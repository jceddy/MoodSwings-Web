using System.Collections;
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
    /// <summary>The welcome tour, on the main menu.</summary>
    public class TourSceneTests
    {
        [SetUp]
        public void SetUp() => MainSceneTests.ShowTour = true;

        [TearDown]
        public void TearDown() => MainSceneTests.ShowTour = false;

        private static IEnumerator ToHome()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        private static TourOverlay Tour => Object.FindAnyObjectByType<AppBootstrap>().Tour;

        private static IEnumerator Next() => PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Tour next"));

        // The button's box in world space.
        private static Rect WorldRect(RectTransform rect)
        {
            var corners = new Vector3[4];
            rect.GetWorldCorners(corners);
            return Rect.MinMaxRect(corners[0].x, corners[0].y, corners[2].x, corners[2].y);
        }

        private static bool Contains(Rect outer, Rect inner) =>
            outer.xMin <= inner.xMin + 0.5f && outer.xMax >= inner.xMax - 0.5f && outer.yMin <= inner.yMin + 0.5f && outer.yMax >= inner.yMax - 0.5f;

        [UnityTest]
        public IEnumerator TheFirstTimeOnTheMainMenu_TheTourStartsBySelf_WithAWelcome()
        {
            yield return ToHome();

            Assert.IsTrue(Tour.IsOpen);
            Assert.IsTrue(AppServices.Tour.IsActive);
            Assert.AreEqual("Welcome to MOOD", Tour.TitleText);
            Assert.AreEqual("Step 1 of 11", Tour.CounterText);
            StringAssert.Contains("skip it at any time", Tour.BodyText);
            Assert.IsFalse(Tour.CanGoBack, "nothing before the first step");
            Assert.AreEqual("Start the tour", Tour.NextLabel);
            Assert.IsNull(Tour.HighlightRect, "the welcome points at nothing");
            Assert.IsNotNull(PhaseFiveSceneTests.ButtonNamed("Tour skip"), "and it can be skipped from the first step");
            ScreenshotHelper.Capture("tour-welcome");
        }

        [UnityTest]
        public IEnumerator EachStepAboutAButton_FramesThatButton_WithTheCardBesideIt()
        {
            yield return ToHome();
            var home = MainSceneTests.Screen<HomeScreen>();
            var canvas = Object.FindAnyObjectByType<Canvas>().rootCanvas.GetComponent<RectTransform>();
            var screen = WorldRect(canvas);

            for (var step = 1; step < TourSteps.All.Count; step++)
            {
                yield return Next();
                var current = TourSteps.All[step];
                Assert.AreEqual(current.Title, Tour.TitleText);
                Assert.AreEqual($"Step {step + 1} of {TourSteps.All.Count}", Tour.CounterText);

                if (current.Target == null)
                {
                    Assert.IsNull(Tour.HighlightRect, current.Id);
                    continue;
                }

                var button = WorldRect(home.TourTarget(current.Target));
                Assert.IsNotNull(Tour.HighlightRect, current.Id);
                Assert.IsTrue(Contains(Tour.HighlightRect.Value, button), current.Id + ": the frame is around the button");

                var card = WorldRect(Tour.Card);
                Assert.IsFalse(card.Overlaps(button), current.Id + ": the card doesn't cover the button it is about");
                Assert.IsTrue(Contains(screen, card), current.Id + ": the card is on the screen");
                if (current.Target == "play" || current.Target == "friends")
                {
                    ScreenshotHelper.Capture("tour-" + current.Id);
                }
            }
        }

        [UnityTest]
        public IEnumerator Back_GoesToThePreviousStep_AndTheLastStepIsDone()
        {
            yield return ToHome();
            yield return Next();
            yield return Next();
            Assert.AreEqual("Puzzles", Tour.TitleText);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Tour back"));
            Assert.AreEqual("Play", Tour.TitleText);
            Assert.IsTrue(Tour.CanGoBack);

            while (!AppServices.Tour.IsLast)
            {
                yield return Next();
            }

            Assert.AreEqual("Done", Tour.NextLabel);
            Assert.IsNull(PhaseFiveSceneTests.Child("Tour skip"), "nothing left to skip on the last step");
            Assert.IsNull(Tour.HighlightRect);
            ScreenshotHelper.Capture("tour-done");

            yield return Next();

            Assert.IsFalse(Tour.IsOpen);
            Assert.IsTrue(AppServices.Device.TourSeen);
        }

        [UnityTest]
        public IEnumerator TheTableStep_ShowsAPictureOfTheTable_ThatFitsTheScreen()
        {
            yield return ToHome();
            while (AppServices.Tour.Current.Id != "table")
            {
                yield return Next();
            }

            Assert.IsTrue(Tour.ShowsDiagram);
            var canvas = Object.FindAnyObjectByType<Canvas>().rootCanvas.GetComponent<RectTransform>();
            Assert.IsTrue(Contains(WorldRect(canvas), WorldRect(Tour.Card)), "the card is on the screen");
            ScreenshotHelper.Capture("tour-table");

            yield return Next();
            Assert.IsFalse(Tour.ShowsDiagram);
        }

        [UnityTest]
        public IEnumerator Skip_EndsTheTourAtOnce_AtAnyStep_AndTheMenuWorksAgain()
        {
            yield return ToHome();
            yield return Next();
            yield return Next();
            yield return Next();

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Tour skip"));

            Assert.IsFalse(Tour.IsOpen);
            Assert.IsFalse(AppServices.Tour.IsActive);
            Assert.IsTrue(AppServices.Device.TourSeen, "skipping counts as having seen it");

            yield return PhaseTwoSceneTests.Click("Puzzles");
            yield return MainSceneTests.WaitFor<PuzzlesScreen>();
        }

        [UnityTest]
        public IEnumerator TheBackKey_SkipsTheTour()
        {
            yield return ToHome();
            yield return Next();

            AndroidBack.SimulatedPresses = 1;
            yield return PhaseTwoSceneTests.Frames(4);

            Assert.IsFalse(Tour.IsOpen);
            Assert.IsTrue(AppServices.Device.TourSeen);
            Assert.IsInstanceOf<HomeScreen>(Object.FindAnyObjectByType<ScreenRouter>().Current, "it was the tour that went, not the screen");
        }

        [UnityTest]
        public IEnumerator OnceSeen_TheTourDoesNotStartBySelf()
        {
            MainSceneTests.ShowTour = false;
            yield return ToHome();
            yield return PhaseTwoSceneTests.Frames(20);

            Assert.IsFalse(Tour.IsOpen);
            Assert.IsFalse(AppServices.Tour.IsActive);
        }

        [UnityTest]
        public IEnumerator SettingsCanRunTheTourAgain_FromTheMainMenu()
        {
            MainSceneTests.ShowTour = false;
            yield return ToHome();
            yield return PhaseTwoSceneTests.Click("Settings");
            yield return MainSceneTests.WaitFor<SettingsScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsFalse(Tour.IsOpen);

            var replay = PhaseFiveSceneTests.ButtonNamed("Replay tour");
            Assert.IsNotNull(replay, "a button in Settings");
            yield return PhaseFiveSceneTests.Tap(replay);
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(Tour.IsOpen, "back on the main menu, with the tour running");
            Assert.AreEqual("Welcome to MOOD", Tour.TitleText);
            Assert.AreEqual("Step 1 of 11", Tour.CounterText);
            Assert.IsNotNull(PhaseFiveSceneTests.ButtonNamed("Tour skip"));
        }

        private sealed class NewerVersion : IHttpTransport
        {
            public int Requests;

            public Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken)
            {
                Requests++;
                return Task.FromResult(new HttpResponse
                {
                    StatusCode = 200,
                    Body = "{\"version\":\"9.9.9\",\"windows\":{\"url\":\"https://example.test/a.exe\"}}",
                });
            }
        }

        [UnityTest]
        public IEnumerator AnUpdateIsOnlyOfferedAfterTheTour()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            var manifest = new NewerVersion();
            AppServices.Updates = new UpdateFlow(manifest, "https://example.test/latest.json", "1.0.0", "windows", AppServices.Device);
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(30);

            Assert.IsTrue(Tour.IsOpen);
            Assert.AreEqual(0, manifest.Requests, "nothing is asked while the tour is up");
            Assert.IsFalse(UpdatePrompt.Current.Overlay.IsOpen);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Tour skip"));
            var waited = 0f;
            while (!UpdatePrompt.Current.Overlay.IsOpen && waited < 4f)
            {
                waited += Time.unscaledDeltaTime;
                yield return null;
            }

            Assert.AreEqual(1, manifest.Requests);
            Assert.IsTrue(UpdatePrompt.Current.Overlay.IsOpen, "the update comes up once the tour is out of the way");
        }
    }
}
