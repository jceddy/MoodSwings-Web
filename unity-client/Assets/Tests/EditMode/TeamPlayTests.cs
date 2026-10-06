using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Team play (Open and Closed): teams and their scores, the teammate, the decisions a team makes
    /// together, and Closed Team's opening card pass. No team game was captured, so the states are the real
    /// four-player capture (you are bshaftoe, 912; BotSage 913, BotSageQuick 914, BotSageDeep 915) edited into
    /// one, with the fields written as the server sends them.
    /// </summary>
    public class TeamPlayTests
    {
        private static GameState TeamGame(string format = "team")
        {
            var state = BoardFixtures.Load(407);
            state.Round.PendingDecision = null;
            state.Game.Format = format;
            state.Players[0].TeamId = 0;
            state.Players[1].TeamId = 0;
            state.Players[2].TeamId = 1;
            state.Players[3].TeamId = 1;
            state.You.TeammateGamePlayerId = 913;
            state.Teams = new List<TeamInfo>
            {
                new TeamInfo { TeamId = 0, GamePlayerIds = { 912, 913 }, TotalScore = 6, TotalWins = 1 },
                new TeamInfo { TeamId = 1, GamePlayerIds = { 914, 915 }, TotalScore = 4, TotalWins = 0 },
            };
            return state;
        }

        private static GameState Deciding(string type, string phase, bool canPropose = false, bool canConfirm = false, int team = 0,
            int? proposer = null, int? proposed = null)
        {
            var state = TeamGame();
            state.Round.CurrentTurnGamePlayerId = null;
            state.TeamDecision = new TeamDecision
            {
                DecisionType = type,
                TeamId = team,
                Phase = phase,
                CandidateGamePlayerIds = team == 0 ? new List<int> { 912, 913 } : new List<int> { 914, 915 },
                ProposerGamePlayerId = proposer,
                ProposedGamePlayerId = proposed,
                CanPropose = canPropose,
                CanConfirm = canConfirm,
            };
            return state;
        }

        // --- teams -------------------------------------------------------------------------------

        [Test]
        public void ATeam_IsNamedByItsNumberAndMembers()
        {
            var state = TeamGame();

            Assert.AreEqual("Team 1 (bshaftoe and BotSage)", BoardDisplay.TeamLabel(state, state.Teams[0]));
            Assert.AreEqual("Team 2 (BotSageQuick and BotSageDeep)", BoardDisplay.TeamLabel(state, state.Teams[1]));
        }

        [Test]
        public void EachPlate_SaysWhoIsYourPartnerAndWhoIsAgainstYou()
        {
            var state = TeamGame();

            Assert.IsNull(BoardDisplay.TeamTag(state, state.Players[0]), "you're marked '(you)' already");
            Assert.AreEqual("your teammate", BoardDisplay.TeamTag(state, state.Players[1]));
            Assert.AreEqual("opponent", BoardDisplay.TeamTag(state, state.Players[2]));
            Assert.AreEqual("opponent", BoardDisplay.TeamTag(state, state.Players[3]));
            Assert.IsNull(BoardDisplay.TeamTag(BoardFixtures.Load(407), BoardFixtures.Load(407).Players[1]), "no tags outside team play");
        }

        [Test]
        public void TeamScores_AreShownYoursFirst_AndByNumberToASpectator()
        {
            var state = TeamGame();
            Assert.AreEqual("Your team 6 pts, 1 win  -  Opposing team 4 pts, 0 wins", BoardDisplay.TeamScoreLine(state));
            StringAssert.Contains("Your team 6 pts, 1 win", BoardDisplay.HeaderLine(state, false, System.DateTime.UtcNow));

            state.You.GamePlayerId = null;
            Assert.AreEqual("Team 1 6 pts, 1 win  -  Team 2 4 pts, 0 wins", BoardDisplay.TeamScoreLine(state));
            Assert.IsNull(BoardDisplay.TeamScoreLine(BoardFixtures.Load(407)), "none outside team play");
        }

        [TestCase("team")]
        [TestCase("closed_team")]
        public void BothTeamFormats_ArePlayableNow(string format)
        {
            Assert.IsNull(BoardDisplay.UnsupportedReason(TeamGame(format)));
            Assert.IsTrue(BoardDisplay.IsTeamGame(TeamGame(format)));
        }

        [Test]
        public void ADraftTeamGame_IsStillNotPlayable()
        {
            var state = TeamGame();
            state.Game.DeckType = "quick_draft";

            Assert.IsNotNull(BoardDisplay.UnsupportedReason(state));
        }

        [Test]
        public void OnlyOpenTeamPlay_ShowsYourPartnersHand()
        {
            var open = JsonConvert<GameState>(
                @"{""you"":{""game_player_id"":912,""teammate_game_player_id"":913,""hand"":[],
                    ""teammate_hand"":[{""card_id"":5,""name"":""Calm"",""color"":""white"",""value"":0,""base_value"":0}]}}");
            var closed = JsonConvert<GameState>(@"{""you"":{""game_player_id"":912,""teammate_game_player_id"":913,""hand"":[]}}");

            Assert.AreEqual("Calm", open.You.TeammateHand.Single().Name);
            Assert.IsNull(closed.You.TeammateHand, "Closed Team keeps hands private");
            Assert.AreEqual(913, closed.You.TeammateGamePlayerId);
        }

        private static T JsonConvert<T>(string json) => Newtonsoft.Json.JsonConvert.DeserializeObject<T>(json);

        // --- the team decision ---------------------------------------------------------------------

        [Test]
        public void TheRealServersTeamDecisionShape_Parses()
        {
            var state = JsonConvert<GameState>(
                @"{""teams"":[{""team_id"":0,""game_player_ids"":[912,913],""total_score"":3,""total_wins"":1}],
                    ""team_decision"":{""decision_type"":""turn_order"",""team_id"":0,""phase"":""confirm"",
                      ""candidate_game_player_ids"":[912,913],""proposer_game_player_id"":913,""proposed_game_player_id"":912,
                      ""can_propose"":false,""can_confirm"":true},
                    ""initial_card_pass"":{""you_submitted"":false,""submitted_game_player_ids"":[913]}}");

            Assert.AreEqual(1, state.Teams[0].TotalWins);
            Assert.AreEqual("confirm", state.TeamDecision.Phase);
            Assert.IsTrue(state.TeamDecision.CanConfirm);
            Assert.AreEqual(912, state.TeamDecision.ProposedGamePlayerId);
            Assert.AreEqual(new[] { 913 }, state.InitialCardPass.SubmittedGamePlayerIds.ToArray());
        }

        [Test]
        public void ProposingWhoGoesNext_IsAskedOfYou_WhenYouMay()
        {
            var state = Deciding("turn_order", "propose", canPropose: true);

            Assert.IsTrue(BoardDisplay.NeedsTeamProposal(state));
            Assert.AreEqual("Choose who should go next", BoardDisplay.TeamDecisionBanner(state));
            Assert.AreEqual("Choose who should go next:", BoardDisplay.TeamDecisionStatus(state));
            Assert.AreEqual("Your team's turn", BoardDisplay.TeamDecisionTitle(state));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(state));
            Assert.AreEqual("Choose who should go next", BoardDisplay.TurnBanner(state));
        }

        [Test]
        public void TheSharedDraw_IsAboutWhoDrawsTheCard()
        {
            var state = Deciding("draw_recipient", "propose", canPropose: true);

            Assert.AreEqual("Choose who should draw the shared card:", BoardDisplay.TeamDecisionStatus(state));
            Assert.AreEqual("Your team's shared draw", BoardDisplay.TeamDecisionTitle(state));
        }

        [Test]
        public void YourPartnersProposal_AsksYouToAgree()
        {
            var state = Deciding("turn_order", "confirm", canConfirm: true, proposer: 913, proposed: 912);

            Assert.IsTrue(BoardDisplay.NeedsTeamConfirmation(state));
            Assert.IsFalse(BoardDisplay.NeedsTeamProposal(state));
            Assert.AreEqual("BotSage proposed bshaftoe to go next. Do you agree?", BoardDisplay.TeamDecisionStatus(state));
            Assert.AreEqual("Your partner made a proposal - do you agree?", BoardDisplay.TeamDecisionBanner(state));
        }

        [Test]
        public void WhenYouProposed_YouWaitForYourPartner()
        {
            var state = Deciding("turn_order", "confirm", proposer: 912, proposed: 913);

            Assert.IsFalse(BoardDisplay.NeedsTeamConfirmation(state));
            Assert.AreEqual("Waiting for your partner to confirm that BotSage should go next.", BoardDisplay.TeamDecisionStatus(state));
            Assert.AreEqual("Your team is deciding who should go next", BoardDisplay.TeamDecisionBanner(state));
            Assert.IsFalse(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void TheOtherTeamsDecision_IsJustWatched()
        {
            var proposing = Deciding("turn_order", "propose", team: 1);
            var confirming = Deciding("turn_order", "confirm", team: 1, proposer: 914, proposed: 915);

            Assert.AreEqual("Waiting for BotSageQuick or BotSageDeep to choose who should go next.", BoardDisplay.TeamDecisionStatus(proposing));
            Assert.AreEqual("The other team is deciding who should go next", BoardDisplay.TeamDecisionBanner(proposing));
            Assert.AreEqual("Opposing team's turn", BoardDisplay.TeamDecisionTitle(proposing));
            Assert.AreEqual("Waiting for BotSageQuick's team to confirm that BotSageDeep should go next.", BoardDisplay.TeamDecisionStatus(confirming));
        }

        [Test]
        public void ARoundFrozenOnATeamDecision_AllowsNoPlays()
        {
            var state = Deciding("turn_order", "propose", canPropose: true);
            state.You.IsYourTurn = false;

            Assert.IsFalse(BoardDisplay.CanAct(state));
        }

        // --- the opening card pass -------------------------------------------------------------------

        [Test]
        public void ClosedTeamPlay_OpensWithYouPassingTwoCards()
        {
            var state = TeamGame("closed_team");
            state.InitialCardPass = new InitialCardPass { YouSubmitted = false, SubmittedGamePlayerIds = { 913 } };

            Assert.IsTrue(BoardDisplay.NeedsCardPass(state));
            Assert.AreEqual("Choose 2 cards to pass to your partner", BoardDisplay.TurnBanner(state));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void AfterPassing_YouWaitForWhoeverHasnt()
        {
            var state = TeamGame("closed_team");
            state.InitialCardPass = new InitialCardPass { YouSubmitted = true, SubmittedGamePlayerIds = { 912, 913 } };

            Assert.IsFalse(BoardDisplay.NeedsCardPass(state));
            Assert.AreEqual("Waiting for BotSageQuick and BotSageDeep to pass their cards", BoardDisplay.TurnBanner(state));
        }

        // --- what a round ending means -----------------------------------------------------------------

        [Test]
        public void ARoundYourTeamWon_IsYours_AndTheOtherTeamsIsNamed()
        {
            var before = TeamGame();
            var won = Newtonsoft.Json.JsonConvert.DeserializeObject<GameState>(Newtonsoft.Json.JsonConvert.SerializeObject(before));
            won.Round.RoundNumber = before.Round.RoundNumber + 1;
            won.Teams[0].TotalWins = 2;
            var lost = Newtonsoft.Json.JsonConvert.DeserializeObject<GameState>(Newtonsoft.Json.JsonConvert.SerializeObject(before));
            lost.Round.RoundNumber = before.Round.RoundNumber + 1;
            lost.Teams[1].TotalWins = 1;

            var wonCue = BoardCues.Between(before, won).Single();
            var lostCue = BoardCues.Between(before, lost).Single();

            Assert.AreEqual(CueKind.RoundWon, wonCue.Kind);
            Assert.AreEqual("Your team won the round!", wonCue.Text);
            Assert.AreEqual(CueKind.RoundLost, lostCue.Kind);
            Assert.AreEqual("Team 2 (BotSageQuick and BotSageDeep) won the round.", lostCue.Text);
        }

        // --- sending ---------------------------------------------------------------------------------------

        private FakeHttpTransport _transport;
        private BoardSession _session;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _session = BoardSession.ForPlayer(new ApiClient(new ApiConfig("https://example.test"), _transport), 407);
        }

        private JObject Send(System.Func<BoardSession, System.Threading.Tasks.Task<BoardActionResult>> act, string route)
        {
            _transport.Enqueue(200, @"{""status"":""ok""}");
            _transport.Enqueue(200, TestFixtures.Read("game_407_state"));
            Assert.IsTrue(act(_session).GetAwaiter().GetResult().Ok);
            StringAssert.EndsWith(route, _transport.Requests[0].Url);
            return JObject.Parse(_transport.Requests[0].Body);
        }

        [Test]
        public void Proposing_NamesTheSeat()
        {
            var body = Send(s => s.ProposeTeamDecisionAsync(913), "/games/team-decision");

            Assert.AreEqual("propose", (string)body["action"]);
            Assert.AreEqual(913, (int)body["proposed_game_player_id"]);
            Assert.AreEqual(407, (int)body["game_id"]);
        }

        [TestCase(true)]
        [TestCase(false)]
        public void Confirming_SendsAgreeOrDisagree(bool approve)
        {
            var body = Send(s => s.ConfirmTeamDecisionAsync(approve), "/games/team-decision");

            Assert.AreEqual("confirm", (string)body["action"]);
            Assert.AreEqual(approve, (bool)body["approve"]);
        }

        [Test]
        public void PassingCards_SendsTheTwoIds()
        {
            var body = Send(s => s.SubmitInitialPassAsync(new[] { 14216, 14217 }), "/games/initial-pass");

            Assert.AreEqual(new[] { 14216, 14217 }, body["card_ids"].Select(t => (int)t).ToArray());
        }

        [Test]
        public void TeamChat_GoesToTheTeamChannel()
        {
            var body = Send(s => s.SendChatAsync("pass me the red one", "team"), "/games/chat");

            Assert.AreEqual("team", (string)body["channel"]);
            Assert.AreEqual("pass me the red one", (string)body["message_text"]);
        }
    }
}
