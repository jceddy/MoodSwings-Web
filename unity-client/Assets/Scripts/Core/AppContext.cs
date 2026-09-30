using MoodSwings.Networking;
using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>
    /// The app's shared services, created once before the first scene
    /// loads. A plain static rather than a DI container -- there's exactly
    /// one of each service, and screens reach them through here.
    /// </summary>
    public static class AppContext
    {
        public static ApiClient Api { get; private set; }

        [RuntimeInitializeOnLoadMethod(RuntimeInitializeLoadType.BeforeSceneLoad)]
        private static void Initialize()
        {
            // Dev server only for now; add a prod config (and a way to pick
            // one per build) once the production domain is decided.
            Api = new ApiClient(ApiConfig.Dev, new UnityWebRequestTransport(), new InMemorySessionStore());
        }
    }
}
