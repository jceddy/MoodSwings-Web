using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class LobbyFlowTests
    {
        private const string NoListings = "{\"status\":\"ok\",\"listings\":[]}";

        private FakeHttpTransport _transport;
        private LobbyFlow _lobby;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _lobby = new LobbyFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        [Test]
        public void TheSynchronousFeatureFlag_IsReadFromTheServer()
        {
            Assert.IsFalse(_lobby.SynchronousModeEnabled, "off until known");

            _transport.Enqueue(200, TestFixtures.Read("synchronous_mode_enabled"));
            Assert.IsTrue(_lobby.RefreshSynchronousModeFlagAsync().GetAwaiter().GetResult().Ok);
            Assert.IsFalse(_lobby.SynchronousModeEnabled, "the real dev server has it switched off");
            StringAssert.EndsWith("/config/synchronous-mode-enabled", _transport.LastRequest.Url);

            _transport.Enqueue(200, "{\"status\":\"ok\",\"enabled\":true}");
            _lobby.RefreshSynchronousModeFlagAsync().GetAwaiter().GetResult();
            Assert.IsTrue(_lobby.SynchronousModeEnabled);
        }

        [Test]
        public void IfTheFlagCantBeRead_SynchronousStaysOff()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"enabled\":true}");
            _lobby.RefreshSynchronousModeFlagAsync().GetAwaiter().GetResult();

            _transport.EnqueueNetworkError("down");
            var result = _lobby.RefreshSynchronousModeFlagAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.IsFalse(_lobby.SynchronousModeEnabled, "when in doubt, don't offer it");
        }

        /// <summary>A listing as the server sends it. A null creator leaves creator_username out, as it does for the listings you posted.</summary>
        private static string Listing(int id, string creator, int target, int joined, string createdAt = "2026-10-01 10:00:00", string deck = "structure", int? createdBy = null) =>
            $"{{\"id\":{id},\"created_by_user_id\":{createdBy ?? id + 100}," +
            (creator == null ? string.Empty : $"\"creator_username\":\"{creator}\",") + "\"create_game_params\":" +
            $"{{\"format\":\"standard\",\"wins_needed\":3,\"deck_type\":\"{deck}\",\"default_selections_mode\":false,\"decklist_text\":null}}," +
            $"\"target_player_count\":{target},\"joined_count\":{joined},\"created_at\":\"{createdAt}\"}}";

        private static string Listings(params string[] listings) => "{\"status\":\"ok\",\"listings\":[" + string.Join(",", listings) + "]}";

        private void EnqueueGamesRefresh() => EnqueueGamesRefresh(TestFixtures.Read("games"), TestFixtures.Read("games_past"));

        private void EnqueueGamesRefresh(string active, string past)
        {
            _transport.Enqueue(200, active);
            _transport.Enqueue(200, past);
        }

        private void EnqueueOpenRefresh(string available = NoListings, string mine = NoListings, string joined = NoListings)
        {
            _transport.Enqueue(200, available);
            _transport.Enqueue(200, mine);
            _transport.Enqueue(200, joined);
        }

        private static GameSetup SetupVs(params int[] opponents) => new GameSetup { OpponentUserIds = opponents.ToList() };

        [Test]
        public void RefreshGames_LoadsTheRealCapturedActiveAndFinishedGames()
        {
            EnqueueGamesRefresh();

            var result = _lobby.RefreshGamesAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(3, _lobby.ActiveGames.Count);
            Assert.AreEqual(69, _lobby.PastGames.Count);
            Assert.AreEqual("https://example.test/app/games", _transport.Requests[0].Url);
            Assert.AreEqual("https://example.test/app/games/past", _transport.Requests[1].Url);
        }

        [Test]
        public void RefreshGames_PutsGamesWaitingOnYouFirst_ThenTheMostRecentlyActive()
        {
            var waiting = "{\"id\":1,\"status\":\"in_progress\",\"is_your_turn\":true,\"last_move_at\":\"2026-10-01 08:00:00\"}";
            var idleNew = "{\"id\":2,\"status\":\"in_progress\",\"last_move_at\":\"2026-10-01 12:00:00\"}";
            var idleOld = "{\"id\":3,\"status\":\"in_progress\",\"last_move_at\":\"2026-09-30 12:00:00\"}";
            EnqueueGamesRefresh("{\"status\":\"ok\",\"games\":[" + idleOld + "," + idleNew + "," + waiting + "]}", "{\"status\":\"ok\"}");

            _lobby.RefreshGamesAsync().GetAwaiter().GetResult();

            CollectionAssert.AreEqual(new[] { 1, 2, 3 }, _lobby.ActiveGames.Select(g => g.Id).ToArray());
            Assert.AreEqual(1, _lobby.GamesNeedingYou);
        }

        [Test]
        public void RefreshGames_ThreeRealGamesAreAllWaitingOnYou()
        {
            EnqueueGamesRefresh();

            _lobby.RefreshGamesAsync().GetAwaiter().GetResult();

            Assert.AreEqual(3, _lobby.GamesNeedingYou);
        }

        [Test]
        public void RefreshGames_ListsFinishedGamesNewestFirst_WithAbandonedOnesLast()
        {
            EnqueueGamesRefresh();

            _lobby.RefreshGamesAsync().GetAwaiter().GetResult();

            Assert.AreEqual("2026-09-27 17:06:21", _lobby.PastGames[0].CompletedAt);
            Assert.IsNull(_lobby.PastGames.Last().CompletedAt, "an abandoned game has no completion time");
            var times = _lobby.PastGames.Where(g => g.CompletedAt != null).Select(g => g.CompletedAt).ToList();
            CollectionAssert.AreEqual(times.OrderByDescending(t => t).ToList(), times);
        }

        [Test]
        public void RefreshGames_WhenOffline_FailsAndKeepsWhatItHad()
        {
            EnqueueGamesRefresh();
            _lobby.RefreshGamesAsync().GetAwaiter().GetResult();
            _transport.EnqueueNetworkError("offline");
            _transport.EnqueueNetworkError("offline");

            var result = _lobby.RefreshGamesAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
            Assert.AreEqual(3, _lobby.ActiveGames.Count);
        }

        [Test]
        public void RefreshBots_LoadsTheRealPracticeBots()
        {
            _transport.Enqueue(200, TestFixtures.Read("games_bots"));

            var result = _lobby.RefreshBotsAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(6, _lobby.Bots.Count);
            Assert.AreEqual("BotAlice", _lobby.Bots[0].Username);
            Assert.AreEqual(9, _lobby.Bots[0].UserId);
            Assert.IsFalse(_lobby.Bots[0].UsesTacticalAi);
            Assert.AreEqual(3, _lobby.Bots.Count(b => b.UsesTacticalAi));
        }

        [Test]
        public void RefreshOpenGames_AsksForAvailableMineAndJoined()
        {
            EnqueueOpenRefresh(
                available: Listings(Listing(1, "Alice", 3, 1, "2026-10-01 09:00:00"), Listing(2, "Bob", 2, 0, "2026-10-01 11:00:00")),
                mine: Listings(Listing(3, "me", 4, 0)),
                joined: Listings(Listing(4, "Cleo", 2, 1)));

            var result = _lobby.RefreshOpenGamesAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/open-games", _transport.Requests[0].Url);
            Assert.AreEqual("https://example.test/app/open-games?mine=1", _transport.Requests[1].Url);
            Assert.AreEqual("https://example.test/app/open-games?joined=1", _transport.Requests[2].Url);
            CollectionAssert.AreEqual(new[] { 2, 1 }, _lobby.AvailableOpenGames.Select(l => l.Id).ToArray(), "newest first");
            Assert.AreEqual(3, _lobby.MyOpenGames.Single().Id);
            Assert.AreEqual("Cleo", _lobby.JoinedOpenGames.Single().CreatorUsername);
            Assert.AreEqual(3, _lobby.AvailableOpenGames[1].TargetPlayerCount);
            Assert.AreEqual("structure", _lobby.AvailableOpenGames[1].Settings.DeckType);
        }

        [Test]
        public void RefreshOpenGames_ListingsYouPosted_ComeBackWithoutACreatorName_ButWithYourId()
        {
            // What the real server sends for ?mine=1: no creator_username key at all.
            EnqueueOpenRefresh(mine: Listings(Listing(3, creator: null, target: 4, joined: 1, createdBy: 2)));

            _lobby.RefreshOpenGamesAsync().GetAwaiter().GetResult();

            var posted = _lobby.MyOpenGames.Single();
            Assert.IsNull(posted.CreatorUsername);
            Assert.AreEqual(2, posted.CreatedByUserId);
            Assert.AreEqual("Your game  -  2 of 4 seated", GameDisplay.ListingTitle(posted, yourUserId: 2));
        }

        [Test]
        public void RefreshOpenGames_IfAnyOfTheThreeFails_ReplacesNothing()
        {
            _transport.Enqueue(200, Listings(Listing(1, "Alice", 3, 1)));
            _transport.Enqueue(500, "{\"status\":\"error\"}");
            _transport.Enqueue(200, NoListings);

            var result = _lobby.RefreshOpenGamesAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual(0, _lobby.AvailableOpenGames.Count);
        }

        [Test]
        public void CreateGame_PostsTheBody_ThenRefreshesAndReturnsTheGameId()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"game_id\":408}");
            EnqueueGamesRefresh();

            var result = _lobby.CreateGameAsync(SetupVs(18, 20)).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(408, result.GameId);
            Assert.AreEqual("Game created.", result.Message);
            Assert.AreEqual("https://example.test/app/games", _transport.Requests[0].Url);
            Assert.AreEqual("POST", _transport.Requests[0].Method);
            var body = JObject.Parse(_transport.Requests[0].Body);
            CollectionAssert.AreEqual(new[] { 18, 20 }, body["opponent_user_ids"].Values<int>().ToArray());
            Assert.AreEqual("structure", (string)body["deck_type"]);
            Assert.AreEqual(3, _transport.Requests.Count, "create, then the games refresh's two GETs");
        }

        [Test]
        public void CreateGame_WithNoOpponents_MakesNoRequest()
        {
            var result = _lobby.CreateGameAsync(SetupVs()).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("Pick at least one opponent.", result.Message);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void CreateGame_ShowsTheServersReason_AndDoesNotRefresh()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"One or more opponents could not be found.\"}");

            var result = _lobby.CreateGameAsync(SetupVs(999)).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("One or more opponents could not be found.", result.Message);
            Assert.IsNull(result.GameId);
            Assert.AreEqual(1, _transport.Requests.Count);
        }

        [Test]
        public void PostOpenGame_PostsTheTargetCount_ThenRefreshesTheLobby()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"listing_id\":7}");
            EnqueueOpenRefresh(mine: Listings(Listing(7, "me", 3, 0)));

            var result = _lobby.PostOpenGameAsync(new GameSetup { OpenLobbyPlayerCount = 3 }).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            StringAssert.Contains("3 players", result.Message);
            Assert.AreEqual("https://example.test/app/open-games", _transport.Requests[0].Url);
            Assert.AreEqual(3, (int)JObject.Parse(_transport.Requests[0].Body)["target_player_count"]);
            Assert.AreEqual(1, _lobby.MyOpenGames.Count);
        }

        [Test]
        public void PostOpenGame_NotDiscoverable_ShowsTheServersReason()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"Turn on \\\"Discoverable for open games\\\" in Settings first.\"}");

            var result = _lobby.PostOpenGameAsync(new GameSetup()).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Discoverable", result.Message);
        }

        [Test]
        public void JoinOpenGame_WhenStillShortOfPlayers_SaysHowManyMoreAreNeeded()
        {
            // The body's "status" is "waiting" here, not "ok" -- the join response overrides it.
            _transport.Enqueue(201, "{\"status\":\"waiting\",\"joined_count\":1,\"target_player_count\":3}");
            EnqueueOpenRefresh(joined: Listings(Listing(1, "Alice", 3, 1)));
            var listing = new OpenGameListing { Id = 1, CreatorUsername = "Alice", TargetPlayerCount = 3 };

            var result = _lobby.JoinOpenGameAsync(listing).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("Joined. Waiting for 1 more player.", result.Message);
            Assert.IsNull(result.GameId);
            Assert.AreEqual("https://example.test/app/open-games/join", _transport.Requests[0].Url);
            Assert.AreEqual("{\"id\":1}", _transport.Requests[0].Body);
            Assert.AreEqual(1, _lobby.JoinedOpenGames.Count);
        }

        [Test]
        public void JoinOpenGame_WaitingForSeveralMore_Pluralizes()
        {
            _transport.Enqueue(201, "{\"status\":\"waiting\",\"joined_count\":1,\"target_player_count\":4}");
            EnqueueOpenRefresh();

            var result = _lobby.JoinOpenGameAsync(new OpenGameListing { Id = 2 }).GetAwaiter().GetResult();

            Assert.AreEqual("Joined. Waiting for 2 more players.", result.Message);
        }

        [Test]
        public void JoinOpenGame_AsTheLastPlayer_StartsTheGame_AndRefreshesYourGames()
        {
            _transport.Enqueue(201, "{\"status\":\"started\",\"game_id\":55}");
            EnqueueOpenRefresh();
            EnqueueGamesRefresh();

            var result = _lobby.JoinOpenGameAsync(new OpenGameListing { Id = 1 }).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(55, result.GameId);
            Assert.AreEqual("The game is starting!", result.Message);
            Assert.AreEqual(3, _lobby.ActiveGames.Count, "your games were refreshed");
        }

        [Test]
        public void JoinOpenGame_ThatIsGone_ShowsTheServersReason()
        {
            _transport.Enqueue(404, "{\"status\":\"error\",\"message\":\"No open listing found with that id.\"}");

            var result = _lobby.JoinOpenGameAsync(new OpenGameListing { Id = 9 }).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("No open listing found with that id.", result.Message);
        }

        [Test]
        public void LeaveAndCancel_PostTheListingId_ThenRefresh()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            EnqueueOpenRefresh();
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            EnqueueOpenRefresh();
            var listing = new OpenGameListing { Id = 5 };

            var left = _lobby.LeaveOpenGameAsync(listing).GetAwaiter().GetResult();
            var cancelled = _lobby.CancelOpenGameAsync(listing).GetAwaiter().GetResult();

            Assert.IsTrue(left.Ok);
            Assert.IsTrue(cancelled.Ok);
            Assert.AreEqual("https://example.test/app/open-games/leave", _transport.Requests[0].Url);
            Assert.AreEqual("{\"id\":5}", _transport.Requests[0].Body);
            Assert.AreEqual("https://example.test/app/open-games/cancel", _transport.Requests[4].Url);
        }

        [Test]
        public void Cancel_OfSomeoneElsesListing_ShowsTheServersReason()
        {
            _transport.Enqueue(403, "{\"status\":\"error\",\"message\":\"You can only cancel your own listing.\"}");

            var result = _lobby.CancelOpenGameAsync(new OpenGameListing { Id = 5 }).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("You can only cancel your own listing.", result.Message);
        }

        [Test]
        public void RefreshWatchableGames_LoadsFriendsGames_MostRecentlyActiveFirst()
        {
            // The same summary shape as your own games.
            var older = "{\"id\":1,\"status\":\"in_progress\",\"last_move_at\":\"2026-10-01 08:00:00\"}";
            var newer = "{\"id\":2,\"status\":\"in_progress\",\"last_move_at\":\"2026-10-01 11:00:00\"}";
            _transport.Enqueue(200, "{\"status\":\"ok\",\"games\":[" + older + "," + newer + "]}");

            var result = _lobby.RefreshWatchableGamesAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/games/spectatable", _transport.LastRequest.Url);
            CollectionAssert.AreEqual(new[] { 2, 1 }, _lobby.WatchableGames.Select(g => g.Id).ToArray());
        }

        [Test]
        public void RefreshWatchableGames_WhenOffline_KeepsWhatItHad()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"games\":[{\"id\":1,\"status\":\"in_progress\"}]}");
            _lobby.RefreshWatchableGamesAsync().GetAwaiter().GetResult();
            _transport.EnqueueNetworkError("offline");

            var result = _lobby.RefreshWatchableGamesAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual(1, _lobby.WatchableGames.Count);
        }

        [Test]
        public void ResolveSpectateCode_PostsTheTrimmedCode_AndReturnsTheGameId()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"game_id\":406}");

            var result = _lobby.ResolveSpectateCodeAsync("  AB12 ").GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(406, result.GameId);
            Assert.AreEqual("https://example.test/app/games/spectate/resolve", _transport.LastRequest.Url);
            Assert.AreEqual("{\"code\":\"AB12\"}", _transport.LastRequest.Body);
        }

        [TestCase("")]
        [TestCase("   ")]
        [TestCase(null)]
        public void ResolveSpectateCode_WithNothingTyped_MakesNoRequest(string code)
        {
            var result = _lobby.ResolveSpectateCodeAsync(code).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("Enter a spectate code.", result.Message);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void ResolveSpectateCode_UnknownCode_ShowsTheServersReason()
        {
            _transport.Enqueue(404, "{\"status\":\"error\",\"message\":\"No game found for that spectate code.\"}");

            var result = _lobby.ResolveSpectateCodeAsync("NOPE").GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("No game found for that spectate code.", result.Message);
            Assert.IsNull(result.GameId);
        }

        [Test]
        public void Clear_EmptiesEverything_AndRaisesChanged()
        {
            EnqueueGamesRefresh();
            _lobby.RefreshGamesAsync().GetAwaiter().GetResult();
            var raised = 0;
            _lobby.Changed += () => raised++;

            _lobby.Clear();

            Assert.AreEqual(1, raised);
            Assert.AreEqual(0, _lobby.ActiveGames.Count);
            Assert.AreEqual(0, _lobby.PastGames.Count);
            Assert.AreEqual(0, _lobby.GamesNeedingYou);
        }
    }
}
