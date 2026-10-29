using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>League play: the event and queue, the leaderboard and collection, and the deck built inside a paired game.</summary>
    public class LeagueTests
    {
        private const string Status =
            @"{""status"":""ok"",""event"":{""id"":4,""name"":""Spring Cup"",""set_code"":""MSW"",""started_at"":""2026-10-01 10:00:00""},
              ""joined"":true,""queued"":false,""in_progress_count"":1,""concurrent_match_cap"":2,""wins"":3,""losses"":1,
              ""matches"":[
                {""id"":9,""game_id"":812,""opponent_username"":""Alder"",""status"":""in_progress"",""won"":null},
                {""id"":8,""game_id"":790,""opponent_username"":""Birch"",""status"":""completed"",""won"":true},
                {""id"":7,""game_id"":null,""opponent_username"":""Cedar"",""status"":""completed"",""won"":false}],
              ""collection"":{""unique_cards"":31,""total_cards"":52},
              ""deck_rules"":{""min_cards"":12,""max_mythics"":2,""max_rares"":6}}";

        private FakeHttpTransport _transport;
        private LeagueFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _flow = new LeagueFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        // --- the event and the queue -----------------------------------------------------------

        [Test]
        public void TheStatus_ReadsTheEvent_YourRecord_YourMatches_AndTheRules()
        {
            _transport.Enqueue(200, Status);

            Assert.IsTrue(_flow.RefreshStatusAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/league", _transport.LastRequest.Url);
            var status = _flow.Status;
            Assert.AreEqual("Spring Cup (MSW)", LeagueDisplay.EventName(status.Event));
            Assert.AreEqual("Record 3-1. 1/2 matches in progress. Collection: 52 cards (31 different).", LeagueDisplay.Summary(status));
            StringAssert.Contains("at least 12 cards from your collection", LeagueDisplay.Rules(status));
            StringAssert.Contains("at most 2 mythics and 6 rares", LeagueDisplay.Rules(status));
            Assert.AreEqual(3, status.Matches.Count);
            Assert.IsNull(status.Matches[0].Won);
            Assert.AreEqual(812, status.Matches[0].GameId);
            Assert.IsNull(status.Matches[2].GameId);
        }

        [Test]
        public void WithNoEventRunning_TheStatusHasNoEvent_AndNothingCanBeJoined()
        {
            _transport.Enqueue(200, @"{""status"":""ok"",""event"":null,""joined"":false,""queued"":false,""in_progress_count"":0,""concurrent_match_cap"":2,""wins"":0,""losses"":0,""matches"":[],
                ""collection"":{""unique_cards"":0,""total_cards"":0},""deck_rules"":{""min_cards"":12,""max_mythics"":2,""max_rares"":6}}");

            _flow.RefreshStatusAsync().GetAwaiter().GetResult();

            Assert.IsNull(_flow.Status.Event);
            Assert.IsFalse(LeagueDisplay.CanJoin(_flow.Status));
            Assert.AreEqual(string.Empty, LeagueDisplay.EventName(null));
        }

        [Test]
        public void AnEventWithoutASet_IsJustItsName()
        {
            Assert.AreEqual("Open Cup", LeagueDisplay.EventName(new LeagueEvent { Name = "Open Cup" }));
        }

        [Test]
        public void TheQueueLine_DependsOnWhetherYouAreWaiting_AndWhetherYouHaveJoinedBefore()
        {
            var first = new LeagueStatus { Event = new LeagueEvent(), Joined = false };
            StringAssert.Contains("granted a random Structure deck", LeagueDisplay.QueueLine(first));
            Assert.AreEqual("Join League", LeagueDisplay.JoinLabel(first));

            var again = new LeagueStatus { Event = new LeagueEvent(), Joined = true };
            Assert.AreEqual("You choose your deck from your collection once a match starts.", LeagueDisplay.QueueLine(again));
            Assert.AreEqual("Queue for a match", LeagueDisplay.JoinLabel(again));

            var waiting = new LeagueStatus { Event = new LeagueEvent(), Joined = true, Queued = true };
            StringAssert.StartsWith("You're in the queue, waiting for an opponent.", LeagueDisplay.QueueLine(waiting));
        }

        [Test]
        public void JoiningIsBlockedWhileQueued_AndAtTheMatchCap()
        {
            var open = new LeagueStatus { Event = new LeagueEvent(), InProgressCount = 1, ConcurrentMatchCap = 2 };
            Assert.IsTrue(LeagueDisplay.CanJoin(open));

            open.InProgressCount = 2;
            Assert.IsFalse(LeagueDisplay.CanJoin(open), "two matches are going");

            open.InProgressCount = 0;
            open.Queued = true;
            Assert.IsFalse(LeagueDisplay.CanJoin(open), "already waiting");
            Assert.IsFalse(LeagueDisplay.CanJoin(null));
        }

        [Test]
        public void JoiningWhenSomeoneIsWaiting_PairsAtOnce_WithTheNewGame()
        {
            _transport.Enqueue(200, @"{""status"":""paired"",""game_id"":812,""opponent_username"":""Alder""}");

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(812, result.GameId);
            StringAssert.Contains("Alder", result.Message);
            Assert.AreEqual("https://example.test/app/league/join", _transport.LastRequest.Url);
            Assert.AreEqual("POST", _transport.LastRequest.Method);
        }

        [Test]
        public void JoiningWhenNobodyIsWaiting_QueuesYou_AndRefreshesTheStatus()
        {
            _transport.Enqueue(200, @"{""status"":""waiting""}");
            _transport.Enqueue(200, Status.Replace(@"""queued"":false", @"""queued"":true"));

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.IsNull(result.GameId);
            Assert.IsTrue(_flow.Status.Queued);
        }

        [Test]
        public void ARefusedJoin_SaysWhy()
        {
            _transport.Enqueue(400, @"{""status"":""error"",""message"":""League play pairs you with strangers -- turn on Settings > \""Discoverable for open games\"" first.""}");

            var result = _flow.JoinAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Discoverable for open games", result.Message);
        }

        [Test]
        public void LeavingTheQueue_PostsToLeave_AndRefreshes()
        {
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, Status);

            Assert.IsTrue(_flow.LeaveAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/league", _transport.LastRequest.Url);
            Assert.IsFalse(_flow.Status.Queued);
        }

        [Test]
        public void AMatchReadsAsInProgress_Won_OrLost()
        {
            var matches = JsonConvert.DeserializeObject<LeagueStatus>(Status).Matches;

            Assert.AreEqual("vs Alder -- in progress", LeagueDisplay.MatchLine(matches[0]));
            Assert.AreEqual("Open game", LeagueDisplay.MatchButton(matches[0]));
            Assert.AreEqual("vs Birch -- won", LeagueDisplay.MatchLine(matches[1]));
            Assert.AreEqual("vs Cedar -- lost", LeagueDisplay.MatchLine(matches[2]));
            Assert.AreEqual("View", LeagueDisplay.MatchButton(matches[1]));
        }

        // --- the leaderboard and the collection ------------------------------------------------

        [Test]
        public void TheLeaderboard_ReadsTheStandings_ForTheCurrentOrThePreviousEvent()
        {
            _transport.Enqueue(200, @"{""status"":""ok"",""event"":{""id"":4,""name"":""Spring Cup"",""set_code"":null,""status"":""active""},
                ""standings"":[{""user_id"":3,""username"":""Alder"",""wins"":3,""losses"":0,""rank"":1,""percentile"":10}]}");

            Assert.IsTrue(_flow.RefreshLeaderboardAsync(false).GetAwaiter().GetResult().Ok);
            Assert.AreEqual("https://example.test/app/league/leaderboard", _transport.LastRequest.Url);
            Assert.AreEqual("Spring Cup", LeagueDisplay.EventName(_flow.LeaderboardEvent));
            Assert.AreEqual("#1 Alder  -  3-0 (top 10%)", WeeklySealedDisplay.StandingLine(_flow.Standings[0], 99));
            Assert.IsFalse(_flow.ShowingPrior);

            _transport.Enqueue(200, @"{""status"":""ok"",""event"":null,""standings"":[]}");
            Assert.IsTrue(_flow.RefreshLeaderboardAsync(true).GetAwaiter().GetResult().Ok);
            Assert.AreEqual("https://example.test/app/league/leaderboard?event=prior", _transport.LastRequest.Url);
            Assert.IsNull(_flow.LeaderboardEvent);
            Assert.IsEmpty(_flow.Standings);
            Assert.IsTrue(_flow.ShowingPrior);
        }

        [Test]
        public void AnEmptyLeaderboard_SaysWhetherThereIsNoEventOrNoMatchesYet()
        {
            Assert.AreEqual("There is no League event running right now.", LeagueDisplay.EmptyLeaderboard(false, false));
            Assert.AreEqual("There is no previous League event yet.", LeagueDisplay.EmptyLeaderboard(true, false));
            Assert.AreEqual("No standings yet -- be the first to finish a match!", LeagueDisplay.EmptyLeaderboard(false, true));
            Assert.AreEqual("Nobody finished a match in that event.", LeagueDisplay.EmptyLeaderboard(true, true));
        }

        [Test]
        public void TheCollection_IsTheServersCards_WithHowManyOfEach()
        {
            _transport.Enqueue(200, @"{""status"":""ok"",""cards"":[
                {""card_id"":1,""name"":""Altruism"",""color"":""white"",""rarity"":""common"",""copies"":3},
                {""card_id"":2,""name"":""Faith"",""color"":""white"",""rarity"":""rare"",""copies"":1}]}");

            Assert.IsTrue(_flow.RefreshCollectionAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/league/collection", _transport.LastRequest.Url);
            Assert.AreEqual(3, _flow.Collection[0].Copies);
            Assert.AreEqual("4 cards, 2 different.", LeagueDisplay.CollectionSummary(_flow.Collection.ToList()));
            Assert.AreEqual(string.Empty, LeagueDisplay.CollectionSummary(null));
        }

        [Test]
        public void SigningOut_ForgetsEverythingFetched()
        {
            _transport.Enqueue(200, Status);
            _flow.RefreshStatusAsync().GetAwaiter().GetResult();

            _flow.Clear();

            Assert.IsNull(_flow.Status);
            Assert.IsNull(_flow.Collection);
            Assert.IsFalse(_flow.LeaderboardLoaded);
        }

        // --- the game --------------------------------------------------------------------------

        [Test]
        public void ALeagueGame_SaysSo_SoThereIsNoRematch()
        {
            var game = JsonConvert.DeserializeObject<BoardGameInfo>(@"{""id"":1,""is_league_match"":true}");
            Assert.IsTrue(game.IsLeagueMatch);
            Assert.IsFalse(JsonConvert.DeserializeObject<BoardGameInfo>(@"{""id"":1}").IsLeagueMatch);

            var state = BoardFixtures.Load(405); // you are bshaftoe, user 2, who made it
            state.Game.Status = "completed";
            state.Game.CreatedByUserId = 2;
            state.Game.WinnerUsernames = new List<string> { "bshaftoe" };
            state.Game.IsTournamentMatch = false;
            foreach (var player in state.Players)
            {
                player.Resigned = false;
            }

            Assert.IsTrue(BoardDisplay.CanRematch(state));
            state.Game.IsLeagueMatch = true;
            Assert.IsFalse(BoardDisplay.CanRematch(state));
        }

        // --- the deck builder ------------------------------------------------------------------

        private static string Card(int id, string name, string color, string rarity, int copies) =>
            $@"{{""card_id"":{id},""catalog_card_id"":{id},""name"":""{name}"",""color"":""{color}"",""rarity"":""{rarity}"",""copies"":{copies}}}";

        private static LeagueDeckBuilder Builder(bool fixedPool = false, string defaults = "[1,1,2,3]", int min = 3)
        {
            var pool = string.Join(",",
                Card(1, "Calm", "white", "common", 3),
                Card(2, "Awe", "blue", "rare", 2),
                Card(3, "Joy", "red", "mythic", 1),
                Card(4, "Fury", "red", "rare", 1),
                Card(5, "Hope", "green", "common", 2));
            return JsonConvert.DeserializeObject<LeagueDeckBuilder>(
                $@"{{""pool"":[{pool}],""default_deck_card_ids"":{defaults},""min_cards"":{min},""rarity_limits"":{{""mythic"":1,""rare"":2}},""sideboard_max"":{(fixedPool ? 0 : 3)},""fixed_pool"":{(fixedPool ? "true" : "false")}}}");
        }

        private static BoardCard Pick(LeagueDeckSelection selection, string name) => selection.Pool.First(c => c.Name == name);

        [Test]
        public void TheBuilder_ReadsThePoolTheCapsAndTheSideboardSize()
        {
            var builder = Builder();

            Assert.AreEqual(5, builder.Pool.Count);
            Assert.AreEqual(3, builder.Pool[0].Copies);
            Assert.AreEqual(1, builder.RarityLimits["mythic"]);
            Assert.AreEqual(3, builder.SideboardMax);
            Assert.IsFalse(builder.FixedPool);

            var empty = JsonConvert.DeserializeObject<LeagueDeckBuilder>(@"{""pool"":[],""default_deck_card_ids"":[],""min_cards"":12,""rarity_limits"":[],""sideboard_max"":0,""fixed_pool"":true}");
            Assert.IsEmpty(empty.RarityLimits, "PHP sends an empty map as a list");
        }

        [Test]
        public void TheDeck_StartsFromTheSuggestedOne_AndNeverExceedsWhatYouOwn()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[1,1,2,3,4,5,1,1,1]"));

            Assert.AreEqual(3, selection.InDeck(Pick(selection, "Calm")), "three owned, so the extra copies are dropped");
            Assert.AreEqual(1, selection.InDeck(Pick(selection, "Joy")));
            Assert.AreEqual(7, selection.DeckCount);
        }

        [Test]
        public void ThePoolIsInColorRarityNameOrder()
        {
            var selection = new LeagueDeckSelection(Builder());

            CollectionAssert.AreEqual(new[] { "Calm", "Awe", "Fury", "Joy", "Hope" }, selection.Pool.Select(c => c.Name));
        }

        [Test]
        public void ACardCannotBeAddedPastTheCopiesYouOwn_OrPastTheRarityCaps()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[]"));
            var calm = Pick(selection, "Calm");
            Assert.IsTrue(selection.AddToDeck(calm));
            Assert.IsTrue(selection.AddToDeck(calm));
            Assert.IsTrue(selection.AddToDeck(calm));
            Assert.IsFalse(selection.AddToDeck(calm), "only three owned");
            Assert.AreEqual(3, selection.InDeck(calm));

            Assert.IsTrue(selection.AddToDeck(Pick(selection, "Joy")));
            Assert.IsFalse(selection.CanAddToDeck(Pick(selection, "Joy")), "the one copy is used, and so is the one mythic");

            Assert.IsTrue(selection.AddToDeck(Pick(selection, "Awe")));
            Assert.IsTrue(selection.AddToDeck(Pick(selection, "Awe")));
            Assert.IsFalse(selection.CanAddToDeck(Pick(selection, "Fury")), "two rares is the limit");
            Assert.AreEqual(2, selection.RarityCount("rare"));
        }

        [Test]
        public void TheSideboard_HoldsUpToItsLimit_AndCardsInItAreNotAvailableForTheDeck()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[5]"));
            var hope = Pick(selection, "Hope");
            Assert.IsTrue(selection.AddToSideboard(hope), "the second copy");
            Assert.IsFalse(selection.CanAddToSideboard(hope), "one in the deck and one in the sideboard use both copies");
            Assert.IsFalse(selection.CanAddToDeck(hope));

            var calm = Pick(selection, "Calm");
            Assert.IsTrue(selection.AddToSideboard(calm));
            Assert.IsTrue(selection.AddToSideboard(calm));
            Assert.AreEqual(3, selection.SideboardCount);
            Assert.IsFalse(selection.AddToSideboard(calm), "three sideboard cards is the most");

            Assert.IsTrue(selection.RemoveFromSideboard(hope));
            Assert.IsTrue(selection.CanAddToDeck(hope), "the copy is free again");
            Assert.IsFalse(selection.RemoveFromSideboard(hope), "none left to take out");
        }

        [Test]
        public void OnAFixedPool_WhateverTheDeckLeavesOverIsTheSideboard()
        {
            var selection = new LeagueDeckSelection(Builder(fixedPool: true, defaults: "[1,2]"));
            var calm = Pick(selection, "Calm");

            Assert.IsTrue(selection.FixedPool);
            Assert.AreEqual(2, selection.InSideboard(calm), "three owned, one in the deck");
            Assert.IsTrue(selection.AddToDeck(calm));
            Assert.AreEqual(1, selection.InSideboard(calm));
            Assert.IsFalse(selection.CanAddToSideboard(calm), "there is no sideboard to edit");
            Assert.IsFalse(selection.AddToSideboard(calm));
            Assert.IsFalse(selection.CanRemoveFromSideboard(calm));
            Assert.AreEqual(selection.Pool.Sum(c => c.Copies) - selection.DeckCount, selection.SideboardCount);
        }

        [Test]
        public void TheDeckNeedsItsMinimum_BeforeItCanBeSent()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[1,1]", min: 3));
            Assert.AreEqual("Choose at least 3 cards (2 so far).", selection.Problem());

            selection.AddToDeck(Pick(selection, "Hope"));
            Assert.IsNull(selection.Problem());
        }

        [Test]
        public void ResetGoesBackToTheSuggestedDeck_AndClearEmptiesIt()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[1,1,2,3]"));
            selection.RemoveFromDeck(Pick(selection, "Calm"));
            selection.AddToSideboard(Pick(selection, "Hope"));

            selection.Reset();
            Assert.AreEqual(4, selection.DeckCount);
            Assert.AreEqual(0, selection.SideboardCount);

            selection.Clear();
            Assert.AreEqual(0, selection.DeckCount);
        }

        [Test]
        public void TheDeckIsSentAsDecklistText_WithASideboardOnGameOne()
        {
            var selection = new LeagueDeckSelection(Builder(defaults: "[1,1,2,3]"));
            selection.AddToSideboard(Pick(selection, "Hope"));
            selection.AddToSideboard(Pick(selection, "Hope"));

            Assert.AreEqual("About\nName League deck\n\n2 Calm\n1 Awe\n1 Joy\n\nSideboard\n2 Hope", selection.DecklistText());
        }

        [Test]
        public void OnAFixedPool_NoSideboardSectionIsSent()
        {
            var selection = new LeagueDeckSelection(Builder(fixedPool: true, defaults: "[1,2,3]"));

            Assert.AreEqual("About\nName League deck\n\n1 Calm\n1 Awe\n1 Joy", selection.DecklistText());
        }

        [Test]
        public void TheInstructions_SayHowFarTheDeckAndTheSideboardAre()
        {
            var game1 = new LeagueDeckSelection(Builder(defaults: "[1,2,3]"));
            StringAssert.Contains("3 card(s) in your deck (at least 3 needed; 1/1 mythic, 1/2 rare)", game1.Instructions());
            StringAssert.Contains("Sideboard: 0/3 card(s)", game1.Instructions());

            var later = new LeagueDeckSelection(Builder(fixedPool: true, defaults: "[1,2,3]"));
            StringAssert.StartsWith("Sideboarding:", later.Instructions());
            StringAssert.Contains("Only the cards you declared for game 1 can be used.", later.Instructions());
        }
    }
}
