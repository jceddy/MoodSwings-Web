namespace MoodSwings.Core
{
    /// <summary>
    /// Picks the push token source for the platform being run on. Android uses Firebase Cloud Messaging once the app is
    /// built with its Firebase setup (see unity-client/README.md, "Push notifications"); every other case has none, and
    /// the app simply doesn't offer push.
    /// </summary>
    public static class PushProviders
    {
        public static IPushTokenProvider ForThisPlatform()
        {
            // The Firebase-backed provider is added here when the Firebase Messaging package and the project's
            // google-services.json are in the build; until then there is nothing to register.
            return new NoPushTokenProvider();
        }
    }
}
