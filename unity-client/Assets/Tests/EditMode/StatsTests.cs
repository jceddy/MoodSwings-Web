using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Lifetime stats and achievements, written the way GET /user/stats and GET /user/achievements document them.</summary>
    public class StatsTests
    {
        private const string StatsJson =
            @"{""status"":""ok"",""username"":""jed"",""stats"":{""game_wins"":12,""game_losses"":8,""game_win_percentage"":60,
                ""match_wins"":0,""match_losses"":0,""match_win_percentage"":null},
              ""prior_weekly_sealed_pool_events"":[{""period_start"":""2026-09-28"",""wins"":3,""losses"":1,""percentile"":15}]}";

        private const string AchievementsJson =
            @"{""status"":""ok"",""achievements"":{
                ""A"":[
                  {""slug"":""first-win"",""title"":""First Win"",""description"":""Win a game."",""tier"":""Bronze"",""target"":null,""progress"":1,""unlocked_at"":""2026-09-01 10:00:00"",""hidden"":false},
                  {""slug"":""ten-wins"",""title"":""Ten Wins"",""description"":""Win ten games."",""tier"":""Silver"",""target"":10,""progress"":4,""unlocked_at"":null,""hidden"":false}],
                ""J"":[
                  {""slug"":""puzzler"",""title"":""Puzzler"",""description"":""Solve a puzzle."",""tier"":""Gold"",""target"":null,""progress"":0,""unlocked_at"":""2026-09-05 08:30:00"",""hidden"":false}],
                ""Z"":[
                  {""slug"":""secret"",""title"":""???"",""description"":""A hidden achievement."",""tier"":""Diamond"",""target"":null,""progress"":0,""unlocked_at"":null,""hidden"":true}]}}";

        private FakeHttpTransport _transport;
        private InMemoryKeyValueStore _store;
        private DeviceSettings _device;
        private StatsFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _store = new InMemoryKeyValueStore();
            _device = new DeviceSettings(_store);
            _flow = new StatsFlow(new ApiClient(new ApiConfig("https://example.test"), _transport), _device);
        }

        private void LoadAchievements()
        {
            _transport.Enqueue(200, AchievementsJson);
            Assert.IsTrue(_flow.RefreshAchievementsAsync().GetAwaiter().GetResult().Ok);
        }

        [Test]
        public void TheStats_ParseWithANullPercentageForNothingPlayed()
        {
            _transport.Enqueue(200, StatsJson);

            Assert.IsTrue(_flow.RefreshStatsAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/user/stats", _transport.LastRequest.Url);
            Assert.AreEqual(12, _flow.Stats.GameWins);
            Assert.AreEqual(60, _flow.Stats.GameWinPercentage);
            Assert.IsNull(_flow.Stats.MatchWinPercentage);
            Assert.AreEqual(1, _flow.PriorWeeklySealedEvents.Count);
            Assert.AreEqual(15, _flow.PriorWeeklySealedEvents[0].Percentile);
        }

        [Test]
        public void ARecord_ReadsAsTheWebPageWritesIt()
        {
            Assert.AreEqual("12-8 (60%)", StatsDisplay.Record(12, 8, 60));
            Assert.AreEqual("0-0", StatsDisplay.Record(0, 0, null), "no percentage until there is something to measure");
            Assert.AreEqual("—", StatsDisplay.Percentage(null));
            Assert.AreEqual("2026-09-28   3-1   Top 15%", StatsDisplay.Week(new WeeklySealedEvent { PeriodStart = "2026-09-28", Wins = 3, Losses = 1, Percentile = 15 }));
        }

        [Test]
        public void AFailedStatsFetch_SaysSo_AndKeepsWhatWasThere()
        {
            _transport.Enqueue(200, StatsJson);
            _flow.RefreshStatsAsync().GetAwaiter().GetResult();

            _transport.EnqueueNetworkError("down");
            var failed = _flow.RefreshStatsAsync().GetAwaiter().GetResult();

            Assert.IsFalse(failed.Ok);
            StringAssert.Contains("Can't reach the server", failed.Message);
            Assert.AreEqual(12, _flow.Stats.GameWins);
        }

        [Test]
        public void TheAchievements_ParseByCategory_WithAnEmptyCatalogComingAsAnArray()
        {
            LoadAchievements();
            Assert.AreEqual("https://example.test/app/user/achievements", _transport.LastRequest.Url);
            Assert.AreEqual(2, _flow.Achievements["A"].Count);
            Assert.IsNull(_flow.Achievements["A"][0].Target);
            Assert.AreEqual(10, _flow.Achievements["A"][1].Target);
            Assert.IsTrue(_flow.Achievements["A"][0].Unlocked);
            Assert.IsFalse(_flow.Achievements["A"][1].Unlocked);

            _transport.Enqueue(200, "{\"status\":\"ok\",\"achievements\":[]}");
            Assert.IsTrue(_flow.RefreshAchievementsAsync().GetAwaiter().GetResult().Ok);
            Assert.AreEqual(0, _flow.Achievements.Count);
        }

        [Test]
        public void Categories_AreNamedAndOrderedAsTheWebPageDoes_AndUnknownOnesComeLast()
        {
            LoadAchievements();

            CollectionAssert.AreEqual(new[] { "A", "J", "Z" }, AchievementsDisplay.OrderedLetters(_flow.Achievements));
            Assert.AreEqual("Volume & Milestones", AchievementsDisplay.CategoryName("A"));
            Assert.AreEqual("Puzzles", AchievementsDisplay.CategoryName("J"));
            Assert.AreEqual("Z", AchievementsDisplay.CategoryName("Z"));
        }

        [Test]
        public void TheTally_CountsUnlocked_AndHideLockedKeepsOnlyThose()
        {
            LoadAchievements();

            Assert.AreEqual("2 of 4 unlocked", AchievementsDisplay.Summary(_flow.Achievements));
            Assert.AreEqual(2, AchievementsDisplay.Visible(_flow.Achievements["A"], hideLocked: false).Count);
            CollectionAssert.AreEqual(new[] { "first-win" }, AchievementsDisplay.Visible(_flow.Achievements["A"], hideLocked: true).Select(a => a.Slug).ToArray());
        }

        [Test]
        public void Progress_ShowsForACountingAchievementUntilItIsDone()
        {
            LoadAchievements();
            var ten = _flow.Achievements["A"][1];

            Assert.AreEqual("4 / 10", AchievementsDisplay.ProgressText(ten));
            Assert.AreEqual(0.4f, AchievementsDisplay.ProgressFraction(ten), 0.001f);
            Assert.IsNull(AchievementsDisplay.ProgressText(_flow.Achievements["A"][0]), "no target: all or nothing");

            ten.UnlockedAt = "2026-09-09 00:00:00";
            ten.Progress = 12;
            Assert.IsNull(AchievementsDisplay.ProgressText(ten), "done");
            Assert.AreEqual(1f, AchievementsDisplay.ProgressFraction(ten), "and never past the end of the bar");
        }

        [Test]
        public void TheFirstLook_TreatsWhatIsAlreadyUnlockedAsSeen_ThenNewUnlocksAreFlagged()
        {
            LoadAchievements();
            Assert.AreEqual(0, _flow.Unseen().Count, "the first fetch on a device baselines");
            Assert.AreEqual("2026-09-05 08:30:00", _device.AchievementsSeenAt);

            var later = _flow.Achievements["A"][1];
            later.UnlockedAt = "2026-09-20 12:00:00";
            Assert.AreEqual("ten-wins", _flow.Unseen().Single().Slug);

            _flow.MarkSeen();
            Assert.AreEqual(0, _flow.Unseen().Count);
            Assert.AreEqual("2026-09-20 12:00:00", _device.AchievementsSeenAt);
        }

        [Test]
        public void ANewAccountWithNothingUnlocked_BaselinesToo()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"achievements\":{\"A\":[{\"slug\":\"x\",\"title\":\"X\",\"description\":\"d\",\"tier\":\"Bronze\",\"target\":null,\"progress\":0,\"unlocked_at\":null,\"hidden\":false}]}}");
            _flow.RefreshAchievementsAsync().GetAwaiter().GetResult();

            Assert.AreEqual(string.Empty, _device.AchievementsSeenAt);

            _flow.Achievements["A"][0].UnlockedAt = "2026-10-01 00:00:00";
            Assert.AreEqual(1, _flow.Unseen().Count);
        }

        [Test]
        public void LoggingOut_ForgetsEverything_IncludingWhatWasSeen()
        {
            LoadAchievements();
            _transport.Enqueue(200, StatsJson);
            _flow.RefreshStatsAsync().GetAwaiter().GetResult();

            _flow.Clear();

            Assert.IsNull(_flow.Stats);
            Assert.IsFalse(_flow.AchievementsLoaded);
            Assert.AreEqual(0, _flow.Achievements.Count);
            Assert.IsNull(_device.AchievementsSeenAt, "the next account to sign in gets its own first look");
        }

        [Test]
        public void HideLocked_IsRememberedOnTheDevice_AndStartsOff()
        {
            Assert.IsFalse(_device.HideLockedAchievements);
            _device.HideLockedAchievements = true;
            Assert.IsTrue(new DeviceSettings(_store).HideLockedAchievements);
        }
    }
}
