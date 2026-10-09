using System.Collections;
using System.Linq;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>A desktop window in full screen has no close button, so the menus offer Quit.</summary>
    public class QuitTests
    {
        private System.Action _original;

        [SetUp]
        public void SetUp() => _original = AppExit.Quit;

        [TearDown]
        public void TearDown() => AppExit.Quit = _original;

        [UnityTest]
        public IEnumerator Home_OffersQuit_ThatLeavesTheApp()
        {
            var quits = 0;
            AppExit.Quit = () => quits++;
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(3);

            yield return PhaseTwoSceneTests.Click("Quit");

            Assert.AreEqual(1, quits);
            ScreenshotHelper.Capture("home-with-quit");
        }

        [UnityTest]
        public IEnumerator TheLoginScreen_OffersQuitToo()
        {
            var quits = 0;
            AppExit.Quit = () => quits++;
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };
            yield return MainSceneTests.Launch(server.Handle);
            yield return MainSceneTests.WaitFor<LoginScreen>();
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsTrue(Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "Quit"));
            ScreenshotHelper.Capture("login-with-quit");
            yield return PhaseTwoSceneTests.Click("Quit");

            Assert.AreEqual(1, quits);
        }

        [Test]
        public void DesktopsAndAndroidOfferQuit_ButNotIos()
        {
            Assert.IsTrue(AppExit.IsAvailableOn(RuntimePlatform.WindowsPlayer));
            Assert.IsTrue(AppExit.IsAvailableOn(RuntimePlatform.OSXPlayer));
            Assert.IsTrue(AppExit.IsAvailableOn(RuntimePlatform.LinuxPlayer));
            Assert.IsTrue(AppExit.IsAvailableOn(RuntimePlatform.Android));
            Assert.IsFalse(AppExit.IsAvailableOn(RuntimePlatform.IPhonePlayer), "Apple asks apps not to close themselves");
        }
    }
}
