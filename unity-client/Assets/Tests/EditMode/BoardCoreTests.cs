using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    internal static class BoardFixtures
    {
        /// <summary>
        /// The captured game states: 405 is 2 players, 406 is 3, 407 is 4 (all
        /// you = bshaftoe, seat 0). Parsed through ApiClient, the way the app
        /// parses them, so the same JSON settings apply (e.g. nulls ignored).
        /// </summary>
        public static GameState Load(int gameId)
        {
            var transport = new FakeHttpTransport();
            transport.Enqueue(200, TestFixtures.Read($"game_{gameId}_state"));
            var api = new ApiClient(new ApiConfig("https://example.test"), transport);
            var result = api.GetGameStateAsync(gameId).GetAwaiter().GetResult();
            Assert.IsTrue(result.Ok, result.Message);
            return result.Value;
        }

        public static BoardPlayer Player(int gamePlayerId, int seat, string name = null) =>
            new BoardPlayer { GamePlayerId = gamePlayerId, SeatOrder = seat, Username = name ?? "P" + seat };

        public static List<BoardPlayer> Seats(int count) =>
            Enumerable.Range(0, count).Select(i => Player(100 + i, i)).ToList();
    }

    public class BoardModelsTests
    {
        [Test]
        public void TheRealFourPlayerState_ParsesEverythingTheBoardDraws()
        {
            var state = BoardFixtures.Load(407);

            Assert.AreEqual("ok", state.Status);
            Assert.AreEqual(407, state.Game.Id);
            Assert.AreEqual(3, state.Game.WinsNeeded);
            Assert.AreEqual(4, state.Players.Count);
            Assert.AreEqual(912, state.You.GamePlayerId);
            Assert.AreEqual(4, state.You.Hand.Count);
            Assert.AreEqual(4, state.InPlay.Count);
            Assert.AreEqual(25, state.DeckCount);
            Assert.AreEqual(1, state.Round.RoundNumber);
            Assert.AreEqual(914, state.Round.CurrentTurnGamePlayerId);
            Assert.AreEqual(4, state.RecentEvents.Count);
            StringAssert.Contains("Confusion", state.RecentEvents[0].Description);
            Assert.IsNull(state.Round.HurtFeelingsGamePlayerId);
        }

        [Test]
        public void Players_CarryTheirSeatAndTheCountsOpponentsShow()
        {
            var bot = BoardFixtures.Load(407).Players.Single(p => p.GamePlayerId == 913);

            Assert.AreEqual("BotSage", bot.Username);
            Assert.IsTrue(bot.IsBot);
            Assert.AreEqual(1, bot.SeatOrder);
            Assert.AreEqual(4, bot.HandCount, "an opponent's hand is only a count");
            Assert.AreEqual(25, bot.DeckCount);
            Assert.AreEqual(6, bot.TotalScore);
            Assert.AreEqual(0, bot.TotalWins);
        }

        [Test]
        public void Cards_InPlayKnowWhoOwnsThem_AndWhetherAnEffectChangedTheirValue()
        {
            var inPlay = BoardFixtures.Load(407).InPlay;

            var plain = inPlay.First(c => c.Name == "Confusion");
            Assert.AreEqual(914, plain.OwnerGamePlayerId);
            Assert.AreEqual(4, plain.Value);
            Assert.IsFalse(plain.ValueIsModified);

            var boosted = inPlay.Single(c => c.Name == "Tranquility");
            Assert.AreEqual(915, boosted.OwnerGamePlayerId);
            Assert.AreEqual(6, boosted.Value);
            Assert.AreEqual(3, boosted.BaseValue);
            Assert.IsTrue(boosted.ValueIsModified, "the art shows the printed 3; the board must show the real 6");
        }

        [Test]
        public void ACardWithNoAlternateValue_HasANullAltValue()
        {
            // The real captures have cards whose alt_value is null.
            var inPlay = BoardFixtures.Load(405).InPlay;

            Assert.IsTrue(inPlay.Any(c => c.AltValue == null), "a real card with no alt value");
            Assert.IsTrue(BoardFixtures.Load(407).InPlay.Any(c => c.AltValue.HasValue), "and real cards that have one");
        }

        [Test]
        public void HandCards_CarryWhatTheArtNeeds()
        {
            var card = BoardFixtures.Load(407).You.Hand.First(c => c.Name == "Loyalty");

            Assert.AreEqual(18, card.CatalogCardId);
            Assert.AreEqual("white", card.Color);
            Assert.AreEqual(3, card.Value);
            StringAssert.Contains("While in play", card.RulesText);
            Assert.IsNull(card.OwnerGamePlayerId, "a hand card has no in-play owner");
        }

        [Test]
        public void DiscardedCards_RememberWhoPlayedThem()
        {
            var discard = BoardFixtures.Load(405).DiscardPile.Single();

            Assert.AreEqual("Celebration", discard.Name);
            Assert.AreEqual(908, discard.LastOwnerGamePlayerId);
            Assert.AreEqual("BotSage", discard.LastOwnerName);
        }

        [Test]
        public void ThePendingDecision_SaysWhoItWaitsOn()
        {
            var decision = BoardFixtures.Load(407).Round.PendingDecision;

            Assert.AreEqual("confusion_give_card", decision.DecisionType);
            Assert.IsTrue(decision.IsYou);
            Assert.AreEqual(912, decision.TargetGamePlayerId);
            Assert.AreEqual(914, decision.InitiatingGamePlayerId);
            Assert.AreEqual("Confusion", decision.PlayedCardName);
            Assert.IsNotNull(decision.Field, "kept raw for when choices become answerable");
        }

        [Test]
        public void AGameWithNoDecisionWaiting_HasNullPendingDecision()
        {
            Assert.IsNull(BoardFixtures.Load(406).Round.PendingDecision);
        }

        [Test]
        public void ASpectatorsState_HasNoSeatAndNoHand()
        {
            // Spectators have no seat of their own: the id and hand come back empty.
            var state = JsonConvert.DeserializeObject<GameState>(
                "{\"status\":\"ok\",\"you\":{\"game_player_id\":null,\"hand\":[]},\"players\":[],\"in_play\":[],\"round\":{}}");

            Assert.IsNull(state.You.GamePlayerId);
            Assert.AreEqual(0, state.You.Hand.Count);
            Assert.IsTrue(BoardDisplay.IsSpectator(state));
        }

        [Test]
        public void AMinimalStateStillParsesWithEmptyListsNotNulls()
        {
            var state = JsonConvert.DeserializeObject<GameState>("{\"status\":\"ok\"}");

            Assert.AreEqual(0, state.Players.Count);
            Assert.AreEqual(0, state.InPlay.Count);
            Assert.AreEqual(0, state.DiscardPile.Count);
            Assert.AreEqual(0, state.RecentEvents.Count);
            Assert.AreEqual(0, state.ChatMessages.Count);
            Assert.IsNotNull(state.Round);
            Assert.IsNotNull(state.You);
        }

        [Test]
        public void ChatMessages_ParseTheServersFieldNames()
        {
            var state = JsonConvert.DeserializeObject<GameState>(
                "{\"status\":\"ok\",\"chat_messages\":[{\"id\":1,\"sender_user_id\":2,\"sender_username\":\"bshaftoe\"," +
                "\"channel\":\"game\",\"message_text\":\"GL;HF\",\"created_at\":\"2026-10-01 12:00:00\"}]}");

            Assert.AreEqual("bshaftoe", state.ChatMessages[0].SenderUsername);
            Assert.AreEqual("GL;HF", state.ChatMessages[0].MessageText);
        }
    }

    public class BoardLayoutTests
    {
        private static Dictionary<int, SeatZone> Zones(int count, int viewerSeat) =>
            BoardLayout.Assign(BoardFixtures.Seats(count), 100 + viewerSeat);

        [Test]
        public void TwoPlayers_ViewerSouth_OpponentNorth()
        {
            var zones = Zones(2, 0);

            Assert.AreEqual(SeatZone.South, zones[100]);
            Assert.AreEqual(SeatZone.North, zones[101]);
        }

        [Test]
        public void ThreePlayers_TheNextSeatInTurnOrderIsOnTheViewersLeft()
        {
            var zones = Zones(3, 0);

            Assert.AreEqual(SeatZone.South, zones[100]);
            Assert.AreEqual(SeatZone.Northwest, zones[101], "the next seat is at the viewer's left");
            Assert.AreEqual(SeatZone.Northeast, zones[102]);
        }

        [Test]
        public void FourPlayers_AroundTheTable()
        {
            var zones = Zones(4, 0);

            Assert.AreEqual(SeatZone.South, zones[100]);
            Assert.AreEqual(SeatZone.West, zones[101], "the next seat is at the viewer's left");
            Assert.AreEqual(SeatZone.North, zones[102]);
            Assert.AreEqual(SeatZone.East, zones[103]);
        }

        [TestCase(0)]
        [TestCase(1)]
        [TestCase(2)]
        [TestCase(3)]
        public void FourPlayers_TheViewerIsAlwaysSouth_AndTheRingTurnsWithThem(int viewerSeat)
        {
            var zones = Zones(4, viewerSeat);

            Assert.AreEqual(SeatZone.South, zones[100 + viewerSeat]);
            Assert.AreEqual(SeatZone.West, zones[100 + (viewerSeat + 1) % 4]);
            Assert.AreEqual(SeatZone.North, zones[100 + (viewerSeat + 2) % 4]);
            Assert.AreEqual(SeatZone.East, zones[100 + (viewerSeat + 3) % 4]);
        }

        [TestCase(2)]
        [TestCase(3)]
        [TestCase(4)]
        public void EveryoneGetsADistinctZone(int count)
        {
            var zones = Zones(count, count - 1);

            Assert.AreEqual(count, zones.Values.Distinct().Count());
        }

        [Test]
        public void ASpectatorIsAnchoredOnTheFirstSeat()
        {
            var zones = BoardLayout.Assign(BoardFixtures.Seats(4), viewerGamePlayerId: null);

            Assert.AreEqual(SeatZone.South, zones[100]);
            Assert.AreEqual(SeatZone.West, zones[101]);
        }

        [Test]
        public void AnUnknownViewerIsTreatedLikeASpectator()
        {
            var zones = BoardLayout.Assign(BoardFixtures.Seats(3), viewerGamePlayerId: 999);

            Assert.AreEqual(SeatZone.South, zones[100]);
        }

        [Test]
        public void ASingleSeat_PuzzleStyle_IsJustSouth()
        {
            var zones = BoardLayout.Assign(BoardFixtures.Seats(1), 100);

            Assert.AreEqual(SeatZone.South, zones[100]);
        }

        [Test]
        public void GapsInTheSeatNumbering_DoNotShiftAnyone()
        {
            // Seats 0, 2, 5 -> the same table as 0, 1, 2.
            var players = new List<BoardPlayer>
            {
                BoardFixtures.Player(100, 0), BoardFixtures.Player(101, 2), BoardFixtures.Player(102, 5),
            };

            var zones = BoardLayout.Assign(players, 100);

            Assert.AreEqual(SeatZone.Northwest, zones[101]);
            Assert.AreEqual(SeatZone.Northeast, zones[102]);
        }

        [Test]
        public void PlayersGivenOutOfOrder_AreStillSeatedBySeatOrder()
        {
            var players = BoardFixtures.Seats(4);
            players.Reverse();

            var zones = BoardLayout.Assign(players, 100);

            Assert.AreEqual(SeatZone.West, zones[101]);
        }

        [TestCase(0)]
        [TestCase(5)]
        public void ThePlayerCountMustBeOneToFour(int count)
        {
            Assert.Throws<System.ArgumentOutOfRangeException>(() => BoardLayout.Assign(BoardFixtures.Seats(count), 100));
        }

        [Test]
        public void TheRealGames_SeatTheViewerSouth()
        {
            foreach (var id in new[] { 405, 406, 407 })
            {
                var state = BoardFixtures.Load(id);
                var zones = BoardLayout.Assign(state.Players, state.You.GamePlayerId);

                Assert.AreEqual(SeatZone.South, zones[state.You.GamePlayerId.Value], "game " + id);
                Assert.AreEqual(state.Players.Count, zones.Values.Distinct().Count(), "game " + id);
            }
        }

        [TestCase(2, new[] { SeatZone.North })]
        [TestCase(3, new[] { SeatZone.Northwest, SeatZone.Northeast })]
        [TestCase(4, new[] { SeatZone.West, SeatZone.North, SeatZone.East })]
        public void OpponentZones_ReadLeftToRight(int count, SeatZone[] expected)
        {
            CollectionAssert.AreEqual(expected, BoardLayout.OpponentZonesLeftToRight(count).ToArray());
        }
    }

    public class BoardDisplayTests
    {
        [Test]
        public void TheBanner_WhenACardWaitsOnYou()
        {
            Assert.AreEqual("Your response is needed  -  Confusion", BoardDisplay.TurnBanner(BoardFixtures.Load(407)));
            Assert.AreEqual("Your response is needed  -  Fury", BoardDisplay.TurnBanner(BoardFixtures.Load(405)));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(BoardFixtures.Load(407)));
        }

        [Test]
        public void TheBanner_OnYourTurn()
        {
            var state = BoardFixtures.Load(406);

            Assert.AreEqual("Your turn", BoardDisplay.TurnBanner(state));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void TheBanner_WhenYouHaveSeveralPlaysLeft()
        {
            var state = BoardFixtures.Load(406);
            state.Round.PlaysRemaining = 2;

            Assert.AreEqual("Your turn  -  2 plays left", BoardDisplay.TurnBanner(state));
        }

        [Test]
        public void TheBanner_WhenItIsSomeoneElsesTurn()
        {
            var state = BoardFixtures.Load(406);
            state.Round.CurrentTurnGamePlayerId = 910;

            Assert.AreEqual("BotSage's turn", BoardDisplay.TurnBanner(state));
            Assert.IsFalse(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void TheBanner_WhenACardWaitsOnSomeoneElse()
        {
            var state = BoardFixtures.Load(407);
            state.Round.PendingDecision.IsYou = false;
            state.Round.PendingDecision.TargetGamePlayerId = 913;

            Assert.AreEqual("Waiting for BotSage to respond  -  Confusion", BoardDisplay.TurnBanner(state));
            Assert.IsFalse(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void TheBanner_ForASpectator_NamesWhoseTurnItIs()
        {
            var state = BoardFixtures.Load(406);
            state.You = new BoardViewer();
            state.Round.CurrentTurnGamePlayerId = 911;

            Assert.AreEqual("BotSageQuick's turn", BoardDisplay.TurnBanner(state));
            Assert.IsFalse(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void TheBanner_WhenTheGameIsOver()
        {
            var won = BoardFixtures.Load(406);
            won.Game.Status = "completed";
            won.Game.WinnerUsernames = new List<string> { "bshaftoe" };
            Assert.AreEqual("You won!", BoardDisplay.TurnBanner(won));

            var lost = BoardFixtures.Load(406);
            lost.Game.Status = "completed";
            lost.Game.WinnerUsernames = new List<string> { "BotSage" };
            Assert.AreEqual("Game over  -  BotSage won", BoardDisplay.TurnBanner(lost));
            Assert.IsFalse(BoardDisplay.BannerNeedsViewer(lost));

            var drawn = BoardFixtures.Load(406);
            drawn.Game.Status = "completed";
            Assert.AreEqual("Game over", BoardDisplay.TurnBanner(drawn));
        }

        [Test]
        public void RoundLine_ShowsTheRoundAndWhatWinsTheGame()
        {
            Assert.AreEqual("Round 2  -  First to 3 wins", BoardDisplay.RoundLine(BoardFixtures.Load(405)));
        }

        [TestCase(1, 1, "1 pt  -  1 win")]
        [TestCase(0, 0, "0 pts  -  0 wins")]
        [TestCase(6, 2, "6 pts  -  2 wins")]
        public void ScoreLine_Pluralizes(int score, int wins, string expected)
        {
            Assert.AreEqual(expected, BoardDisplay.ScoreLine(new BoardPlayer { TotalScore = score, TotalWins = wins }));
        }

        [Test]
        public void MoodsOf_GroupsInPlayCardsByOwner()
        {
            var state = BoardFixtures.Load(405);

            CollectionAssert.AreEqual(new[] { "Laziness" }, BoardDisplay.MoodsOf(state, 907).Select(c => c.Name).ToArray());
            CollectionAssert.AreEquivalent(new[] { "Fury", "Gluttony" }, BoardDisplay.MoodsOf(state, 908).Select(c => c.Name).ToArray());
            Assert.AreEqual(0, BoardDisplay.MoodsOf(state, 12345).Count);
        }

        [Test]
        public void Viewer_IsTheSeatedPlayerMatchingYou()
        {
            Assert.AreEqual("bshaftoe", BoardDisplay.Viewer(BoardFixtures.Load(407)).Username);
            Assert.IsFalse(BoardDisplay.IsSpectator(BoardFixtures.Load(407)));
        }
    }

    public class BoardSessionTests
    {
        private FakeHttpTransport _transport;
        private ApiClient _api;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _api = new ApiClient(new ApiConfig("https://example.test"), _transport);
        }

        [Test]
        public void ThePlayersSession_ReadsTheGameStateOfThatGame()
        {
            _transport.Enqueue(200, TestFixtures.Read("game_407_state"));
            var session = BoardSession.ForPlayer(_api, 407);

            var result = session.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/games/state?game_id=407", _transport.LastRequest.Url);
            Assert.AreEqual(4, session.State.Players.Count);
            Assert.IsFalse(session.IsSpectating);
        }

        [Test]
        public void ASpectatorSession_UsesTheSpectateRoute_WithTheCodeEscaped()
        {
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));
            var session = BoardSession.ForSpectator(_api, 406, "AB 12/x");

            session.RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/games/spectate/state?game_id=406&code=AB%2012%2Fx", _transport.LastRequest.Url);
            Assert.IsTrue(session.IsSpectating);
        }

        [Test]
        public void ASpectatorSession_WithoutACode_AsksByIdAlone()
        {
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            BoardSession.ForSpectator(_api, 406).RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/games/spectate/state?game_id=406", _transport.LastRequest.Url);
        }

        [Test]
        public void Refresh_RaisesChanged_OnlyWhenTheGameActuallyChanged()
        {
            var session = BoardSession.ForPlayer(_api, 407);
            var raised = 0;
            session.Changed += () => raised++;
            _transport.Enqueue(200, TestFixtures.Read("game_407_state"));
            _transport.EnqueueNetworkError("offline");
            _transport.Enqueue(200, TestFixtures.Read("game_407_state"));
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            session.RefreshAsync().GetAwaiter().GetResult(); // first look: a change
            Assert.AreEqual(1, raised);

            session.RefreshAsync().GetAwaiter().GetResult(); // offline: nothing
            Assert.AreEqual(1, raised);

            session.RefreshAsync().GetAwaiter().GetResult(); // identical poll: nothing to redraw
            Assert.AreEqual(1, raised);

            session.RefreshAsync().GetAwaiter().GetResult(); // a different game state
            Assert.AreEqual(2, raised);
        }

        [Test]
        public void AFailedRefresh_KeepsTheLastKnownBoard()
        {
            var session = BoardSession.ForPlayer(_api, 407);
            _transport.Enqueue(200, TestFixtures.Read("game_407_state"));
            session.RefreshAsync().GetAwaiter().GetResult();
            _transport.EnqueueNetworkError("offline");

            var result = session.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
            Assert.AreEqual(4, session.State.Players.Count, "the old board stays up");
        }

        [Test]
        public void ANotSeatedGame_ShowsTheServersReason()
        {
            _transport.Enqueue(403, "{\"status\":\"error\",\"message\":\"You are not a player in this game.\"}");

            var result = BoardSession.ForPlayer(_api, 999).RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("You are not a player in this game.", result.Message);
        }

        [Test]
        public void BeforeTheFirstRefresh_ThereIsNoState()
        {
            Assert.IsNull(BoardSession.ForPlayer(_api, 1).State);
        }
    }
}
