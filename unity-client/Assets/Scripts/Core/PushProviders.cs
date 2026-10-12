using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>
    /// Picks the push token source for the platform being run on: Firebase Cloud Messaging on an Android device (its
    /// project settings come from Assets/Resources/Firebase/google-services.json), none anywhere else, so the app just
    /// doesn't offer push there.
    /// </summary>
    public static class PushProviders
    {
        public static IPushTokenProvider ForThisPlatform()
        {
            if (Application.platform != RuntimePlatform.Android)
            {
                return new NoPushTokenProvider();
            }

            var file = Resources.Load<TextAsset>("Firebase/google-services");
            var config = FirebaseConfig.Parse(file != null ? file.text : null, AndroidPushTokenProvider.PackageName);
            return config != null ? (IPushTokenProvider)new AndroidPushTokenProvider(config) : new NoPushTokenProvider();
        }
    }
}
