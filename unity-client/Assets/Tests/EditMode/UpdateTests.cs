using System.IO;
using System.Text;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Noticing that a newer version of the app has been published.</summary>
    public class UpdateTests
    {
        private const string Url = "https://example.test/latest.json";

        private const string Manifest = @"{
            ""version"": ""1.3.0"",
            ""notes"": [""Auto updates"", ""Art packs""],
            ""windows"": { ""url"": ""https://example.test/MOOD-Setup-1.3.0.exe"", ""sha256"": ""ABCDEF"" },
            ""macos"": { ""url"": ""https://example.test/MOOD-1.3.0-mac.zip"" },
            ""android"": { ""url"": ""https://example.test/MOOD-1.3.0.apk"", ""sha256"": ""123456"" }
        }";

        private FakeHttpTransport _http;
        private DeviceSettings _device;

        [SetUp]
        public void SetUp()
        {
            _http = new FakeHttpTransport();
            _device = new DeviceSettings(new InMemoryKeyValueStore());
        }

        private UpdateFlow Flow(string current = "1.2.0", string platform = "windows") =>
            new UpdateFlow(_http, Url, current, platform, _device);

        // --- versions ------------------------------------------------------------------------------------

        [TestCase("1.2.0", "1.2.0", 0)]
        [TestCase("1.3.0", "1.2.0", 1)]
        [TestCase("1.2.0", "1.10.0", -1)]
        [TestCase("1.2.9", "1.2.10", -1)]
        [TestCase("2.0", "1.99.99", 1)]
        [TestCase("v1.2.1", "1.2.0", 1)]
        [TestCase("1.3.0-beta", "1.3.0", 0)]
        public void VersionsCompareByNumber_NotByText(string a, string b, int expected)
        {
            Assert.AreEqual(expected, AppVersions.Compare(a, b));
        }

        [TestCase("", "1.2.0")]
        [TestCase(null, "1.2.0")]
        [TestCase("latest", "1.2.0")]
        [TestCase("1.2.0.4", "1.2.0")]
        public void ANonsenseVersion_ComparesToNothing(string a, string b)
        {
            Assert.IsNull(AppVersions.Compare(a, b));
        }

        // --- the check -----------------------------------------------------------------------------------

        [Test]
        public void ANewerVersion_IsOffered_WithItsNotesAndThisPlatformsDownload()
        {
            _http.Enqueue(200, Manifest);

            var check = Flow().CheckAsync().GetAwaiter().GetResult();

            Assert.IsNotNull(check.Update);
            Assert.AreEqual("1.3.0", check.Update.Version);
            Assert.AreEqual("https://example.test/MOOD-Setup-1.3.0.exe", check.Update.Url);
            Assert.AreEqual("abcdef", check.Update.Sha256, "hashes are compared in lowercase");
            CollectionAssert.AreEqual(new[] { "Auto updates", "Art packs" }, check.Update.Notes);
            Assert.AreEqual(Url, _http.LastRequest.Url);
        }

        [Test]
        public void EachPlatform_GetsItsOwnDownload()
        {
            _http.Enqueue(200, Manifest);
            Assert.AreEqual("https://example.test/MOOD-1.3.0-mac.zip", Flow(platform: "macos").CheckAsync().GetAwaiter().GetResult().Update.Url);

            _http.Enqueue(200, Manifest);
            Assert.AreEqual("https://example.test/MOOD-1.3.0.apk", Flow(platform: "android").CheckAsync().GetAwaiter().GetResult().Update.Url);
        }

        [Test]
        public void TheSameOrAnOlderVersion_IsNotOffered()
        {
            _http.Enqueue(200, Manifest);
            var same = Flow(current: "1.3.0").CheckAsync().GetAwaiter().GetResult();
            Assert.IsNull(same.Update);
            StringAssert.Contains("latest version (1.3.0)", same.Message);

            _http.Enqueue(200, Manifest);
            Assert.IsNull(Flow(current: "1.4.0").CheckAsync().GetAwaiter().GetResult().Update, "a build newer than the published one");
        }

        [Test]
        public void ANewVersion_NotPublishedForThisPlatform_IsNotOffered()
        {
            _http.Enqueue(200, @"{ ""version"": ""1.3.0"", ""windows"": { ""url"": ""https://example.test/a.exe"" } }");

            Assert.IsNull(Flow(platform: "android").CheckAsync().GetAwaiter().GetResult().Update);
        }

        [Test]
        public void ASkippedVersion_IsNotOfferedAgain_ButANewerOneIs_AndAManualCheckStillSaysSo()
        {
            var flow = Flow();
            flow.Skip("1.3.0");

            _http.Enqueue(200, Manifest);
            var routine = flow.CheckAsync().GetAwaiter().GetResult();
            Assert.IsNull(routine.Update);
            StringAssert.Contains("skip", routine.Message);

            _http.Enqueue(200, Manifest);
            Assert.IsNotNull(flow.CheckAsync(manual: true).GetAwaiter().GetResult().Update);

            _http.Enqueue(200, Manifest.Replace("1.3.0", "1.3.1"));
            Assert.IsNotNull(flow.CheckAsync().GetAwaiter().GetResult().Update, "skipping one version doesn't silence the next");
        }

        [Test]
        public void ACheckThatCantBeMade_IsNotAnUpdate()
        {
            _http.EnqueueNetworkError("no route");
            var offline = Flow().CheckAsync().GetAwaiter().GetResult();
            Assert.IsNull(offline.Update);
            Assert.IsTrue(offline.Failed);

            _http.Enqueue(404, "Not Found");
            Assert.IsTrue(Flow().CheckAsync().GetAwaiter().GetResult().Failed);

            _http.Enqueue(200, "<html>not json</html>");
            Assert.IsTrue(Flow().CheckAsync().GetAwaiter().GetResult().Failed);

            _http.Enqueue(200, @"{ ""version"": ""soon"" }");
            Assert.IsTrue(Flow().CheckAsync().GetAwaiter().GetResult().Failed);
        }

        [Test]
        public void WhereUpdatesArentOffered_NothingIsFetched()
        {
            var flow = new UpdateFlow(_http, Url, "1.2.0", platform: null, device: _device);

            var check = flow.CheckAsync().GetAwaiter().GetResult();

            Assert.IsFalse(flow.IsAvailable);
            Assert.IsNull(check.Update);
            Assert.AreEqual(0, _http.Requests.Count);
        }

        [Test]
        public void ThePlayersChoices_AreRemembered_AndCheckingIsOnByDefault()
        {
            Assert.IsTrue(_device.CheckForUpdates);
            _device.CheckForUpdates = false;
            Assert.IsFalse(_device.CheckForUpdates);

            Assert.IsNull(_device.SkippedUpdateVersion);
            _device.SkippedUpdateVersion = "1.3.0";
            Assert.AreEqual("1.3.0", _device.SkippedUpdateVersion);
            _device.SkippedUpdateVersion = null;
            Assert.IsNull(_device.SkippedUpdateVersion);
        }

        [Test]
        public void EachDesktopAndAndroidPlayer_HasAManifestKey_TheEditorHasNone()
        {
            Assert.AreEqual("windows", UpdateFlow.PlatformKey(UnityEngine.RuntimePlatform.WindowsPlayer));
            Assert.AreEqual("macos", UpdateFlow.PlatformKey(UnityEngine.RuntimePlatform.OSXPlayer));
            Assert.AreEqual("android", UpdateFlow.PlatformKey(UnityEngine.RuntimePlatform.Android));
            Assert.IsNull(UpdateFlow.PlatformKey(UnityEngine.RuntimePlatform.WindowsEditor));
        }

        // --- the downloaded file -------------------------------------------------------------------------

        [Test]
        public void ADownload_IsCheckedAgainstItsPublishedHash()
        {
            var path = Path.Combine(Path.GetTempPath(), "mood-update-test-" + System.Guid.NewGuid().ToString("N") + ".bin");
            File.WriteAllBytes(path, Encoding.ASCII.GetBytes("abc"));
            try
            {
                // The well-known SHA-256 of "abc".
                const string abc = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad";
                Assert.AreEqual(abc, UpdateInstaller.Sha256Hex(path));
                Assert.IsTrue(UpdateInstaller.Matches(path, abc));
                Assert.IsTrue(UpdateInstaller.Matches(path, abc.ToUpperInvariant()));
                Assert.IsFalse(UpdateInstaller.Matches(path, "00" + abc.Substring(2)));
                Assert.IsTrue(UpdateInstaller.Matches(path, null), "the manifest gave no hash to check");
            }
            finally
            {
                File.Delete(path);
            }
        }
    }
}
