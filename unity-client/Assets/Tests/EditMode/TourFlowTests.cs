using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>The welcome tour: what it says, going through it, skipping out of it, and being remembered.</summary>
    public class TourFlowTests
    {
        private InMemoryKeyValueStore _store;
        private DeviceSettings _device;
        private TourFlow _tour;

        [SetUp]
        public void SetUp()
        {
            _store = new InMemoryKeyValueStore();
            _device = new DeviceSettings(_store);
            _tour = new TourFlow(_device);
        }

        // --- what it says ----------------------------------------------------------------------------

        [Test]
        public void EveryStep_HasATitleAndSomethingToSay()
        {
            Assert.GreaterOrEqual(TourSteps.All.Count, 8);
            foreach (var step in TourSteps.All)
            {
                Assert.IsNotEmpty(step.Title, step.Id);
                Assert.Greater(step.Body.Length, 20, step.Id);
            }

            CollectionAssert.AllItemsAreUnique(TourSteps.All.Select(s => s.Id).ToList());
        }

        [Test]
        public void TheStepsPointAtMainMenuButtons_OrSettingsSwitches_AndTheTourOpensAndClosesInTheMiddle()
        {
            var buttons = new[] { "play", "puzzles", "decklists", "friends", "achievements", "tournaments", "stats", "settings" };
            var switches = new[] { "custom-formats", "discoverable" };

            foreach (var step in TourSteps.All.Where(s => s.Target != null))
            {
                CollectionAssert.Contains(step.Screen == TourScreen.Home ? buttons : switches, step.Target, step.Id);
            }

            Assert.IsNull(TourSteps.All.First().Target, "it opens by welcoming you");
            Assert.IsNull(TourSteps.All.Last().Target, "and ends by saying how to see it again");
            StringAssert.Contains("Settings", TourSteps.All.Last().Body);
            Assert.AreEqual(1, TourSteps.All.Count(s => s.TableDiagram), "one step draws the table");
        }

        [Test]
        public void TwoSettingsThatShapeYourGames_AreCalledOutByName_OnTheSettingsScreen()
        {
            var formats = PreferenceCatalog.GameDefaults.Single(p => p.JsonKey == "allow_custom_content");
            var discoverable = PreferenceCatalog.Privacy.Single(p => p.JsonKey == "matchmaking_discoverable");

            var formatsStep = TourSteps.All.Single(s => s.Id == "custom-formats");
            var discoverableStep = TourSteps.All.Single(s => s.Id == "discoverable");

            Assert.AreEqual(formats.Label, formatsStep.Title, "named as Settings names it");
            Assert.AreEqual(discoverable.Label, discoverableStep.Title, "named as Settings names it");
            Assert.AreEqual(TourScreen.Settings, formatsStep.Screen);
            Assert.AreEqual(TourScreen.Settings, discoverableStep.Screen);
            StringAssert.Contains("Chaos Draft", formatsStep.Body);
            StringAssert.Contains("open lobby", discoverableStep.Body);

            // They come right after the Settings step on the main menu, which says they are next.
            var order = TourSteps.All.Select(s => s.Id).ToList();
            Assert.AreEqual(order.IndexOf("settings") + 1, order.IndexOf("custom-formats"));
            Assert.AreEqual(order.IndexOf("custom-formats") + 1, order.IndexOf("discoverable"));
            Assert.AreEqual(TourScreen.Home, TourSteps.All.Single(s => s.Id == "table").Screen, "and the tour comes back to the main menu");
            Assert.AreEqual(TourScreen.Home, TourSteps.All.Last().Screen);
        }

        [Test]
        public void TheTourCoversTheMainMenu_AndThePlay()
        {
            var covered = TourSteps.All.Where(s => s.Target != null).Select(s => s.Target).ToList();

            CollectionAssert.IsSupersetOf(covered, new[] { "play", "puzzles", "decklists", "friends", "achievements", "stats", "settings" });
        }

        // --- going through it --------------------------------------------------------------------------

        [Test]
        public void ItStartsAtTheFirstStep_AndNothingHappensBefore()
        {
            Assert.IsFalse(_tour.IsActive);
            Assert.IsNull(_tour.Current);

            _tour.Start();

            Assert.IsTrue(_tour.IsActive);
            Assert.AreEqual(0, _tour.Index);
            Assert.IsTrue(_tour.IsFirst);
            Assert.AreEqual("welcome", _tour.Current.Id);
        }

        [Test]
        public void NextAndBack_MoveOneStepAtATime_AndBackStopsAtTheFirst()
        {
            _tour.Start();
            _tour.Back();
            Assert.AreEqual(0, _tour.Index, "nothing before the first step");

            _tour.Next();
            _tour.Next();
            Assert.AreEqual(2, _tour.Index);
            _tour.Back();
            Assert.AreEqual(1, _tour.Index);
        }

        [Test]
        public void NextOnTheLastStep_FinishesIt_AndItCountsAsSeen()
        {
            var ended = new System.Collections.Generic.List<bool>();
            _tour.Ended += completed => ended.Add(completed);
            _tour.Start();
            Assert.IsFalse(_tour.Seen);

            for (var i = 0; i < _tour.Count - 1; i++)
            {
                _tour.Next();
            }

            Assert.IsTrue(_tour.IsLast);
            Assert.IsTrue(_tour.IsActive);

            _tour.Next();

            Assert.IsFalse(_tour.IsActive);
            Assert.IsTrue(_tour.Seen);
            CollectionAssert.AreEqual(new[] { true }, ended);
        }

        [Test]
        public void SkippingAtAnyStep_EndsIt_AndAlsoCountsAsSeen()
        {
            for (var step = 0; step < TourSteps.All.Count; step++)
            {
                var device = new DeviceSettings(new InMemoryKeyValueStore());
                var tour = new TourFlow(device);
                var ended = new System.Collections.Generic.List<bool>();
                tour.Ended += completed => ended.Add(completed);
                tour.Start();
                for (var i = 0; i < step; i++)
                {
                    tour.Next();
                }

                tour.Skip();

                Assert.IsFalse(tour.IsActive, "skipped at step " + step);
                Assert.IsTrue(device.TourSeen, "skipped at step " + step);
                CollectionAssert.AreEqual(new[] { false }, ended, "skipped, not finished");
            }
        }

        [Test]
        public void SkippingOrAdvancing_WhenItIsNotRunning_DoesNothing()
        {
            var changes = 0;
            var ends = 0;
            _tour.Changed += () => changes++;
            _tour.Ended += _ => ends++;

            _tour.Skip();
            _tour.Next();
            _tour.Back();

            Assert.AreEqual(0, changes);
            Assert.AreEqual(0, ends);
            Assert.IsFalse(_device.TourSeen, "a tour that never ran hasn't been seen");
        }

        [Test]
        public void EveryMoveIsAnnounced_SoTheScreenCanFollow()
        {
            var changes = 0;
            _tour.Changed += () => changes++;

            _tour.Start();
            _tour.Next();
            _tour.Back();
            _tour.Skip();

            Assert.AreEqual(4, changes, "start, next, back and the end");
        }

        // --- being remembered, and run again -------------------------------------------------------------

        [Test]
        public void TheTour_IsNotSeenOnAFreshDevice_AndIsRememberedOnceItHasRun()
        {
            Assert.IsFalse(new DeviceSettings(_store).TourSeen);

            _tour.Start();
            _tour.Skip();

            Assert.IsTrue(new DeviceSettings(_store).TourSeen, "remembered by a fresh reader of the same store");
        }

        [Test]
        public void ItCanBeRunAgainAfterBeingSeen_FromTheFirstStep()
        {
            _tour.Start();
            _tour.Next();
            _tour.Skip();
            Assert.IsTrue(_tour.Seen);

            _tour.Start();

            Assert.IsTrue(_tour.IsActive);
            Assert.AreEqual(0, _tour.Index);
        }

        [Test]
        public void AskingForTheTourAgain_IsTakenOnce()
        {
            Assert.IsFalse(_tour.TakeReplayRequest());

            _tour.RequestReplay();

            Assert.IsTrue(_tour.TakeReplayRequest());
            Assert.IsFalse(_tour.TakeReplayRequest(), "one request, one tour");
        }
    }
}
