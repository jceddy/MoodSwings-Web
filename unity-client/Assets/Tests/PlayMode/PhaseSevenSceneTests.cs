using System.Collections;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The extra play modes on the board, against the same scripted server and captured games as
    /// the earlier phases: best-of-three matches here, team play and puzzles as they arrive.
    /// </summary>
    public class PhaseSevenSceneTests
    {
        private static PhaseFiveSceneTests.PlayServer Serve(JObject state) => new PhaseFiveSceneTests.PlayServer { State = state };

        /// <summary>Game 405 (two players, you and BotSage) as game 2 of a match, with whatever else a test edits.</summary>
        private static JObject MatchGame(int yourWins, int theirWins, System.Action<JObject> edit = null) =>
            PhaseFiveSceneTests.Load(405, s =>
            {
                s["round"]["pending_decision"] = null;
                s["game"]["match_game_number"] = 2;
                s["game_match"] = JObject.Parse(
                    "{\"status\":\"in_progress\",\"your_wins\":" + yourWins + ",\"opponent_wins\":" + theirWins + ",\"games_to_win\":2," +
                    "\"winner_usernames\":[],\"players\":[{\"user_id\":2,\"username\":\"bshaftoe\",\"wins\":" + yourWins + ",\"is_you\":true}," +
                    "{\"user_id\":18,\"username\":\"BotSage\",\"wins\":" + theirWins + ",\"is_you\":false}],\"next_game_id\":null}");
                edit?.Invoke(s);
            });

        private static BoardScreen Board() => PhaseFiveSceneTests.Board();

        private static Button Named(string name) => PhaseFiveSceneTests.ButtonNamed(name);

        // --- best of three ------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator AMatchGame_SaysWhichGameItIsAndTheScore()
        {
            var server = Serve(MatchGame(1, 0));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            StringAssert.Contains("Game 2  -  Match: you 1 - 0 BotSage", Board().RoundText);
        }

        private static JObject Deciding(bool youLost) => MatchGame(youLost ? 1 : 0, youLost ? 0 : 1, s =>
        {
            s["first_player_decision"] = JObject.Parse("{\"you_are_previous_loser\":" + (youLost ? "true" : "false") + ",\"default_user_id\":" + (youLost ? 18 : 2) + "}");
            s["round"]["current_turn_game_player_id"] = null;
            s["you"]["is_your_turn"] = false;
        });

        [UnityTest]
        public IEnumerator TheLoserOfTheLastGame_IsAskedWhoGoesFirst()
        {
            var server = Serve(Deciding(youLost: true));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsTrue(Board().FirstPlayerChoiceOpen);
            Assert.AreEqual("Choose who goes first", Board().BannerText);
            Assert.IsNotNull(Named("Go first"));
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "Let BotSage go first"));
            ScreenshotHelper.Capture("match-first-player-choice");
        }

        [UnityTest]
        public IEnumerator GoingFirst_IsSentAsPlayFirstTrue_AndTheQuestionGoesAway()
        {
            var server = Serve(Deciding(youLost: true));
            server.OnPost = (path, body) =>
            {
                server.State = MatchGame(1, 0, s => s["round"]["current_turn_game_player_id"] = 907);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Go first"));

            var post = server.Posts("/games/draft/first-player-choice").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.IsTrue((bool)post["play_first"]);
            Assert.IsFalse(Board().FirstPlayerChoiceOpen);
        }

        [UnityTest]
        public IEnumerator LettingTheWinnerGoFirst_IsSentAsPlayFirstFalse()
        {
            var server = Serve(Deciding(youLost: true));
            server.OnPost = (path, body) =>
            {
                server.State = MatchGame(1, 0, s => s["round"]["current_turn_game_player_id"] = 908);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Let them go first"));

            Assert.IsFalse((bool)server.Posts("/games/draft/first-player-choice").Single()["play_first"]);
        }

        [UnityTest]
        public IEnumerator TheChoiceCantBeDismissed_BackLeavesTheBoardInstead()
        {
            var server = Serve(Deciding(youLost: true));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsFalse(Board().HandleBack(), "Back isn't an answer; it leaves the screen");
            Assert.AreEqual(0, server.Posts("/games/draft/first-player-choice").Count());
        }

        [UnityTest]
        public IEnumerator TheWinnerOfTheLastGame_JustSeesWhoIsChoosing()
        {
            var server = Serve(Deciding(youLost: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsFalse(Board().FirstPlayerChoiceOpen);
            Assert.AreEqual("Waiting for BotSage to choose who goes first", Board().BannerText);
            Assert.IsFalse(PhaseFiveSceneTests.ButtonNamed("Primary action").interactable, "nothing to do until they choose");
        }

        [UnityTest]
        public IEnumerator WhenAGameEndsWithTheMatchOn_ANextGameButtonTakesYouThere()
        {
            var finished = MatchGame(1, 1, s =>
            {
                s["game"]["status"] = "completed";
                s["game"]["winner_usernames"] = new JArray("bshaftoe");
                s["game_match"]["next_game_id"] = 777;
            });
            var next = PhaseFiveSceneTests.Load(406);
            var server = Serve(finished);
            server.StateFor = id => id == 777 ? next : finished;
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("You won!", Board().BannerText);
            Assert.AreEqual("Next game", PhaseFiveSceneTests.Child("Primary action").GetComponentInChildren<Text>().text);
            Assert.IsNull(PhaseFiveSceneTests.Child("Resign"), "the game is over");
            ScreenshotHelper.Capture("match-next-game");

            yield return PhaseFiveSceneTests.Tap(Named("Primary action"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=777")), string.Join("\n", server.Calls));
            Assert.AreEqual("Your turn", Board().BannerText, "the next game's board");
        }

        [UnityTest]
        public IEnumerator ADecidedMatch_IsAnnounced_WithNoNextGame()
        {
            var server = Serve(MatchGame(2, 0, s =>
            {
                s["game"]["status"] = "completed";
                s["game"]["winner_usernames"] = new JArray("bshaftoe");
                s["game_match"]["status"] = "completed";
                s["game_match"]["winner_usernames"] = new JArray("bshaftoe");
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("You won the match!", Board().BannerText);
            Assert.IsNull(PhaseFiveSceneTests.Child("Primary action"));
        }

        // --- team play --------------------------------------------------------------------------------------

        /// <summary>
        /// The captured four-player game (you are bshaftoe, 912; BotSage 913 is your partner) turned into a team game.
        /// No team game was captured, so the team fields are written as the server sends them.
        /// </summary>
        private static JObject TeamGame(string format, System.Action<JObject> edit = null) => PhaseFiveSceneTests.Load(407, s =>
        {
            s["round"]["pending_decision"] = null;
            s["game"]["format"] = format;
            var players = (JArray)s["players"];
            for (var i = 0; i < 4; i++)
            {
                players[i]["team_id"] = i < 2 ? 0 : 1;
            }

            s["you"]["teammate_game_player_id"] = 913;
            s["teams"] = JArray.Parse(
                "[{\"team_id\":0,\"game_player_ids\":[912,913],\"total_score\":6,\"total_wins\":1}," +
                "{\"team_id\":1,\"game_player_ids\":[914,915],\"total_score\":4,\"total_wins\":0}]");
            edit?.Invoke(s);
        });

        private static void Deciding(JObject s, string phase, bool canPropose, bool canConfirm, int? proposer = null, int? proposed = null)
        {
            s["round"]["current_turn_game_player_id"] = null;
            s["you"]["is_your_turn"] = false;
            s["team_decision"] = JObject.Parse(
                "{\"decision_type\":\"turn_order\",\"team_id\":0,\"phase\":\"" + phase + "\",\"candidate_game_player_ids\":[912,913]," +
                "\"proposer_game_player_id\":" + (proposer.HasValue ? proposer.ToString() : "null") +
                ",\"proposed_game_player_id\":" + (proposed.HasValue ? proposed.ToString() : "null") +
                ",\"can_propose\":" + (canPropose ? "true" : "false") + ",\"can_confirm\":" + (canConfirm ? "true" : "false") + "}");
        }

        [UnityTest]
        public IEnumerator ATeamBoard_MarksPartnerAndOpponents_AndShowsTheTeamScores()
        {
            var server = Serve(TeamGame("team"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.IsTrue(texts.Contains("BotSage  (bot)  (your teammate)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("BotSageQuick  (bot)  (opponent)"));
            Assert.IsTrue(texts.Contains("bshaftoe  (you)"));
            StringAssert.Contains("Your team 6 pts, 1 win  -  Opposing team 4 pts, 0 wins", Board().RoundText);
            ScreenshotHelper.Capture("team-board");
        }

        [UnityTest]
        public IEnumerator YouMayNameWhoOnYourTeamGoesNext_AndTheProposalIsSent()
        {
            var server = Serve(TeamGame("team", s => Deciding(s, "propose", canPropose: true, canConfirm: false)));
            server.OnPost = (path, body) =>
            {
                server.State = TeamGame("team", s => Deciding(s, "confirm", false, false, proposer: 912, proposed: 913));
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            Assert.IsTrue(Board().TeamDecisionOpen);
            Assert.AreEqual("Choose who should go next", Board().BannerText);
            Assert.IsNotNull(Named("Propose Me"));
            ScreenshotHelper.Capture("team-propose");

            yield return PhaseFiveSceneTests.Tap(Named("Propose BotSage"));

            var post = server.Posts("/games/team-decision").Single();
            Assert.AreEqual("propose", (string)post["action"]);
            Assert.AreEqual(913, (int)post["proposed_game_player_id"]);
            Assert.IsFalse(Board().TeamDecisionOpen, "now it's your partner's turn to answer");
            Assert.AreEqual("Your team is deciding who should go next", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator YourPartnersProposal_CanBeAgreedTo_OrSentBack()
        {
            JObject Proposed() => TeamGame("team", s => Deciding(s, "confirm", canPropose: false, canConfirm: true, proposer: 913, proposed: 912));
            var server = Serve(Proposed());
            server.OnPost = (path, body) =>
            {
                server.State = PhaseFiveSceneTests.Load(407, s => s["round"]["pending_decision"] = null);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            Assert.IsTrue(Board().TeamDecisionOpen);
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude)
                .Any(t => t.text == "BotSage proposed bshaftoe to go next. Do you agree?"));

            yield return PhaseFiveSceneTests.Tap(Named("Disagree"));
            Assert.IsFalse((bool)server.Posts("/games/team-decision").Single()["approve"]);

            // It comes back to be proposed again; agreeing is the other button.
            server.State = Proposed();
            yield return PhaseFiveSceneTests.Poll();
            yield return PhaseFiveSceneTests.Tap(Named("Agree"));
            Assert.IsTrue((bool)server.Posts("/games/team-decision").Last()["approve"]);
        }

        [UnityTest]
        public IEnumerator TheOtherTeamsDecision_IsOnlyAnnounced()
        {
            var server = Serve(TeamGame("team", s =>
            {
                Deciding(s, "propose", canPropose: false, canConfirm: false);
                s["team_decision"]["team_id"] = 1;
                s["team_decision"]["candidate_game_player_ids"] = new JArray(914, 915);
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            Assert.IsFalse(Board().TeamDecisionOpen);
            Assert.AreEqual("The other team is deciding who should go next", Board().BannerText);
            Assert.IsFalse(PhaseFiveSceneTests.ButtonNamed("Primary action").interactable, "the round is frozen");
        }

        [UnityTest]
        public IEnumerator InOpenTeamPlay_YouCanReadYourPartnersHand()
        {
            var server = Serve(TeamGame("team", s => s["you"]["teammate_hand"] = JArray.Parse(
                "[{\"card_id\":8001,\"catalog_card_id\":1,\"name\":\"Calm\",\"color\":\"white\",\"base_color\":\"white\",\"value\":0,\"base_value\":0,\"suppressions\":[],\"choice_fields\":[]}," +
                "{\"card_id\":8002,\"catalog_card_id\":2,\"name\":\"Dread\",\"color\":\"black\",\"base_color\":\"black\",\"value\":3,\"base_value\":3,\"suppressions\":[],\"choice_fields\":[]}]")));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            yield return PhaseFiveSceneTests.Tap(Named("Partner hand button"));

            Assert.IsTrue(Board().TeammateHandOpen);
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "BotSage's hand"));
            var shown = UnityEngine.Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).Where(t => t.name == "Card Calm" || t.name == "Card Dread").ToList();
            Assert.AreEqual(2, shown.Count);
            ScreenshotHelper.Capture("team-partner-hand");

            Assert.IsTrue(Board().HandleBack());
            Assert.IsFalse(Board().TeammateHandOpen);
        }

        [UnityTest]
        public IEnumerator InClosedTeamPlay_ThereIsNoPartnerHandToLookAt()
        {
            var server = Serve(TeamGame("closed_team"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            Assert.IsNull(Named("Partner hand button"));
        }

        [UnityTest]
        public IEnumerator ClosedTeamPlay_OpensWithPassingTwoCardsToYourPartner()
        {
            JObject Passing(bool submitted) => TeamGame("closed_team", s =>
            {
                s["round"]["current_turn_game_player_id"] = null;
                s["you"]["is_your_turn"] = false;
                s["initial_card_pass"] = JObject.Parse("{\"you_submitted\":" + (submitted ? "true" : "false") + ",\"submitted_game_player_ids\":" + (submitted ? "[912,913]" : "[913]") + "}");
            });
            var server = Serve(Passing(false));
            server.OnPost = (path, body) =>
            {
                server.State = Passing(true);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);

            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.AreEqual("Pass 2 cards to your partner", Board().Choices.TitleText);
            Assert.IsFalse(Board().Choices.Cancellable);
            Assert.IsFalse(Board().Choices.SubmitEnabled, "exactly two cards must be chosen");
            ScreenshotHelper.Capture("team-card-pass");

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Option Loyalty (white, 3)").GetComponent<Button>());
            Assert.IsFalse(Board().Choices.SubmitEnabled, "one isn't enough");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Option Cheer (green, 3)").GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Option Patience (white, 5)").GetComponent<Button>());
            StringAssert.Contains("exactly 2", Board().Choices.ProblemText);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Option Patience (white, 5)").GetComponent<Button>());
            Assert.IsTrue(Board().Choices.SubmitEnabled);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Submit"));

            var post = server.Posts("/games/initial-pass").Single();
            Assert.AreEqual(new[] { 14216, 14220 }, post["card_ids"].Select(t => (int)t).OrderBy(i => i).ToArray());
            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.AreEqual("Waiting for BotSageQuick and BotSageDeep to pass their cards", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator InOpenTeamPlay_ChatCanGoToJustYourPartner()
        {
            var server = Serve(TeamGame("team"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);
            yield return PhaseFiveSceneTests.Tap(PhaseTwoSceneTests.FindButton("Chat"));
            Assert.AreEqual("table", Board().ChatChannel);

            yield return PhaseFiveSceneTests.Tap(Named("Chat to partner"));
            Assert.AreEqual("team", Board().ChatChannel);
            PhaseFiveSceneTests.Child("ChatEntry").GetComponentInChildren<InputField>().text = "keep the red one";
            yield return PhaseFiveSceneTests.Tap(Named("Send chat"));

            var post = server.Posts("/games/chat").Single();
            Assert.AreEqual("team", (string)post["channel"]);
            Assert.AreEqual("keep the red one", (string)post["message_text"]);
        }

        [UnityTest]
        public IEnumerator InClosedTeamPlay_ChatOnlyGoesToTheTable()
        {
            var server = Serve(TeamGame("closed_team"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);
            yield return PhaseFiveSceneTests.Tap(PhaseTwoSceneTests.FindButton("Chat"));

            Assert.IsNull(Named("Chat to partner"), "that format keeps information closed between partners");
        }

        [UnityTest]
        public IEnumerator ATeamChatMessage_IsMarkedAsTeamOnly()
        {
            var server = Serve(TeamGame("team", s => s["chat_messages"] = JArray.Parse(
                "[{\"id\":1,\"sender_username\":\"BotSage\",\"channel\":\"team\",\"message_text\":\"psst\",\"created_at\":\"2026-01-01 00:00:00\"}]")));
            yield return PhaseFiveSceneTests.OpenBoard(server, 407);
            yield return PhaseFiveSceneTests.Tap(PhaseTwoSceneTests.FindButton("Chat (1)"));

            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text == "[team] BotSage: psst"));
        }

        // --- polish: lists and buttons -------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator ACardList_GroupsEachPlayersCardsTogether_YoursFirst()
        {
            // The opponent's mood entered play first, so it comes first in the server's list.
            var server = Serve(PhaseFiveSceneTests.Load(406, s =>
            {
                var inPlay = (JArray)s["in_play"];
                var first = inPlay[0];
                inPlay.RemoveAt(0);
                inPlay.Add(first); // Frustration (BotSageQuick's), then Ambition (yours)
                inPlay.Insert(0, JObject.Parse("{\"card_id\":97001,\"catalog_card_id\":1,\"name\":\"Extra\",\"color\":\"red\",\"base_color\":\"red\"," +
                    "\"value\":2,\"base_value\":2,\"owner_game_player_id\":911,\"suppressions\":[],\"choice_fields\":[]}"));
                s["you"]["hand"][0]["choice_fields"] = JArray.Parse(
                    "[{\"key\":\"target_mood_id\",\"type\":\"mood\",\"scope\":\"any\",\"required\":false,\"label\":\"A mood\"}]");
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card Superiority").GetComponent<Button>());

            float Y(string startsWith) =>
                UnityEngine.Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude).Single(b => b.name.StartsWith("Option " + startsWith)).transform.position.y;
            var ambition = Y("Ambition");
            var extra = Y("Extra");
            var frustration = Y("Frustration");
            Assert.Greater(ambition, extra, "your own mood is listed before the opponent's");
            Assert.Greater(extra, frustration, "and an opponent's two moods sit together, in the order they entered play");

            var headers = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude)
                .Where(t => t.text == "bshaftoe" || t.text == "BotSageQuick").ToList();
            Assert.AreEqual(2, headers.Count, "each player is named once");
        }

        // --- puzzles ----------------------------------------------------------------------------------------

        private const string TwoPuzzles =
            "{\"status\":\"ok\",\"puzzles\":[" +
            "{\"id\":1,\"slug\":\"first\",\"title\":\"First Test Puzzle\",\"description\":\"Do the first thing.\",\"difficulty\":\"easy\",\"max_plays\":null,\"solved\":true,\"best_plays\":2,\"solve_count\":3}," +
            "{\"id\":2,\"slug\":\"second\",\"title\":\"Second Test Puzzle\",\"description\":\"Do the second thing.\",\"difficulty\":\"hard\",\"max_plays\":3,\"solved\":false,\"best_plays\":null,\"solve_count\":0}]}";

        private static IEnumerator OpenPuzzles(PhaseFiveSceneTests.PlayServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Click("Puzzles");
            yield return MainSceneTests.WaitFor<PuzzlesScreen>();
        }

        private static JObject PuzzleBoard(System.Action<JObject> edit = null) => PhaseFiveSceneTests.Load(405, s =>
        {
            s["round"]["pending_decision"] = null;
            s["game"]["format"] = "puzzle";
            s["game"]["puzzle_description"] = "Do the first thing.";
            s["game"]["puzzle_hint"] = null;
            edit?.Invoke(s);
        });

        [UnityTest]
        public IEnumerator Puzzles_AreListedWithTheirGoalAndHowYouHaveDone()
        {
            var server = Serve(PuzzleBoard());
            server.PuzzlesJson = TwoPuzzles;
            yield return OpenPuzzles(server);

            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.IsTrue(texts.Contains("1 of 2 solved"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("✓  First Test Puzzle"));
            Assert.IsTrue(texts.Contains("Second Test Puzzle"));
            Assert.IsTrue(texts.Contains("Do the second thing."));
            Assert.IsTrue(texts.Contains("Easy  -  solved (best 2 plays, solved 3 times)"));
            Assert.IsTrue(texts.Contains("Hard  -  not solved yet"));
            Assert.IsTrue(texts.Contains("Within 3 plays, in a single turn"));
            Assert.AreEqual("Try again", Named("Attempt First Test Puzzle").GetComponentInChildren<Text>().text);
            Assert.AreEqual("Attempt", Named("Attempt Second Test Puzzle").GetComponentInChildren<Text>().text);
            ScreenshotHelper.Capture("puzzles");
        }

        [UnityTest]
        public IEnumerator ALongPuzzleDescription_WrapsWithoutSqueezingTheButton()
        {
            var server = Serve(PuzzleBoard());
            var longText = string.Join(" ", Enumerable.Repeat("This goal is long and keeps going and going", 8));
            server.PuzzlesJson =
                "{\"status\":\"ok\",\"puzzles\":[" +
                "{\"id\":1,\"slug\":\"a\",\"title\":\"Short\",\"description\":\"Short.\",\"difficulty\":\"easy\",\"max_plays\":null,\"solved\":true,\"best_plays\":2,\"solve_count\":1}," +
                "{\"id\":2,\"slug\":\"b\",\"title\":\"Long\",\"description\":\"" + longText + "\",\"difficulty\":\"hard\",\"max_plays\":null,\"solved\":true,\"best_plays\":2,\"solve_count\":1}]}";
            yield return OpenPuzzles(server);

            var short1 = (RectTransform)Named("Attempt Short").transform;
            var long1 = (RectTransform)Named("Attempt Long").transform;

            Assert.AreEqual(220f, short1.rect.width, 0.5f);
            Assert.AreEqual(220f, long1.rect.width, 0.5f, "the button keeps its size however long the description is");
            Assert.AreEqual("Try again", long1.GetComponentInChildren<Text>().text);
            ScreenshotHelper.Capture("puzzles-long-description");
        }

        [UnityTest]
        public IEnumerator AttemptingAPuzzle_StartsItAndOpensItsBoard()
        {
            var server = Serve(PuzzleBoard());
            server.PuzzlesJson = TwoPuzzles;
            server.StateFor = id => id == 900 ? PuzzleBoard() : PhaseFiveSceneTests.Load(406);
            yield return OpenPuzzles(server);

            yield return PhaseFiveSceneTests.Tap(Named("Attempt Second Test Puzzle"));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("POST /puzzles/attempt") && c.Contains("\"puzzle_id\":2")), string.Join("\n", server.Calls));
            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=900")));
            Assert.AreEqual("Do the first thing.", Board().GoalText);
        }

        [UnityTest]
        public IEnumerator APuzzleThatWontStart_SaysWhy_AndStaysOnTheList()
        {
            var server = Serve(PuzzleBoard());
            server.PuzzlesJson = TwoPuzzles;
            server.AttemptReply = MainSceneTests.Reply(400, "{\"status\":\"error\",\"message\":\"That puzzle is not available.\"}");
            yield return OpenPuzzles(server);

            yield return PhaseFiveSceneTests.Tap(Named("Attempt Second Test Puzzle"));

            Assert.AreEqual("That puzzle is not available.", MainSceneTests.Screen<PuzzlesScreen>().StatusText);
            Assert.IsInstanceOf<PuzzlesScreen>(UnityEngine.Object.FindAnyObjectByType<ScreenRouter>().Current);
        }

        [UnityTest]
        public IEnumerator APuzzleBoard_ShowsItsGoal_AndOffersNoResign()
        {
            var server = Serve(PuzzleBoard(s =>
            {
                s["you"]["is_your_turn"] = true;
                s["round"]["current_turn_game_player_id"] = 907;
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Do the first thing.", Board().GoalText);
            Assert.IsFalse(Board().HintAvailable, "this puzzle has no hint");
            Assert.IsNull(PhaseFiveSceneTests.Child("Resign"), "there's nothing to resign: just leave");
            Assert.AreEqual("Puzzle", Board().RoundText);
            ScreenshotHelper.Capture("puzzle-board");
        }

        [UnityTest]
        public IEnumerator TheHint_IsOnlyShownWhenAskedFor_AndTheServerIsToldFirst()
        {
            var server = Serve(PuzzleBoard(s => s["game"]["puzzle_hint"] = "Mind the obvious play."));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsTrue(Board().HintAvailable);
            Assert.IsFalse(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text.Contains("Mind the obvious play.")),
                "the hint doesn't spoil itself");

            yield return PhaseFiveSceneTests.Tap(Named("Hint"));

            Assert.AreEqual(1, server.Posts("/games/puzzle-hint-viewed").Count());
            Assert.AreEqual(405, (int)server.Posts("/games/puzzle-hint-viewed").Single()["game_id"]);
            Assert.IsTrue(Board().Confirm.IsOpen);
            Assert.AreEqual("Mind the obvious play.", Board().Confirm.MessageText);
        }

        [UnityTest]
        public IEnumerator ASolvedPuzzle_SaysHowManyPlaysItTook()
        {
            var server = Serve(PuzzleBoard(s =>
            {
                s["game"]["status"] = "completed";
                s["game"]["puzzle_plays_made"] = 3;
                s["game"]["winner_usernames"] = new JArray("bshaftoe");
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Puzzle solved in 3 plays!", Board().BannerText);
            Assert.IsNull(PhaseFiveSceneTests.Child("Primary action"));
        }

        [UnityTest]
        public IEnumerator Home_OffersPuzzles()
        {
            var server = Serve(PuzzleBoard());
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();

            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Puzzles"));
            ScreenshotHelper.Capture("home-with-puzzles");
        }
    }
}
