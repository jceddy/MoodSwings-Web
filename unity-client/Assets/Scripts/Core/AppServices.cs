using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using MoodSwings.UI;
using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>
    /// The app's shared services, created once before the first scene
    /// loads. A plain static rather than a DI container -- there's exactly
    /// one of each service, and screens reach them through here.
    /// </summary>
    public static class AppServices
    {
        public static ApiClient Api { get; private set; }

        public static AuthFlow Auth { get; private set; }

        public static FriendsFlow Friends { get; private set; }

        public static PreferencesFlow Preferences { get; private set; }

        public static LobbyFlow Lobby { get; private set; }

        public static PuzzleFlow Puzzles { get; private set; }

        public static DecklistFlow Decklists { get; private set; }

        public static StatsFlow Stats { get; private set; }

        public static TournamentFlow Tournaments { get; private set; }

        public static WeeklySealedFlow WeeklySealed { get; private set; }

        public static NotificationsFlow Notifications { get; private set; }

        /// <summary>This device's push token, registered with the server for whoever is signed in.</summary>
        public static PushFlow Push { get; private set; }

        /// <summary>Sound and vibration switches for this device.</summary>
        public static DeviceSettings Device { get; private set; }

        public static UiTheme Theme { get; private set; }

        /// <summary>The art packs the player has added (replacement card art), kept in the app's own storage.</summary>
        public static ArtPackStore ArtPacks { get; private set; }

        /// <summary>Looks for a newer version of the app (null where updates aren't offered).</summary>
        public static UpdateFlow Updates { get; set; }

        /// <summary>Asks the player for a folder or a zip on their device (a native dialog); replaceable for tests.</summary>
        public static IFilePicker FilePicker { get; set; }

        [RuntimeInitializeOnLoadMethod(RuntimeInitializeLoadType.BeforeSceneLoad)]
        private static void Initialize()
        {
            var preferences = new PlayerPrefsKeyValueStore();
            var sessionStore = new SecureSessionStore(preferences, SecretProtectors.ForCurrentPlatform());

            // Production unless this device was switched (the hidden tap switch on the login screen); a
            // development build (and the Editor) starts on the dev server until someone chooses otherwise.
            var chosen = new DeviceSettings(preferences).DevServerChoice;
            var dev = chosen ?? Debug.isDebugBuild;
            var config = new ApiConfig(
                dev ? ApiConfig.DevSiteRoot : ApiConfig.ProdSiteRoot, $"MoodSwings/{Application.version} ({Application.platform})");

            Override(
                new ApiClient(config, new UnityWebRequestTransport(), sessionStore),
                null,
                sessionStore,
                preferences);
        }

        /// <summary>The server the app was switched to (or changed back from) while running.</summary>
        public static event System.Action ServerChanged;

        public static bool IsDevServer => Api != null && Api.Config.IsDevServer;

        /// <summary>
        /// Switches between the dev and production servers: signs out of the current one properly (taking this device's
        /// push token back while the session still works), forgets everything fetched for that user, then points the
        /// client at the other. The caller shows the login screen afterwards. A session never carries over: each server
        /// has its own.
        /// </summary>
        public static async System.Threading.Tasks.Task SetDevServerAsync(bool dev)
        {
            if (Api.Config.IsDevServer == dev)
            {
                return;
            }

            try
            {
                await Push.UnregisterAsync();
            }
            catch (System.Exception e)
            {
                Debug.LogException(e);
            }

            await Auth.LogoutAsync();
            ClearUserData();
            Device.DevServerChoice = dev;
            Api.Config.UseSiteRoot(dev ? ApiConfig.DevSiteRoot : ApiConfig.ProdSiteRoot);
            ServerChanged?.Invoke();
        }

        /// <summary>Forgets everything fetched for the signed-in user (signing out, or switching servers).</summary>
        public static void ClearUserData()
        {
            Friends.Clear();
            Lobby.Clear();
            Puzzles.Clear();
            Decklists.Clear();
            Stats.Clear();
            Tournaments.Clear();
            WeeklySealed.Clear();
            Notifications.Clear();
            Push.Clear();
        }

        /// <summary>
        /// Replaces the services -- used by Initialize() and by tests that
        /// need a scene to run against a fake transport. When auth is null
        /// it's built over the given store and preferences.
        /// </summary>
        public static void Override(
            ApiClient api, AuthFlow auth, SecureSessionStore sessionStore = null, IKeyValueStore preferences = null,
            IPushTokenProvider pushProvider = null, string artPacksFolder = null)
        {
            Api = api;
            Auth = auth ?? new AuthFlow(api, sessionStore, preferences);
            Friends = new FriendsFlow(api);
            Preferences = new PreferencesFlow(api, Auth);
            Lobby = new LobbyFlow(api);
            Puzzles = new PuzzleFlow(api);
            Decklists = new DecklistFlow(api);
            Device = new DeviceSettings(preferences ?? new InMemoryKeyValueStore());
            Stats = new StatsFlow(api, Device);
            Tournaments = new TournamentFlow(api);
            WeeklySealed = new WeeklySealedFlow(api);
            Notifications = new NotificationsFlow(api);
            Push = new PushFlow(api, pushProvider ?? PushProviders.ForThisPlatform(), Device, preferences ?? new InMemoryKeyValueStore());
            if (Theme == null)
            {
                Theme = ScriptableObject.CreateInstance<UiTheme>();
            }

            Theme.Apply(Device.DarkMode);

            ArtPacks = new ArtPackStore(artPacksFolder ?? System.IO.Path.Combine(Application.persistentDataPath, "ArtPacks"));
            CardArtLibrary.Packs = ArtPacks;
            // A pack that has since been deleted is just the default again.
            CardArtLibrary.ActivePackId = ArtPacks.Find(Device.ArtPack)?.Id;
            FilePicker = FilePickers.ForThisPlatform();

            // Only a built app looks (never the Editor or its tests, which set their own when they want to).
            var platform = UpdateFlow.PlatformKey(Application.platform);
            Updates = platform == null
                ? null
                : new UpdateFlow(new UnityWebRequestTransport(15), UpdateConfig.ManifestUrl, Application.version, platform, Device);
        }
    }
}
