using System;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// Where this device's push token comes from: Firebase Cloud Messaging on Android. The platform glue implements
    /// this; everything else (registering with the server, sign-out, routing a tapped notification) is in
    /// <see cref="PushFlow"/> and tested without it.
    /// </summary>
    public interface IPushTokenProvider
    {
        /// <summary>False where push can't work: another platform, or an app built without its Firebase setup.</summary>
        bool IsAvailable { get; }

        /// <summary>"android" or "ios", as the server names them.</summary>
        string Platform { get; }

        /// <summary>The device's token, asking for notification permission first if that's needed; null if it was refused or couldn't be had.</summary>
        Task<string> GetTokenAsync();

        /// <summary>The token changed (Firebase rotates them now and then).</summary>
        event Action<string> TokenRefreshed;

        /// <summary>The link carried by the notification that opened the app, once; null if it wasn't opened by one.</summary>
        string TakeLaunchUrl();
    }

    /// <summary>Push on a platform that has none (the Windows player, the Editor, a build without Firebase).</summary>
    public sealed class NoPushTokenProvider : IPushTokenProvider
    {
        public bool IsAvailable => false;

        public string Platform => string.Empty;

        public event Action<string> TokenRefreshed
        {
            add { }
            remove { }
        }

        public Task<string> GetTokenAsync() => Task.FromResult<string>(null);

        public string TakeLaunchUrl() => null;
    }

    /// <summary>
    /// Registers this install's push token with the server for whoever is signed in, and takes it back on sign-out so
    /// the next person on the device isn't sent the last one's notifications. A device setting turns it off here
    /// without touching the account's own notification switches. UI-free.
    /// </summary>
    public sealed class PushFlow
    {
        private const string TokenKey = "push.token";

        private readonly ApiClient _api;
        private readonly IPushTokenProvider _provider;
        private readonly DeviceSettings _device;
        private readonly IKeyValueStore _store;
        private string _registeredToken;

        public PushFlow(ApiClient api, IPushTokenProvider provider, DeviceSettings device, IKeyValueStore store)
        {
            _api = api;
            _provider = provider;
            _device = device;
            _store = store;
            _provider.TokenRefreshed += token => _ = OnTokenRefreshedAsync(token);
        }

        /// <summary>Whether this device can get push at all (so Settings shows its switch only then).</summary>
        public bool IsAvailable => _provider.IsAvailable;

        public bool Enabled => IsAvailable && _device.PushOn;

        /// <summary>This device's push token as last registered (null before one was), e.g. to paste into Firebase's "send test message".</summary>
        public string CurrentToken => _registeredToken ?? _store.GetString(TokenKey);

        /// <summary>Whether the server has this device's token as of this sign-in.</summary>
        public bool IsRegistered => _registeredToken != null;

        /// <summary>Signed in (or opened): hands the server this device's token, if push is available and on.</summary>
        public async Task<LobbyResult> RegisterAsync(CancellationToken cancellationToken = default)
        {
            if (!Enabled)
            {
                return new LobbyResult { Ok = true };
            }

            var token = await _provider.GetTokenAsync();
            if (string.IsNullOrEmpty(token))
            {
                return new LobbyResult { Message = "This device isn't allowed to show notifications." };
            }

            return await SendAsync(token, cancellationToken);
        }

        /// <summary>Signing out (or switching push off): the server stops sending to this device. Never blocks a sign-out on a failure.</summary>
        public async Task<LobbyResult> UnregisterAsync(CancellationToken cancellationToken = default)
        {
            var token = _registeredToken ?? _store.GetString(TokenKey);
            _registeredToken = null;
            if (string.IsNullOrEmpty(token))
            {
                return new LobbyResult { Ok = true };
            }

            var result = await _api.RemoveDeviceTokenAsync(token, cancellationToken);
            if (result.Ok)
            {
                _store.Delete(TokenKey);
            }

            return result.Ok ? new LobbyResult { Ok = true } : new LobbyResult { Message = result.UserMessage("Couldn't turn push off.") };
        }

        /// <summary>Turns push on or off for this device, telling the server (a signed-in call).</summary>
        public async Task<LobbyResult> SetEnabledAsync(bool on, CancellationToken cancellationToken = default)
        {
            _device.PushOn = on;
            return on ? await RegisterAsync(cancellationToken) : await UnregisterAsync(cancellationToken);
        }

        private async Task<LobbyResult> SendAsync(string token, CancellationToken cancellationToken)
        {
            if (token == _registeredToken)
            {
                return new LobbyResult { Ok = true };
            }

            var result = await _api.RegisterDeviceTokenAsync(token, _provider.Platform, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't turn push on.") };
            }

            _registeredToken = token;
            _store.SetString(TokenKey, token);
            return new LobbyResult { Ok = true };
        }

        private async Task OnTokenRefreshedAsync(string token)
        {
            // Only while a registration is current (someone is signed in with push on); a refresh while signed out is picked up at the next sign-in.
            if (_registeredToken != null && Enabled && !string.IsNullOrEmpty(token))
            {
                await SendAsync(token, CancellationToken.None);
            }
        }

        /// <summary>The place a tapped notification should open, or null.</summary>
        public PushRoute TakeLaunchRoute() => PushRoute.Parse(_provider.TakeLaunchUrl());

        /// <summary>Forgets who was registered (the token itself is removed by <see cref="UnregisterAsync"/>).</summary>
        public void Clear() => _registeredToken = null;
    }

    /// <summary>Where a notification's link points, in app terms.</summary>
    public sealed class PushRoute
    {
        public enum Kind
        {
            Game,
            Friends,
            Achievements,
        }

        public Kind Target { get; private set; }

        public int GameId { get; private set; }

        /// <summary>
        /// Reads the links the server puts in notifications: "/game/?id=7" (a game), "/game/?open_friends=1" (the friends
        /// list), "/achievements/". Anything else (or nothing) is null: the app just opens normally.
        /// </summary>
        public static PushRoute Parse(string url)
        {
            if (string.IsNullOrEmpty(url))
            {
                return null;
            }

            var query = string.Empty;
            var path = url;
            var mark = url.IndexOf('?');
            if (mark >= 0)
            {
                path = url.Substring(0, mark);
                query = url.Substring(mark + 1);
            }

            path = path.TrimEnd('/');
            if (path == "/achievements")
            {
                return new PushRoute { Target = Kind.Achievements };
            }

            if (path != "/game")
            {
                return null;
            }

            foreach (var pair in query.Split('&'))
            {
                var parts = pair.Split(new[] { '=' }, 2);
                if (parts.Length != 2)
                {
                    continue;
                }

                if (parts[0] == "id" && int.TryParse(parts[1], out var id) && id > 0)
                {
                    return new PushRoute { Target = Kind.Game, GameId = id };
                }

                if (parts[0] == "open_friends" && parts[1] == "1")
                {
                    return new PushRoute { Target = Kind.Friends };
                }
            }

            return null;
        }
    }
}
