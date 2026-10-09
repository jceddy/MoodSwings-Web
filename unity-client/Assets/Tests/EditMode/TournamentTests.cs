using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Tournaments, written the way GET /tournaments and GET /tournaments/state document them (made-up names and
    /// ids): reading them, the display rules, every action, and the New Tournament request.
    /// </summary>
    public class TournamentTests
    {
        private const string InProgress =
            @"{""status"":""ok"",""tournament"":{""id"":7,""name"":""Spring Open"",""bracket_type"":""single_elimination"",""registration_mode"":""open"",
                ""created_by_user_id"":2,""match_params"":{""format"":""duel"",""deck_type"":""custom_duel"",""allow_sideboarding"":false,""best_of_three"":true},
                ""swiss_round_count"":null,""min_participants"":4,""max_participants"":8,""status"":""in_progress"",""winner_user_id"":null},
              ""participants"":[
                {""id"":11,""tournament_id"":7,""user_id"":2,""username"":""bshaftoe"",""status"":""joined"",""seed"":1,""deck_name"":""My Deck"",""draft_pool_card_ids"":null,""current_deck_card_ids"":null},
                {""id"":12,""tournament_id"":7,""user_id"":3,""username"":""Alder"",""status"":""joined"",""seed"":2,""deck_name"":null,""draft_pool_card_ids"":null,""current_deck_card_ids"":null},
                {""id"":13,""tournament_id"":7,""user_id"":4,""username"":""Birch"",""status"":""joined"",""seed"":3,""deck_name"":null,""draft_pool_card_ids"":null,""current_deck_card_ids"":null},
                {""id"":14,""tournament_id"":7,""user_id"":5,""username"":""Cedar"",""status"":""joined"",""seed"":4,""deck_name"":null,""draft_pool_card_ids"":null,""current_deck_card_ids"":null}],
              ""rounds"":[{""id"":70,""bracket"":""single"",""round_number"":1,""status"":""in_progress""},{""id"":71,""bracket"":""single"",""round_number"":2,""status"":""pending""}],
              ""matches_by_round"":{""70"":[
                  {""id"":700,""slot"":1,""participant1_id"":11,""participant2_id"":12,""winner_participant_id"":null,""game_id"":900,""status"":""in_progress""},
                  {""id"":701,""slot"":2,""participant1_id"":13,""participant2_id"":14,""winner_participant_id"":13,""game_id"":901,""status"":""completed""}],
                ""71"":[{""id"":702,""slot"":1,""participant1_id"":null,""participant2_id"":13,""winner_participant_id"":null,""game_id"":null,""status"":""pending""}]},
              ""standings"":null,""pods"":null,""viewer_has_cast_access"":false,""viewer_cast_reveals_hands"":null,""cast_grants"":[]}";

        private const string Lists =
            @"{""status"":""ok"",""tournaments"":[
                {""id"":1,""name"":""Mine"",""bracket_type"":""swiss"",""registration_mode"":""invite_only"",""created_by_user_id"":2,""match_params"":{""format"":""standard"",""deck_type"":""structure""},
                 ""min_participants"":4,""max_participants"":8,""status"":""registration"",""winner_user_id"":null,""my_participant_status"":""joined"",""winner_username"":null,""joined_count"":3},
                {""id"":2,""name"":""Invited"",""bracket_type"":""double_elimination"",""registration_mode"":""invite_only"",""created_by_user_id"":3,""match_params"":{""format"":""duel"",""deck_type"":""custom_duel""},
                 ""min_participants"":4,""max_participants"":8,""status"":""registration"",""winner_user_id"":null,""my_participant_status"":""invited"",""winner_username"":null,""joined_count"":2},
                {""id"":3,""name"":""Gone"",""bracket_type"":""single_elimination"",""registration_mode"":""open"",""created_by_user_id"":3,""match_params"":{""format"":""duel"",""deck_type"":""custom_duel""},
                 ""min_participants"":4,""max_participants"":8,""status"":""registration"",""winner_user_id"":null,""my_participant_status"":""withdrawn"",""winner_username"":null,""joined_count"":2},
                {""id"":4,""name"":""Dead"",""bracket_type"":""single_elimination"",""registration_mode"":""open"",""created_by_user_id"":2,""match_params"":{""format"":""duel"",""deck_type"":""booster_draft""},
                 ""min_participants"":4,""max_participants"":8,""status"":""cancelled"",""winner_user_id"":null,""my_participant_status"":""joined"",""winner_username"":null,""joined_count"":2},
                {""id"":5,""name"":""Done"",""bracket_type"":""single_elimination"",""registration_mode"":""open"",""created_by_user_id"":3,""match_params"":{""format"":""draft"",""deck_type"":""grid_draft_pod_playoff""},
                 ""min_participants"":4,""max_participants"":8,""status"":""completed"",""winner_user_id"":3,""my_participant_status"":""joined"",""winner_username"":""Alder"",""joined_count"":8}]}";

        private FakeHttpTransport _transport;
        private TournamentFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _flow = new TournamentFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        private static TournamentStateResponse State(string json = InProgress) => JsonConvert.DeserializeObject<TournamentStateResponse>(json);

        private static TournamentSummary Summary(int id) =>
            JsonConvert.DeserializeObject<TournamentsResponse>(Lists).Tournaments.Single(t => t.Id == id);

        // --- reading ---------------------------------------------------------------------------------------

        [Test]
        public void TheState_ParsesTheBracket_WithMatchesKeyedByRound()
        {
            var state = State();

            Assert.AreEqual("Spring Open", state.Tournament.Name);
            Assert.AreEqual("custom_duel", state.Tournament.MatchParams.DeckType);
            Assert.AreEqual(4, state.Participants.Count);
            Assert.AreEqual("My Deck", state.Participants[0].DeckName);
            Assert.AreEqual(2, state.Rounds.Count);
            Assert.AreEqual(2, state.MatchesByRound[70].Count);
            Assert.AreEqual(900, state.MatchesByRound[70][0].GameId);
            Assert.IsNull(state.MatchesByRound[71][0].Participant1Id);
            Assert.IsNull(state.Standings);
            Assert.IsNull(state.Pods);
        }

        [Test]
        public void ABracketNotYetMade_ComesAsEmptyArrays()
        {
            var json = JObject.Parse(InProgress);
            json["matches_by_round"] = new JArray();
            json["rounds"] = new JArray();

            var state = State(json.ToString());

            Assert.AreEqual(0, state.MatchesByRound.Count);
            Assert.AreEqual(0, state.Rounds.Count);
        }

        [Test]
        public void TheLists_ParseWithTheViewersOwnSeatAndTheWinner()
        {
            var mine = JsonConvert.DeserializeObject<TournamentsResponse>(Lists).Tournaments;

            Assert.AreEqual("invited", mine[1].MyParticipantStatus);
            Assert.AreEqual("Alder", mine[4].WinnerUsername);
            Assert.AreEqual(3, mine[0].JoinedCount);
        }

        // --- display ---------------------------------------------------------------------------------------

        [Test]
        public void ListedTournaments_AreGroupedAsTheWebPageDoes()
        {
            var all = JsonConvert.DeserializeObject<TournamentsResponse>(Lists).Tournaments;

            CollectionAssert.AreEqual(new[] { 2 }, TournamentDisplay.Invitations(all).Select(t => t.Id).ToArray());
            CollectionAssert.AreEqual(new[] { 1, 5 }, TournamentDisplay.Yours(all).Select(t => t.Id).ToArray(), "not invitations, withdrawn ones or cancelled ones");
            CollectionAssert.AreEqual(new[] { 4 }, TournamentDisplay.Cancelled(all).Select(t => t.Id).ToArray());
        }

        [Test]
        public void ASummary_NamesTheBracketAndWhatIsPlayed()
        {
            Assert.AreEqual("Swiss rounds  -  Traditional  -  Structure", TournamentDisplay.MatchSummary(Summary(1)));
            Assert.AreEqual("Double elimination  -  Power Duel", TournamentDisplay.MatchSummary(Summary(2)));
            Assert.AreEqual("Single elimination  -  Booster Draft", TournamentDisplay.MatchSummary(Summary(4)));
            Assert.AreEqual("Single elimination  -  Grid Draft (Pod Playoffs)", TournamentDisplay.MatchSummary(Summary(5)));
        }

        [Test]
        public void TheListLine_ShowsTheTallyToItsMaker_AndTheWinnerOnceItIsOver()
        {
            Assert.AreEqual("Mine  -  Registration open (3 of 8 joined)", TournamentDisplay.ListLine(Summary(1), myUserId: 2));
            Assert.AreEqual("Mine  -  Registration open", TournamentDisplay.ListLine(Summary(1), myUserId: 9), "only its maker needs the tally");
            Assert.AreEqual("Done  -  Completed (winner: Alder)", TournamentDisplay.ListLine(Summary(5), myUserId: 2));
        }

        [Test]
        public void WhatYouCanDo_FollowsYourSeat_AndTheStage()
        {
            Assert.IsFalse(TournamentDisplay.CanWithdraw(Summary(1), myUserId: 2), "its maker can't withdraw; they cancel it");
            Assert.IsTrue(TournamentDisplay.CanWithdraw(new TournamentSummary { Status = "registration", CreatedByUserId = 3, MyParticipantStatus = "joined" }, myUserId: 2));
            Assert.IsFalse(TournamentDisplay.CanWithdraw(new TournamentSummary { Status = "in_progress", CreatedByUserId = 3, MyParticipantStatus = "joined" }, myUserId: 2));

            var power = new TournamentSummary { Status = "registration", MyParticipantStatus = "joined", MatchParams = new TournamentMatchParams { DeckType = "custom_duel" } };
            Assert.IsTrue(TournamentDisplay.CanEditDeck(power));
            power.Status = "in_progress";
            Assert.IsFalse(TournamentDisplay.CanEditDeck(power), "the deck is locked once it starts");
        }

        [Test]
        public void StartAndCancel_AreForTheMaker_AtTheRightTimes()
        {
            var state = State();
            state.Tournament.Status = "registration";

            Assert.IsTrue(TournamentDisplay.CanStart(state, myUserId: 2), "four have joined and four are needed");
            Assert.IsFalse(TournamentDisplay.CanStart(state, myUserId: 3), "only its maker");
            state.Participants[3].Status = "withdrawn";
            Assert.IsFalse(TournamentDisplay.CanStart(state, myUserId: 2), "three of four");

            Assert.IsTrue(TournamentDisplay.CanCancel(state, myUserId: 2));
            state.Tournament.Status = "in_progress";
            Assert.IsTrue(TournamentDisplay.CanCancel(state, myUserId: 2));
            state.Tournament.Status = "completed";
            Assert.IsFalse(TournamentDisplay.CanCancel(state, myUserId: 2));
        }

        [Test]
        public void AMatch_ReadsAsWhoVersusWho_AndHowItWent()
        {
            var state = State();
            var names = TournamentDisplay.Names(state.Participants);

            Assert.AreEqual("bshaftoe vs Alder  -  in progress", TournamentDisplay.MatchLabel(state.MatchesByRound[70][0], names));
            Assert.AreEqual("Birch vs Cedar  -  Birch won", TournamentDisplay.MatchLabel(state.MatchesByRound[70][1], names));
            Assert.AreEqual("BYE vs Birch  -  waiting", TournamentDisplay.MatchLabel(state.MatchesByRound[71][0], names));
            var bye = new TournamentMatch { Participant1Id = 11, Participant2Id = null, WinnerParticipantId = 11, Status = "bye" };
            Assert.AreEqual("bshaftoe vs BYE  -  bshaftoe won", TournamentDisplay.MatchLabel(bye, names));
        }

        [Test]
        public void RoundHeadings_NameTheBracketTheyBelongTo()
        {
            Assert.AreEqual("Round 2", TournamentDisplay.RoundHeading(new TournamentRound { Bracket = "single", RoundNumber = 2 }));
            Assert.AreEqual("Winners round 1", TournamentDisplay.RoundHeading(new TournamentRound { Bracket = "winners", RoundNumber = 1 }));
            Assert.AreEqual("Losers round 3", TournamentDisplay.RoundHeading(new TournamentRound { Bracket = "losers", RoundNumber = 3 }));
            Assert.AreEqual("Grand final 1", TournamentDisplay.RoundHeading(new TournamentRound { Bracket = "grand_final", RoundNumber = 1 }));
        }

        [Test]
        public void YourLiveMatch_IsTheOneInProgressThatYouAreIn()
        {
            var state = State();

            Assert.AreEqual(900, TournamentDisplay.MyLiveMatch(state, myUserId: 2).GameId);
            Assert.AreEqual(900, TournamentDisplay.MyLiveMatch(state, myUserId: 3).GameId, "either side");
            Assert.IsNull(TournamentDisplay.MyLiveMatch(state, myUserId: 4), "Birch's match is done");
            Assert.IsNull(TournamentDisplay.MyLiveMatch(state, myUserId: 99), "not in it at all");
        }

        [Test]
        public void SwissStandings_ListBestFirst_FromAMapKeyedByParticipant()
        {
            var json = JObject.Parse(InProgress);
            json["tournament"]["bracket_type"] = "swiss";
            json["standings"] = new JObject
            {
                ["13"] = new JObject { ["wins"] = 2, ["buchholz"] = 3 },
                ["11"] = new JObject { ["wins"] = 1, ["buchholz"] = 4 },
            };

            var lines = TournamentDisplay.StandingLines(State(json.ToString()));

            CollectionAssert.AreEqual(new[] { "1. Birch  -  2 wins", "2. bshaftoe  -  1 win" }, lines);
        }

        [Test]
        public void Pods_ReadWithTheirSeatsAndHowFarAlongTheyAre()
        {
            var json = JObject.Parse(InProgress);
            json["tournament"]["status"] = "drafting";
            json["pods"] = new JArray(
                new JObject
                {
                    ["pod_number"] = 1, ["kind"] = "regular", ["status"] = "drafting", ["current_round"] = 4, ["game_id"] = null,
                    ["seats"] = new JArray(
                        new JObject { ["participant_id"] = 12, ["username"] = "Alder", ["seat_order"] = 1 },
                        new JObject { ["participant_id"] = 11, ["username"] = "bshaftoe", ["seat_order"] = 0 }),
                    ["winner_username"] = null, ["bracket_rounds"] = new JArray(),
                },
                new JObject
                {
                    ["pod_number"] = 2, ["kind"] = "final", ["status"] = "completed", ["current_round"] = 1, ["game_id"] = 55,
                    ["seats"] = new JArray(new JObject { ["participant_id"] = 13, ["username"] = "Birch", ["seat_order"] = 0 }),
                    ["winner_username"] = "Birch",
                    ["bracket_rounds"] = new JArray(new JObject { ["id"] = 80, ["bracket"] = "single", ["round_number"] = 1, ["matches"] = new JArray() }),
                });

            var state = State(json.ToString());

            Assert.AreEqual("Pod 1 (round 4/15): bshaftoe, Alder", TournamentDisplay.PodLine(state.Pods[0]));
            Assert.AreEqual("Finals (winner: Birch): Birch", TournamentDisplay.PodLine(state.Pods[1]));
            Assert.AreEqual(1, state.Pods[1].BracketRounds.Count);
            Assert.AreSame(state.Pods[0], TournamentDisplay.MyDraftingPod(state, "bshaftoe"));
            Assert.IsNull(TournamentDisplay.MyDraftingPod(state, "Birch"), "that pod has finished");
        }

        // --- fetching and acting ---------------------------------------------------------------------------

        [Test]
        public void RefreshingTheLists_FetchesYoursAndTheOpenOnes()
        {
            _transport.Enqueue(200, Lists);
            _transport.Enqueue(200, @"{""status"":""ok"",""tournaments"":[{""id"":9,""name"":""Open"",""bracket_type"":""swiss"",""registration_mode"":""open"",""created_by_user_id"":3,""match_params"":{""format"":""duel"",""deck_type"":""custom_duel""},""min_participants"":4,""max_participants"":8,""status"":""registration"",""creator_username"":""Alder"",""joined_count"":2}]}");

            Assert.IsTrue(_flow.RefreshListsAsync().GetAwaiter().GetResult().Ok);

            Assert.IsTrue(_flow.Loaded);
            Assert.AreEqual(5, _flow.Mine.Count);
            Assert.AreEqual("Alder: Open", TournamentDisplay.OpenLine(_flow.Open[0]));
            Assert.IsTrue(_transport.Requests.Any(r => r.Url.EndsWith("/app/tournaments?mine=1")));
            Assert.IsTrue(_transport.Requests.Any(r => r.Url.EndsWith("/app/tournaments")));
        }

        [Test]
        public void IfTheOpenListFails_YoursStillShow()
        {
            _transport.Enqueue(200, Lists);
            _transport.EnqueueNetworkError("down");

            var result = _flow.RefreshListsAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(5, _flow.Mine.Count);
            Assert.AreEqual(0, _flow.Open.Count);
        }

        [Test]
        public void IfYourListFails_ItSaysSo_AndKeepsWhatWasThere()
        {
            _transport.Enqueue(200, Lists);
            _transport.Enqueue(200, @"{""status"":""ok"",""tournaments"":[]}");
            _flow.RefreshListsAsync().GetAwaiter().GetResult();

            _transport.EnqueueNetworkError("down");
            _transport.Enqueue(200, @"{""status"":""ok"",""tournaments"":[]}");
            var result = _flow.RefreshListsAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
            Assert.AreEqual(5, _flow.Mine.Count);
        }

        [Test]
        public void TheState_IsFetchedById_AndHeldAsTheCurrentTournament()
        {
            _transport.Enqueue(200, InProgress);

            Assert.IsTrue(_flow.RefreshStateAsync(7).GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/tournaments/state?id=7", _transport.LastRequest.Url);
            Assert.AreEqual("Spring Open", _flow.Current.Tournament.Name);
            _flow.ForgetCurrent();
            Assert.IsNull(_flow.Current);
        }

        [Test]
        public void JoiningAPowerDuel_NeedsADeck_AndSendsIt()
        {
            var power = Summary(2);

            var without = _flow.JoinAsync(power).GetAwaiter().GetResult();
            Assert.IsFalse(without.Ok);
            StringAssert.Contains("Choose a deck", without.Message);
            Assert.AreEqual(0, _transport.Requests.Count, "nothing is sent without one");

            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            var with = _flow.JoinAsync(power, savedDecklistId: 31).GetAwaiter().GetResult();
            Assert.IsTrue(with.Ok);
            Assert.AreEqual("https://example.test/app/tournaments/join", _transport.LastRequest.Url);
            var body = JObject.Parse(_transport.LastRequest.Body);
            Assert.AreEqual(2, (int)body["tournament_id"]);
            Assert.AreEqual(31, (int)body["saved_decklist_id"]);
        }

        [Test]
        public void JoiningAnythingElse_SendsNoDeck_EvenIfOneIsGiven()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            Assert.IsTrue(_flow.AcceptAsync(Summary(1), savedDecklistId: 31).GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/tournaments/accept-invite", _transport.LastRequest.Url);
            Assert.IsNull(JObject.Parse(_transport.LastRequest.Body)["saved_decklist_id"]);
        }

        [Test]
        public void ChangingYourDeck_PostsItToSubmitDeck()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            Assert.IsTrue(_flow.SubmitDeckAsync(Summary(2), 44).GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/tournaments/submit-deck", _transport.LastRequest.Url);
            Assert.AreEqual(44, (int)JObject.Parse(_transport.LastRequest.Body)["saved_decklist_id"]);
        }

        [TestCase("Decline", "decline-invite")]
        [TestCase("Withdraw", "withdraw")]
        [TestCase("Start", "start")]
        [TestCase("Cancel", "cancel")]
        public void TheSimpleActions_PostTheTournamentId(string action, string route)
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            var tournament = Summary(2);
            var task = action == "Decline" ? _flow.DeclineAsync(tournament)
                : action == "Withdraw" ? _flow.WithdrawAsync(tournament)
                : action == "Start" ? _flow.StartAsync(tournament.Id)
                : _flow.CancelAsync(tournament.Id);

            Assert.IsTrue(task.GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/tournaments/" + route, _transport.LastRequest.Url);
            Assert.AreEqual(2, (int)JObject.Parse(_transport.LastRequest.Body)["tournament_id"]);
        }

        [Test]
        public void ARefusal_ComesBackAsTheServersMessage()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"The tournament is full.\"}");

            var result = _flow.JoinAsync(Summary(1)).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("The tournament is full.", result.Message);
        }

        [Test]
        public void CreatingOne_ReturnsTheNewId()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"tournament_id\":12}");
            var setup = new TournamentSetup { Name = "Fresh", FormatId = TournamentSetup.Traditional };

            var result = _flow.CreateAsync(setup.ToBody()).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(12, result.TournamentId);
            Assert.AreEqual("https://example.test/app/tournaments", _transport.LastRequest.Url);
        }

        [Test]
        public void ThePodDraft_ParsesTheTwoBoosters_AndAPickIsFollowedByARefresh()
        {
            var catalog = JsonConvert.DeserializeObject<CatalogResponse>(TestFixtures.Read("cards_catalog")).Cards;
            string Cards(int skip, int take) => JsonConvert.SerializeObject(catalog.Skip(skip).Take(take));
            _transport.Enqueue(200, "{\"status\":\"ok\",\"pod_status\":\"drafting\",\"current_round\":2,\"total_rounds\":15,\"pod_size\":8,\"drafted_cards\":" + Cards(0, 2) + ",\"left\":" + Cards(2, 5) + ",\"right\":" + Cards(7, 5) + "}");
            _flow.RefreshPodAsync(7).GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/tournaments/pod-draft/state?tournament_id=7", _transport.LastRequest.Url);
            Assert.AreEqual(2, _flow.Pod.CurrentRound);
            Assert.AreEqual(5, _flow.Pod.Left.Count);
            Assert.AreEqual(2, _flow.Pod.DraftedCards.Count);

            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, "{\"status\":\"ok\",\"pod_status\":\"drafting\",\"current_round\":2,\"total_rounds\":15,\"pod_size\":8,\"drafted_cards\":" + Cards(0, 3) + ",\"left\":null,\"right\":" + Cards(7, 5) + "}");
            var pick = _flow.PickAsync(7, "left", _flow.Pod.Left[0]).GetAwaiter().GetResult();

            Assert.IsTrue(pick.Ok);
            var post = _transport.Requests[_transport.Requests.Count - 2];
            Assert.AreEqual("https://example.test/app/tournaments/pod-draft/pick", post.Url);
            var body = JObject.Parse(post.Body);
            Assert.AreEqual("left", (string)body["direction"]);
            Assert.AreEqual(catalog[2].CardId, (int)body["card_id"]);
            Assert.IsNull(_flow.Pod.Left, "taken this round");
            Assert.AreEqual(3, _flow.Pod.DraftedCards.Count);
        }

        [Test]
        public void LoggingOut_ForgetsTheTournaments()
        {
            _transport.Enqueue(200, Lists);
            _transport.Enqueue(200, @"{""status"":""ok"",""tournaments"":[]}");
            _flow.RefreshListsAsync().GetAwaiter().GetResult();

            _flow.Clear();

            Assert.IsFalse(_flow.Loaded);
            Assert.AreEqual(0, _flow.Mine.Count);
        }

        // --- making one ------------------------------------------------------------------------------------

        [Test]
        public void ANewSetup_NeedsAName_AndAPowerDuelADeck()
        {
            var setup = new TournamentSetup();
            StringAssert.Contains("name", setup.Problem());

            setup.Name = "Spring Open";
            StringAssert.Contains("deck", setup.Problem());

            setup.SavedDecklistId = 5;
            Assert.IsNull(setup.Problem());
        }

        [Test]
        public void PowerDuel_IsADuelOfCustomDecks_WithYourDeck()
        {
            var setup = new TournamentSetup { Name = " Spring Open ", FormatId = TournamentSetup.PowerDuel, SavedDecklistId = 5, AllowSideboarding = true, MaxParticipants = 6 };

            var body = setup.ToBody();

            Assert.AreEqual("Spring Open", body["name"]);
            Assert.AreEqual("duel", body["format"]);
            Assert.AreEqual("custom_duel", body["deck_type"]);
            Assert.AreEqual("power", ((System.Collections.Generic.Dictionary<string, object>)body["duel_deck_rules"])["preset"]);
            Assert.AreEqual(5, body["saved_decklist_id"]);
            Assert.AreEqual(true, body["allow_sideboarding"]);
            Assert.AreEqual(true, body["best_of_three"]);
            Assert.AreEqual("single_elimination", body["bracket_type"]);
            Assert.AreEqual("open", body["registration_mode"]);
            Assert.AreEqual(4, body["min_participants"]);
            Assert.AreEqual(6, body["max_participants"]);
        }

        [TestCase(TournamentSetup.Traditional, "standard", "structure")]
        [TestCase(TournamentSetup.SealedDeck, "draft", "sealed_deck")]
        [TestCase(TournamentSetup.BoosterDraft, "duel", "booster_draft")]
        public void EachFormat_FixesItsOwnGameFormatAndDeckType_AndSendsNoDeck(string id, string format, string deckType)
        {
            var setup = new TournamentSetup { Name = "T", FormatId = id, SavedDecklistId = 5, AllowSideboarding = true };

            var body = setup.ToBody();

            Assert.AreEqual(format, body["format"]);
            Assert.AreEqual(deckType, body["deck_type"]);
            Assert.IsFalse(body.ContainsKey("saved_decklist_id"), "only a Power Duel has one");
            Assert.IsFalse(body.ContainsKey("duel_deck_rules"));
            Assert.IsFalse(body.ContainsKey("allow_sideboarding"));
            Assert.IsNull(setup.Problem());
        }

        [TestCase("grid_draft")]
        [TestCase("grid_draft_pod")]
        [TestCase("grid_draft_pod_playoff")]
        public void GridDraft_SendsItsModeAsTheDeckType_WithARandomPool(string mode)
        {
            var setup = new TournamentSetup { Name = "T", FormatId = TournamentSetup.GridDraft, GridMode = mode, BracketType = "swiss" };

            var body = setup.ToBody();

            Assert.AreEqual("draft", body["format"]);
            Assert.AreEqual(mode, body["deck_type"]);
            Assert.AreEqual("random_48", body["grid_draft_pool_source"]);
            Assert.AreEqual(mode == "grid_draft_pod_playoff" ? "single_elimination" : "swiss", body["bracket_type"], "pod playoffs are always single elimination");
        }

        [Test]
        public void AnInviteOnlyTournament_NeedsEnoughInvitesToFillIt()
        {
            var setup = new TournamentSetup { Name = "T", FormatId = TournamentSetup.Traditional, Registration = TournamentSetup.InviteOnly, MaxParticipants = 4 };
            StringAssert.Contains("Invite at least 3", setup.Problem());

            setup.InviteUserIds.AddRange(new[] { 3, 4, 5 });
            Assert.IsNull(setup.Problem());
            CollectionAssert.AreEqual(new[] { 3, 4, 5 }, (int[])setup.ToBody()["invite_user_ids"]);

            setup.Registration = TournamentSetup.Open;
            CollectionAssert.IsEmpty((int[])setup.ToBody()["invite_user_ids"], "invites are only for invite-only events");
        }

        [Test]
        public void PlayerCounts_StayWithinFourToSixteen_AndMinNeverExceedsMax()
        {
            var setup = new TournamentSetup { MinParticipants = 2, MaxParticipants = 40 };
            setup.Normalize();
            Assert.AreEqual(4, setup.MinParticipants);
            Assert.AreEqual(16, setup.MaxParticipants);

            setup.MaxParticipants = 5;
            setup.MinParticipants = 9;
            setup.Normalize();
            Assert.AreEqual(5, setup.MinParticipants);
        }

        [Test]
        public void TheSummaryLine_SaysWhatYouAreMaking()
        {
            var setup = new TournamentSetup { FormatId = TournamentSetup.GridDraft, GridMode = "grid_draft_pod_playoff", MaxParticipants = 12 };

            Assert.AreEqual("Grid Draft (Pods with playoffs)  -  Single elimination  -  4 to 12 players", setup.Summary());
        }
    }
}
