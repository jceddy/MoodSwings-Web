using System;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>What the viewer can do on a board, and what each action sends and reports.</summary>
    public class BoardRulesTests
    {
        [Test]
        public void OnYourTurnWithNothingPending_YouCanActAndResign()
        {
            var state = BoardFixtures.Load(406);

            Assert.IsTrue(state.You.IsYourTurn);
            Assert.IsTrue(BoardDisplay.CanAct(state));
            Assert.IsTrue(BoardDisplay.CanResign(state));
            Assert.IsFalse(BoardDisplay.NeedsAdvanceTurn(state));
            Assert.IsNull(BoardDisplay.UnsupportedReason(state));
        }

        [Test]
        public void OnSomeoneElsesTurn_YouCanOnlyResign()
        {
            var state = BoardFixtures.Load(405);
            state.Round.PendingDecision = null;

            Assert.IsFalse(state.You.IsYourTurn);
            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsTrue(BoardDisplay.CanResign(state));
        }

        [Test]
        public void AQuestionForYou_FreezesTheRound_EvenOnYourOwnTurn()
        {
            var state = BoardFixtures.Load(406);
            state.Round.PendingDecision = new PendingDecision { DecisionType = "compulsion_give_card", IsYou = false };

            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsFalse(BoardDisplay.CanResign(state), "resigning is shut while a decision is open");
        }

        [Test]
        public void WhenTheTurnWaitsToBeAcknowledged_OnlyAdvancingIsOffered()
        {
            var state = BoardFixtures.Load(406);
            state.You.TurnPendingAcknowledgment = true;

            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsTrue(BoardDisplay.NeedsAdvanceTurn(state));
        }

        [Test]
        public void ASpectatorCanDoNothing()
        {
            var state = BoardFixtures.Load(406);
            state.You.GamePlayerId = null;

            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsFalse(BoardDisplay.CanResign(state));
            Assert.IsFalse(BoardDisplay.NeedsReady(state));
        }

        [Test]
        public void AFinishedGame_OffersNothing()
        {
            var state = BoardFixtures.Load(406);
            state.Game.Status = "completed";

            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsFalse(BoardDisplay.CanResign(state));
        }

        [Test]
        public void AResignedPlayer_CanDoNothing()
        {
            var state = BoardFixtures.Load(406);
            state.Players.Single(p => p.GamePlayerId == state.You.GamePlayerId).Resigned = true;

            Assert.IsFalse(BoardDisplay.CanAct(state));
            Assert.IsFalse(BoardDisplay.CanResign(state));
        }

        [TestCase("custom_duel")]
        [TestCase("quick_draft")]
        [TestCase("sealed_deck")]
        public void DraftsAndDuels_AreNotPlayableYet(string deckType)
        {
            var state = BoardFixtures.Load(406);
            state.Game.DeckType = deckType;

            StringAssert.Contains("can't be played in the app yet", BoardDisplay.UnsupportedReason(state));
            Assert.IsFalse(BoardDisplay.CanAct(state));
        }

        [TestCase("team")]
        [TestCase("closed_team")]
        [TestCase("duel")]
        public void OtherFormats_AreNotPlayableYet(string format)
        {
            var state = BoardFixtures.Load(406);
            state.Game.Format = format;

            Assert.IsNotNull(BoardDisplay.UnsupportedReason(state));
            Assert.IsFalse(BoardDisplay.CanAct(state));
        }

        [Test]
        public void ADecisionAboutWhoGoesFirst_MakesTheGameUnsupportedForNow()
        {
            var state = BoardFixtures.Load(406);
            state.FirstPlayerDecision = JObject.Parse(@"{""you_are_previous_loser"":true}");

            Assert.IsNotNull(BoardDisplay.UnsupportedReason(state));
        }

        [Test]
        public void AWaitingGame_StartsAtOnce_UnlessItsSynchronousAndSomeoneIsNotReady()
        {
            var state = BoardFixtures.Load(406);
            state.Game.Status = "waiting";
            Assert.IsTrue(BoardDisplay.ReadyToStart(state));
            Assert.AreEqual("Starting the game...", BoardDisplay.TurnBanner(state));

            state.Game.SynchronousMode = true;
            foreach (var player in state.Players)
            {
                player.Ready = false;
            }

            state.Players[0].Ready = true;
            Assert.IsFalse(BoardDisplay.ReadyToStart(state));
            Assert.AreEqual("Waiting for everyone to be ready", BoardDisplay.TurnBanner(state));

            foreach (var player in state.Players)
            {
                player.Ready = true;
            }

            Assert.IsTrue(BoardDisplay.ReadyToStart(state));
        }

        [Test]
        public void TheReadyCheck_WaitsOnYouUntilYouAreReady_AndNamesWhoElseIsNot()
        {
            var state = BoardFixtures.Load(406);
            state.Game.Status = "waiting";
            state.Game.SynchronousMode = true;
            foreach (var player in state.Players)
            {
                player.Ready = false;
            }

            Assert.IsTrue(BoardDisplay.NeedsReady(state));

            state.Players[1].Ready = true;
            Assert.AreEqual("Ready: BotSage  -  Not yet: bshaftoe, BotSageQuick", BoardDisplay.ReadyCheckLine(state));

            state.Players[0].Ready = true;
            Assert.IsFalse(BoardDisplay.NeedsReady(state));
        }

        // --- clock ---------------------------------------------------------------------------

        private static GameState OnTheClock(string deadline, int banked = 0)
        {
            var state = BoardFixtures.Load(406);
            state.Game.SynchronousMode = true;
            state.Game.ActionDeadlineAt = deadline;
            state.Game.ActionDeadlineGamePlayerId = state.Players[1].GamePlayerId;
            state.Players[1].TimeoutExtensionsBanked = banked;
            return state;
        }

        [Test]
        public void TheClock_CountsDownFromTheServersUtcDeadline()
        {
            var state = OnTheClock("2026-10-02 12:00:30");
            var now = new DateTime(2026, 10, 2, 12, 0, 7, DateTimeKind.Utc);

            Assert.AreEqual("BotSage's clock: 23s", BoardDisplay.ClockLine(state, now));
            Assert.IsFalse(BoardDisplay.ClockIsUrgent(state, now));
            Assert.IsTrue(BoardDisplay.ClockIsUrgent(state, new DateTime(2026, 10, 2, 12, 0, 21, DateTimeKind.Utc)));
        }

        [Test]
        public void TheClock_StopsAtZero_AndIsReadAsUtcWhateverTheLocalZone()
        {
            var state = OnTheClock("2026-10-02 12:00:30");

            Assert.AreEqual("BotSage's clock: 0s", BoardDisplay.ClockLine(state, new DateTime(2026, 10, 2, 13, 0, 0, DateTimeKind.Utc)));
        }

        [Test]
        public void TheClock_IsHidden_WhenThereIsNoneOrAnExtensionIsBanked()
        {
            var now = new DateTime(2026, 10, 2, 12, 0, 0, DateTimeKind.Utc);

            Assert.IsNull(BoardDisplay.ClockLine(BoardFixtures.Load(406), now), "not a synchronous game");
            Assert.IsNull(BoardDisplay.ClockLine(OnTheClock("2026-10-02 12:00:30", banked: 1), now));
            Assert.IsNull(BoardDisplay.ClockLine(OnTheClock("not a date"), now));
        }

        // --- notices ---------------------------------------------------------------------------

        [Test]
        public void TheLoopWarning_IsOnlyShownToThePlayerItsAbout()
        {
            var state = BoardFixtures.Load(406);
            state.Game.LoopWarning = new LoopWarning { GamePlayerId = state.You.GamePlayerId.Value, OccurrenceCount = 3 };
            StringAssert.Contains("repeated 3 times", BoardDisplay.LoopWarningText(state));

            state.Game.LoopWarning = new LoopWarning { GamePlayerId = 99999, OccurrenceCount = 3 };
            Assert.IsNull(BoardDisplay.LoopWarningText(state));
        }

        [Test]
        public void AScoringPreview_ListsEachScoreAndAnySwaps()
        {
            var state = BoardFixtures.Load(405);
            var me = state.Players[0];
            var bot = state.Players[1];
            state.Round.ScoringPreview = new ScoringPreview();
            state.Round.ScoringPreview.Scores[me.GamePlayerId] = 7;
            state.Round.ScoringPreview.Scores[bot.GamePlayerId] = 4;
            state.Round.ScoringPreview.SneakinessSwaps.Add(new ScoreSwap { GamePlayerId = me.GamePlayerId, SwapsWithGamePlayerId = bot.GamePlayerId });

            var lines = BoardDisplay.ScoringPreviewLines(state);

            Assert.AreEqual("bshaftoe: 7", lines[0]);
            Assert.AreEqual("BotSage: 4", lines[1]);
            StringAssert.Contains("bshaftoe <-> BotSage", lines[2]);
        }

        [Test]
        public void EffectLines_JoinBoardAndScoringEffects()
        {
            var state = BoardFixtures.Load(405);
            state.Round.BoardEffects.Add(new EffectNote { Description = "Every mood is blue" });
            state.Round.ScoringEffects.Add(new EffectNote { Description = "Double your next win" });

            Assert.AreEqual(new[] { "Every mood is blue", "Double your next win" }, BoardDisplay.EffectLines(state).ToArray());
        }

        [Test]
        public void DecisionTitles_NameTheCard_OrTheScoringBonus()
        {
            Assert.AreEqual("Respond to Fury", BoardDisplay.DecisionTitle(new PendingDecision { DecisionType = "fury_discard_mood", PlayedCardName = "Fury" }));
            Assert.AreEqual("Respond to a mood", BoardDisplay.DecisionTitle(new PendingDecision { DecisionType = "x" }));
            Assert.AreEqual("Repeat Hate's effect?", BoardDisplay.DecisionTitle(new PendingDecision { DecisionType = "duplicity_repeat_offer", PlayedCardName = "Hate" }));
            Assert.AreEqual("Enthusiasm's bonus", BoardDisplay.DecisionTitle(new PendingDecision { DecisionType = "enthusiasm_extra_score" }));
            Assert.AreEqual("Order your after-scoring effects", BoardDisplay.DecisionTitle(new PendingDecision { DecisionType = "after_scoring_order" }));
        }

        [Test]
        public void ASuppressedMood_SaysWhatSuppressesIt()
        {
            var card = Newtonsoft.Json.JsonConvert.DeserializeObject<BoardCard>(
                @"{""card_id"":1,""is_suppressed"":true,""suppressions"":[
                    {""expiry"":""end_of_round"",""suppressed_by_card_id"":9,""suppressed_by_name"":""Scorn""}]}");

            Assert.IsTrue(card.IsSuppressed);
            Assert.AreEqual("Suppressed by Scorn", BoardDisplay.SuppressedByText(card));

            card.Suppressions.Add(new Suppression { SuppressedByName = "Shame" });
            card.Suppressions.Add(new Suppression { SuppressedByName = "Scorn" });
            Assert.AreEqual("Suppressed by Scorn and Shame", BoardDisplay.SuppressedByText(card), "each source once");

            card.Suppressions.Clear();
            card.Suppressions.Add(new Suppression { SuppressedByName = null });
            Assert.AreEqual("Suppressed", BoardDisplay.SuppressedByText(card));
        }

        [Test]
        public void OutcomeNotice_SaysWhenARoundOrTheGameEnded()
        {
            Assert.AreEqual("Game complete!", BoardDisplay.OutcomeNotice(new GameActionResponse { GameCompleted = true, RoundScored = true }));
            StringAssert.StartsWith("Round scored", BoardDisplay.OutcomeNotice(new GameActionResponse { RoundScored = true }));
            Assert.IsNull(BoardDisplay.OutcomeNotice(new GameActionResponse()));
        }
    }

    public class BoardActionTests
    {
        private FakeHttpTransport _transport;
        private ApiClient _api;
        private BoardSession _session;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _api = new ApiClient(new ApiConfig("https://example.test"), _transport);
            _session = BoardSession.ForPlayer(_api, 406);
        }

        private void LoadBoard()
        {
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));
            _session.RefreshAsync().GetAwaiter().GetResult();
            _transport.Requests.Clear();
        }

        private static JObject Body(HttpRequest request) => JObject.Parse(request.Body);

        [Test]
        public void Playing_SendsTheCardAndItsChoices_ThenReadsTheBoardAgain()
        {
            LoadBoard();
            var card = _session.State.You.Hand.First();
            _transport.Enqueue(200, @"{""status"":""ok"",""round_scored"":false,""game_completed"":false}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            var result = _session.PlayAsync(card, new JObject { ["target_mood_id"] = 7 }).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.IsNull(result.Notice);
            Assert.AreEqual("https://example.test/app/games/play", _transport.Requests[0].Url);
            Assert.AreEqual("POST", _transport.Requests[0].Method);
            var body = Body(_transport.Requests[0]);
            Assert.AreEqual(406, (int)body["game_id"]);
            Assert.AreEqual(card.CardId, (int)body["card_id"]);
            Assert.AreEqual(7, (int)body["choices"]["target_mood_id"]);
            StringAssert.Contains("/games/state?game_id=406", _transport.Requests[1].Url);
        }

        [Test]
        public void PlayingACardWithNoChoices_StillSendsAnEmptyChoicesObject()
        {
            LoadBoard();
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            _session.PlayAsync(_session.State.You.Hand.First(), new JObject()).GetAwaiter().GetResult();

            Assert.AreEqual("{}", Body(_transport.Requests[0])["choices"].ToString(Newtonsoft.Json.Formatting.None));
        }

        [Test]
        public void ARefusedPlay_ReportsTheServersReason_AndStillRefreshesTheBoard()
        {
            LoadBoard();
            _transport.Enqueue(409, @"{""status"":""error"",""message"":""It is not your turn.""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            var result = _session.PlayAsync(_session.State.You.Hand.First(), new JObject()).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("It is not your turn.", result.Message);
            Assert.AreEqual(2, _transport.Requests.Count, "the board is read again to show what really happened");
        }

        [Test]
        public void AnInvalidChoice_ComesBackAsTheServersMessage()
        {
            LoadBoard();
            _transport.Enqueue(400, @"{""status"":""error"",""message"":""Choose a mood in play.""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            var result = _session.PlayAsync(_session.State.You.Hand.First(), new JObject()).GetAwaiter().GetResult();

            Assert.AreEqual("Choose a mood in play.", result.Message);
        }

        [Test]
        public void ADeadConnection_IsSaidInPlainWords()
        {
            LoadBoard();
            _transport.EnqueueNetworkError("down");
            _transport.EnqueueNetworkError("down");

            var result = _session.PassAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
        }

        [Test]
        public void ScoringARound_OrEndingTheGame_ComesBackAsANotice()
        {
            LoadBoard();
            _transport.Enqueue(200, @"{""status"":""ok"",""round_scored"":true,""game_completed"":false}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));
            StringAssert.StartsWith("Round scored", _session.PassAsync().GetAwaiter().GetResult().Notice);

            _transport.Enqueue(200, @"{""status"":""ok"",""round_scored"":false,""game_completed"":true,""winner_game_player_id"":909}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));
            Assert.AreEqual("Game complete!", _session.PassAsync().GetAwaiter().GetResult().Notice);
        }

        [Test]
        public void EachAction_UsesItsOwnRouteAndBody()
        {
            LoadBoard();
            var routes = new (string route, Func<System.Threading.Tasks.Task<BoardActionResult>> act)[]
            {
                ("/games/pass", () => _session.PassAsync()),
                ("/games/advance-turn", () => _session.AdvanceTurnAsync()),
                ("/games/resign", () => _session.ResignAsync()),
                ("/games/respond", () => _session.RespondAsync(new JObject { ["k"] = 1 })),
                ("/games/ready", () => _session.MarkReadyAsync()),
            };

            foreach (var (route, act) in routes)
            {
                _transport.Requests.Clear();
                _transport.Enqueue(200, @"{""status"":""ok""}");
                _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

                Assert.IsTrue(act().GetAwaiter().GetResult().Ok, route);
                Assert.AreEqual("https://example.test/app" + route, _transport.Requests[0].Url);
                Assert.AreEqual(406, (int)Body(_transport.Requests[0])["game_id"], route);
            }
        }

        [Test]
        public void Responding_SendsTheAnswerUnderChoices()
        {
            LoadBoard();
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            _session.RespondAsync(new JObject { ["given_card_id_912"] = 14227 }).GetAwaiter().GetResult();

            Assert.AreEqual(14227, (int)Body(_transport.Requests[0])["choices"]["given_card_id_912"]);
        }

        [Test]
        public void ASpectator_CannotActAtAll_AndNothingIsSent()
        {
            var spectator = BoardSession.ForSpectator(_api, 406);

            Assert.IsFalse(spectator.PassAsync().GetAwaiter().GetResult().Ok);
            Assert.IsFalse(spectator.SendChatAsync("hi").GetAwaiter().GetResult().Ok);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void WhileAnActionIsInFlight_AnotherIsRefused()
        {
            LoadBoard();
            var gate = new System.Threading.Tasks.TaskCompletionSource<bool>();
            var slow = new SlowTransport(_transport, gate.Task);
            var api = new ApiClient(new ApiConfig("https://example.test"), slow);
            var session = BoardSession.ForPlayer(api, 406);

            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));
            var first = session.PassAsync();
            Assert.IsTrue(session.Busy);

            var second = session.PassAsync().GetAwaiter().GetResult();
            Assert.IsFalse(second.Ok);
            StringAssert.Contains("still working", second.Message);

            gate.SetResult(true);
            Assert.IsTrue(first.GetAwaiter().GetResult().Ok);
            Assert.IsFalse(session.Busy);
        }

        private sealed class SlowTransport : IHttpTransport
        {
            private readonly IHttpTransport _inner;
            private readonly System.Threading.Tasks.Task _gate;

            public SlowTransport(IHttpTransport inner, System.Threading.Tasks.Task gate)
            {
                _inner = inner;
                _gate = gate;
            }

            public async System.Threading.Tasks.Task<HttpResponse> SendAsync(HttpRequest request, System.Threading.CancellationToken cancellationToken)
            {
                await _gate;
                return await _inner.SendAsync(request, cancellationToken);
            }
        }

        [Test]
        public void Chat_PostsToTheTableChannel_AndThenRefreshes()
        {
            LoadBoard();
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            var result = _session.SendChatAsync("  good game  ").GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            var body = Body(_transport.Requests[0]);
            Assert.AreEqual("https://example.test/app/games/chat", _transport.Requests[0].Url);
            Assert.AreEqual("table", (string)body["channel"]);
            Assert.AreEqual("good game", (string)body["message_text"]);
            Assert.AreEqual(2, _transport.Requests.Count);
        }

        [Test]
        public void ABlankChatMessage_IsNotSent()
        {
            LoadBoard();

            Assert.IsFalse(_session.SendChatAsync("   ").GetAwaiter().GetResult().Ok);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void ARefusedChat_ReportsWhy()
        {
            LoadBoard();
            _transport.Enqueue(400, @"{""status"":""error"",""message"":""Message too long.""}");

            Assert.AreEqual("Message too long.", _session.SendChatAsync("x").GetAwaiter().GetResult().Message);
        }

        // --- starting ----------------------------------------------------------------------------

        private string WaitingBoard(bool synchronous = false, bool allReady = true)
        {
            var state = JObject.Parse(TestFixtures.Read("game_406_state"));
            state["game"]["status"] = "waiting";
            state["game"]["synchronous_mode"] = synchronous;
            foreach (var player in state["players"])
            {
                player["ready"] = allReady;
            }

            return state.ToString();
        }

        [Test]
        public void AWaitingGame_IsStartedOnce_ThenRefreshed()
        {
            _transport.Enqueue(200, WaitingBoard());
            _session.RefreshAsync().GetAwaiter().GetResult();
            _transport.Requests.Clear();
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_406_state"));

            Assert.IsTrue(_session.StartWhenReadyAsync().GetAwaiter().GetResult());

            Assert.AreEqual("https://example.test/app/games/start", _transport.Requests[0].Url);
            Assert.AreEqual("in_progress", _session.State.Game.Status);
        }

        [Test]
        public void ASynchronousGame_IsNotStartedUntilEveryoneIsReady()
        {
            _transport.Enqueue(200, WaitingBoard(synchronous: true, allReady: false));
            _session.RefreshAsync().GetAwaiter().GetResult();
            _transport.Requests.Clear();

            Assert.IsFalse(_session.StartWhenReadyAsync().GetAwaiter().GetResult());
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void StartingAGameThatSomeoneElseStartedFirst_IsHarmless()
        {
            _transport.Enqueue(200, WaitingBoard());
            _session.RefreshAsync().GetAwaiter().GetResult();
            _transport.Enqueue(409, @"{""status"":""error"",""message"":""Game already started.""}");

            Assert.IsFalse(_session.StartWhenReadyAsync().GetAwaiter().GetResult());
        }

        [Test]
        public void AGameInProgress_IsNotStartedAgain()
        {
            LoadBoard();

            Assert.IsFalse(_session.StartWhenReadyAsync().GetAwaiter().GetResult());
            Assert.AreEqual(0, _transport.Requests.Count);
        }
    }
}
