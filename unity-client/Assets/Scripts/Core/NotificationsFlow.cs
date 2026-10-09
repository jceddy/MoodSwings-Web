using System;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>One notification switch: which field, and how it reads.</summary>
    public sealed class NotificationSwitch
    {
        public string Label { get; set; }

        public string Description { get; set; }

        public Func<NotificationPreferences, bool> Get { get; set; }

        public Action<NotificationPreferences, bool> Set { get; set; }
    }

    /// <summary>
    /// What you are told about, and whether Discord is linked. Each change is saved at once (the whole set goes
    /// with it); if the save fails the old values stay. UI-free.
    /// </summary>
    public sealed class NotificationsFlow
    {
        public static readonly NotificationSwitch[] Switches =
        {
            new NotificationSwitch { Label = "It's my turn", Get = p => p.YourTurn, Set = (p, v) => p.YourTurn = v },
            new NotificationSwitch { Label = "I receive a friend request", Get = p => p.FriendRequest, Set = (p, v) => p.FriendRequest = v },
            new NotificationSwitch { Label = "One of my games finishes", Get = p => p.GameFinished, Set = (p, v) => p.GameFinished = v },
            new NotificationSwitch { Label = "I receive an in-game chat message", Get = p => p.ChatMessage, Set = (p, v) => p.ChatMessage = v },
            new NotificationSwitch
            {
                Label = "A timeout is less than 15 minutes away",
                Description = "A turn's or a whole game's time limit.",
                Get = p => p.TimeoutWarning,
                Set = (p, v) => p.TimeoutWarning = v,
            },
            new NotificationSwitch { Label = "I unlock an achievement", Get = p => p.AchievementUnlocked, Set = (p, v) => p.AchievementUnlocked = v },
            new NotificationSwitch
            {
                Label = "Send every notification immediately",
                Description = "Otherwise at most one is sent every five minutes.",
                Get = p => p.DisableCooldown,
                Set = (p, v) => p.DisableCooldown = v,
            },
        };

        private readonly ApiClient _api;

        public NotificationsFlow(ApiClient api)
        {
            _api = api;
        }

        public NotificationPreferences Preferences { get; private set; }

        public DiscordStatusResponse Discord { get; private set; }

        public async Task<LobbyResult> LoadAsync(CancellationToken cancellationToken = default)
        {
            var preferences = _api.GetNotificationPreferencesAsync(cancellationToken);
            var discord = _api.GetDiscordStatusAsync(cancellationToken);
            var preferencesResult = await preferences;
            var discordResult = await discord;
            if (discordResult.Ok)
            {
                Discord = discordResult.Value;
            }

            if (!preferencesResult.Ok)
            {
                return new LobbyResult { Message = preferencesResult.UserMessage("Couldn't load the notification settings.") };
            }

            Preferences = preferencesResult.Value.Preferences ?? new NotificationPreferences();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Turns one switch, saving the whole set; the stored values only change if the save works.</summary>
        public async Task<LobbyResult> SetAsync(NotificationSwitch toggle, bool value, CancellationToken cancellationToken = default)
        {
            var changed = (Preferences ?? new NotificationPreferences()).Copy();
            toggle.Set(changed, value);
            var result = await _api.SaveNotificationPreferencesAsync(changed, cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't save that setting.") };
            }

            Preferences = result.Value.Preferences ?? changed;
            return new LobbyResult { Ok = true };
        }

        public bool IsOn(NotificationSwitch toggle) => toggle.Get(Preferences ?? new NotificationPreferences());

        /// <summary>"Discord: linked as Jed" or how to link it; null before it's been fetched.</summary>
        public string DiscordLine() =>
            Discord == null ? null
                : Discord.Linked ? "Discord: linked" + (string.IsNullOrEmpty(Discord.DiscordUsername) ? string.Empty : " as " + Discord.DiscordUsername)
                : "Discord: not linked";

        public void Clear()
        {
            Preferences = null;
            Discord = null;
        }
    }
}
