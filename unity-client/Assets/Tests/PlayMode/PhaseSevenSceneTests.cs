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
    }
}
