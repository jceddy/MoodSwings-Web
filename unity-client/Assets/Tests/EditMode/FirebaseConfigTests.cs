using System.IO;
using MoodSwings.Core;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>Reading the Firebase project's google-services.json that ships with the app.</summary>
    public class FirebaseConfigTests
    {
        private static string Shipped() =>
            File.ReadAllText(Path.Combine(Application.dataPath, "Resources", "Firebase", "google-services.json"));

        [Test]
        public void TheShippedFile_IsForTheAppsPackage_AndHasEverythingFirebaseNeeds()
        {
            var config = FirebaseConfig.Parse(Shipped(), AndroidPushTokenProvider.PackageName);

            Assert.IsNotNull(config);
            Assert.AreEqual("com.jceddy.moodswings", config.PackageName);
            Assert.AreEqual("mood-bee14", config.ProjectId);
            Assert.AreEqual("30733730143", config.SenderId);
            StringAssert.StartsWith("1:30733730143:android:", config.ApplicationId);
            Assert.IsFalse(string.IsNullOrEmpty(config.ApiKey));
        }

        [Test]
        public void AFileForAnotherPackage_IsNotUsed()
        {
            Assert.IsNull(FirebaseConfig.Parse(Shipped(), "com.someone.else"));
        }

        [TestCase(null)]
        [TestCase("")]
        [TestCase("not json")]
        [TestCase("{}")]
        [TestCase("{\"client\":[{\"client_info\":{\"android_client_info\":{\"package_name\":\"com.jceddy.moodswings\"}}}]}")]
        public void AMissingOrIncompleteFile_GivesNoConfig_SoPushIsSimplyUnavailable(string json)
        {
            Assert.IsNull(FirebaseConfig.Parse(json, AndroidPushTokenProvider.PackageName));
        }

        [Test]
        public void OffAndroid_NoProviderIsOffered()
        {
            Assert.IsFalse(PushProviders.ForThisPlatform().IsAvailable);
        }

        [Test]
        public void TheProvider_NeedsAConfig_ToBeAvailable()
        {
            Assert.IsFalse(new AndroidPushTokenProvider(null).IsAvailable);
        }
    }
}
