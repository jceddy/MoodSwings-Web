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

        public static UiTheme Theme { get; private set; }

        [RuntimeInitializeOnLoadMethod(RuntimeInitializeLoadType.BeforeSceneLoad)]
        private static void Initialize()
        {
            var preferences = new PlayerPrefsKeyValueStore();
            var sessionStore = new SecureSessionStore(preferences, SecretProtectors.ForCurrentPlatform());

            // Dev server only for now; add a prod config (and a way to pick
            // one per build) once the production domain is decided.
            var config = new ApiConfig(
                ApiConfig.DevSiteRoot, $"MoodSwings/{Application.version} ({Application.platform})");

            Override(
                new ApiClient(config, new UnityWebRequestTransport(), sessionStore),
                null,
                sessionStore,
                preferences);
        }

        /// <summary>
        /// Replaces the services -- used by Initialize() and by tests that
        /// need a scene to run against a fake transport. When auth is null
        /// it's built over the given store and preferences.
        /// </summary>
        public static void Override(
            ApiClient api, AuthFlow auth, SecureSessionStore sessionStore = null, IKeyValueStore preferences = null)
        {
            Api = api;
            Auth = auth ?? new AuthFlow(api, sessionStore, preferences);
            Friends = new FriendsFlow(api);
            Preferences = new PreferencesFlow(api, Auth);
            if (Theme == null)
            {
                Theme = ScriptableObject.CreateInstance<UiTheme>();
            }
        }
    }
}
