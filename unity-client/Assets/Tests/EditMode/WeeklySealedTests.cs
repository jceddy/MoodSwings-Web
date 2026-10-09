using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>The Weekly Sealed Pool queue and standings, written the way their routes document them.</summary>
    public class WeeklySealedTests
    {
        private const string Standings =
            @"{""status"":""ok"",""standings"":[
              {""user_id"":3,""username"":""Alder"",""wins"":3,""losses"":0,""rank"":1,""percentile"":10},
              {""user_id"":2,""username"":""bshaftoe"",""wins"":2,""losses"":1,""rank"":2,""percentile"":20}]}";

        private FakeHttpTransport _transport;
        private WeeklySealedFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _flow = new WeeklySealedFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        [Test]
        public void TheQueue_ReadsWhetherYouAreWaiting_AndHowManyMatchesAreGoing()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"queued\":true,\"in_progress_count\":1,\"concurrent_match_cap\":2}");

            Assert.IsTrue(_flow.RefreshQueueAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/weekly-sealed-pool/queue", _transport.LastRequest.Url);
            Assert.IsTrue(_flow.Queue.Queued);
            Assert.AreEqual("You're in the queue, waiting for an opponent. 1/2 matches in progress this week.", WeeklySealedDisplay.QueueLine(_flow.Queue));
            Assert.IsFalse(WeeklySealedDisplay.CanJoin(_flow.Queue), "already in it");
        }

        [Test]
        public void JoiningIsBlockedAtTheMatchCap()
        {
            Assert.IsTrue(WeeklySealedDisplay.CanJoin(new WeeklyQueueStatus { Queued = false, InProgressCount = 1, ConcurrentMatchCap = 2 }));
            Assert.IsFalse(WeeklySealedDisplay.CanJoin(new WeeklyQueueStatus { Queued = false, InProgressCount = 2, ConcurrentMatchCap = 2 }));
            Assert.IsFalse(WeeklySealedDisplay.CanJoin(null));
            Assert.AreEqual("0/2 matches in progress this week.", WeeklySealedDisplay.QueueLine(new WeeklyQueueStatus { ConcurrentMatchCap = 2 }));
        }

        [Test]
        public void JoiningWhenSomeoneIsWaiting_PairsAtOnce_WithTheNewGame()
        {
            _transport.Enqueue(200, "{\"status\":\"paired\",\"game_id\":812,\"opponent_username\":\"Alder\"}");

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(812, result.GameId);
            StringAssert.Contains("Alder", result.Message);
            Assert.AreEqual("https://example.test/app/weekly-sealed-pool/queue", _transport.LastRequest.Url);
            Assert.AreEqual("POST", _transport.LastRequest.Method);
        }

        [Test]
        public void JoiningWhenNobodyIsWaiting_QueuesYou_AndRefreshesTheStatus()
        {
            _transport.Enqueue(200, "{\"status\":\"waiting\"}");
            _transport.Enqueue(200, "{\"status\":\"ok\",\"queued\":true,\"in_progress_count\":0,\"concurrent_match_cap\":2}");

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.IsNull(result.GameId);
            Assert.IsTrue(_flow.Queue.Queued);
        }

        [Test]
        public void ARefusedJoin_SaysWhy()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"You already have 2 Weekly Sealed Pool matches in progress.\"}");

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.StartsWith("You already have 2", result.Message);
        }

        [Test]
        public void Leaving_PostsToLeave_ThenRefreshes()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, "{\"status\":\"ok\",\"queued\":false,\"in_progress_count\":0,\"concurrent_match_cap\":2}");

            Assert.IsTrue(_flow.LeaveAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/weekly-sealed-pool/queue/leave", _transport.Requests[0].Url);
            Assert.IsFalse(_flow.Queue.Queued);
        }

        [Test]
        public void TheStandings_ListRankedRows_AndMarkYours()
        {
            _transport.Enqueue(200, Standings);

            Assert.IsTrue(_flow.RefreshStandingsAsync(prior: false).GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/weekly-sealed-pool/standings", _transport.LastRequest.Url);
            Assert.AreEqual(2, _flow.Standings.Count);
            Assert.AreEqual("#1 Alder  -  3-0 (top 10%)", WeeklySealedDisplay.StandingLine(_flow.Standings[0], myUserId: 2));
            Assert.AreEqual("#2 bshaftoe (you)  -  2-1 (top 20%)", WeeklySealedDisplay.StandingLine(_flow.Standings[1], myUserId: 2));
        }

        [Test]
        public void LastWeeksStandings_AreAskedForByName_AndMayNotExist()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"standings\":null}");

            Assert.IsTrue(_flow.RefreshStandingsAsync(prior: true).GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/weekly-sealed-pool/standings?week=prior", _transport.LastRequest.Url);
            Assert.IsNull(_flow.Standings);
            Assert.IsTrue(_flow.ShowingPrior);
            Assert.AreEqual("There was no Weekly Sealed Pool event last week.", WeeklySealedDisplay.EmptyLine(prior: true, eventExisted: false));
            Assert.AreEqual("No standings yet -- be the first to finish a match this week!", WeeklySealedDisplay.EmptyLine(prior: false, eventExisted: true));
        }

        [Test]
        public void LoggingOut_ForgetsTheQueueAndStandings()
        {
            _transport.Enqueue(200, Standings);
            _flow.RefreshStandingsAsync(false).GetAwaiter().GetResult();

            _flow.Clear();

            Assert.IsFalse(_flow.StandingsLoaded);
            Assert.AreEqual(0, _flow.Standings.Count());
            Assert.IsNull(_flow.Queue);
        }
    }
}
