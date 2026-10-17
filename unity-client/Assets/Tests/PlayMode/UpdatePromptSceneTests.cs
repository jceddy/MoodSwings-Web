using System;
using System.Collections;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>The "a new version is available" box, on the main menu and from Settings.</summary>
    public class UpdatePromptSceneTests
    {
        private const string Manifest = "{\"version\":\"1.3.0\",\"notes\":[\"Updates itself\",\"Art packs\"],"
            + "\"windows\":{\"url\":\"https://example.test/MOOD-Setup-1.3.0.exe\"},\"macos\":{\"url\":\"https://example.test/MOOD-1.3.0-mac.zip\"}}";

        private sealed class ManifestServer : IHttpTransport
        {
            public string Body = Manifest;
            public int Requests;

            public Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken)
            {
                Requests++;
                return Task.FromResult(new HttpResponse { StatusCode = 200, Body = Body });
            }
        }

        private ManifestServer _manifest;
        private List<string> _opened;
        private Action _quit;

        [SetUp]
        public void SetUp()
        {
            _manifest = new ManifestServer();
            _opened = new List<string>();
            _quit = AppExit.Quit;
            UpdatePrompt.OpenUrl = url => _opened.Add(url);
            UpdatePrompt.InstallsInApp = () => false;
        }

        [TearDown]
        public void TearDown()
        {
            AppExit.Quit = _quit;
            UpdatePrompt.OpenUrl = url => Application.OpenURL(url);
            UpdatePrompt.InstallsInApp = () => UpdateInstaller.InstallsInApp;
            UpdatePrompt.Install = UpdateInstaller.DownloadAndRunAsync;
        }

        private static string[] Texts() =>
            UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        // Signed in, 1.2.0 installed, the stand-in manifest in place before the main menu is reached.
        private IEnumerator ToHome(bool checkForUpdates = true)
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            AppServices.Updates = new UpdateFlow(_manifest, "https://example.test/latest.json", "1.2.0", "windows", AppServices.Device);
            AppServices.Device.CheckForUpdates = checkForUpdates;
            yield return MainSceneTests.WaitFor<HomeScreen>();
        }

        private static IEnumerator Until(Func<bool> condition, float seconds = 4f)
        {
            var waited = 0f;
            while (!condition() && waited < seconds)
            {
                waited += Time.unscaledDeltaTime;
                yield return null;
            }
        }

        private static UpdateOverlay Overlay => UpdatePrompt.Current.Overlay;

        [UnityTest]
        public IEnumerator ANewerVersion_IsOffered_WhenTheMainMenuIsReached()
        {
            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            Assert.IsTrue(Overlay.IsOpen);
            StringAssert.Contains("1.3.0", Overlay.MessageText);
            StringAssert.Contains("1.2.0", Overlay.MessageText);
            StringAssert.Contains("Updates itself", Overlay.NotesText);
            StringAssert.Contains("Art packs", Overlay.NotesText);
            CollectionAssert.Contains(Texts(), "Skip this version");
            ScreenshotHelper.Capture("update-offered");
        }

        [UnityTest]
        public IEnumerator Later_ClosesIt_AndRemembersNothing()
        {
            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            yield return PhaseTwoSceneTests.Click("Later");
            yield return PhaseTwoSceneTests.Frames(4);

            Assert.IsFalse(Overlay.IsOpen);
            Assert.IsNull(AppServices.Device.SkippedUpdateVersion);
            Assert.AreEqual(0, _opened.Count);
        }

        [UnityTest]
        public IEnumerator Skip_ClosesIt_AndThatVersionIsNotOfferedAgain()
        {
            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            yield return PhaseTwoSceneTests.Click("Skip this version");
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.IsFalse(Overlay.IsOpen);
            Assert.AreEqual("1.3.0", AppServices.Device.SkippedUpdateVersion);

            var message = UpdatePrompt.Current.CheckAsync(manual: false);
            yield return MainSceneTests.Await(message);
            Assert.IsFalse(Overlay.IsOpen, "a routine check leaves a skipped version alone");
        }

        [UnityTest]
        public IEnumerator Back_IsLater()
        {
            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            Assert.IsTrue(UpdatePrompt.Current.Dismiss());
            yield return PhaseTwoSceneTests.Frames(4);

            Assert.IsFalse(Overlay.IsOpen);
            Assert.IsNull(AppServices.Device.SkippedUpdateVersion);
        }

        [UnityTest]
        public IEnumerator WhereTheAppCantInstallItself_UpdateOpensTheDownload()
        {
            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            yield return PhaseTwoSceneTests.Click("Download");
            yield return PhaseTwoSceneTests.Frames(4);

            CollectionAssert.AreEqual(new[] { "https://example.test/MOOD-Setup-1.3.0.exe" }, _opened);
            Assert.IsFalse(Overlay.IsOpen);
        }

        [UnityTest]
        public IEnumerator OnWindows_UpdateDownloadsAndRunsTheInstaller_ThenQuits()
        {
            UpdatePrompt.InstallsInApp = () => true;
            var installed = new List<string>();
            var quit = 0;
            AppExit.Quit = () => quit++;
            UpdatePrompt.Install = (update, progress) =>
            {
                installed.Add(update.Url);
                progress(0.5f);
                return Task.CompletedTask;
            };

            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            yield return PhaseTwoSceneTests.Click("Update now");
            yield return PhaseTwoSceneTests.Frames(6);

            CollectionAssert.AreEqual(new[] { "https://example.test/MOOD-Setup-1.3.0.exe" }, installed);
            Assert.AreEqual(1, quit);
            Assert.AreEqual(0, _opened.Count);
        }

        [UnityTest]
        public IEnumerator AFailedInstall_SaysWhy_AndCanBeTriedAgain()
        {
            UpdatePrompt.InstallsInApp = () => true;
            var attempts = 0;
            var quit = 0;
            AppExit.Quit = () => quit++;
            UpdatePrompt.Install = (update, progress) =>
            {
                attempts++;
                return attempts == 1 ? Task.FromException(new System.IO.IOException("The download failed (timeout).")) : Task.CompletedTask;
            };

            yield return ToHome();
            yield return Until(() => Overlay.IsOpen);

            yield return PhaseTwoSceneTests.Click("Update now");
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.IsTrue(Overlay.IsOpen);
            Assert.IsTrue(Overlay.ShowsButtons, "the choices come back");
            StringAssert.Contains("The download failed (timeout).", Overlay.StatusText);
            Assert.AreEqual(0, quit);

            yield return PhaseTwoSceneTests.Click("Update now");
            yield return PhaseTwoSceneTests.Frames(6);
            Assert.AreEqual(2, attempts);
            Assert.AreEqual(1, quit);
        }

        [UnityTest]
        public IEnumerator WithCheckingSwitchedOff_NothingIsFetched()
        {
            yield return ToHome(checkForUpdates: false);
            yield return PhaseTwoSceneTests.Frames(20);

            Assert.AreEqual(0, _manifest.Requests);
            Assert.IsFalse(Overlay.IsOpen);
        }

        [UnityTest]
        public IEnumerator TheNewestVersion_ShowsNothing()
        {
            _manifest.Body = Manifest.Replace("1.3.0", "1.2.0");
            yield return ToHome();
            yield return PhaseTwoSceneTests.Frames(20);

            Assert.AreEqual(1, _manifest.Requests, "it did look");
            Assert.IsFalse(Overlay.IsOpen);
        }

        [UnityTest]
        public IEnumerator Settings_CanSwitchCheckingOff_AndCheckNow()
        {
            _manifest.Body = Manifest.Replace("1.3.0", "1.2.0");
            yield return ToHome(checkForUpdates: false);
            yield return PhaseTwoSceneTests.Click("Settings");
            yield return MainSceneTests.WaitFor<SettingsScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            var toggle = UnityEngine.Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude)
                .First(t => t.GetComponentInChildren<Text>().text == "Check for updates");
            Assert.IsFalse(toggle.isOn);
            toggle.isOn = true;
            Assert.IsTrue(AppServices.Device.CheckForUpdates);

            yield return PhaseTwoSceneTests.Click("Check for updates now");
            yield return Until(() => Texts().Any(t => t.StartsWith("You have the latest version")));
            CollectionAssert.Contains(Texts(), "You have the latest version (1.2.0).");

            // A newer one found by hand is offered even though it was skipped.
            _manifest.Body = Manifest;
            AppServices.Device.SkippedUpdateVersion = "1.3.0";
            yield return PhaseTwoSceneTests.Click("Check for updates now");
            yield return Until(() => Overlay.IsOpen);
            Assert.IsTrue(Overlay.IsOpen);
        }
    }
}
