using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Drives the real Main scene through Play, New Game and Open Games
    /// against a small stateful fake of the server (no network), pressing the
    /// real buttons and checking the requests that go out. The games, past
    /// games and bots are the real captured fixtures.
    /// </summary>
    public class PhaseThreeSceneTests
    {
        private const string NoListings = "{\"status\":\"ok\",\"listings\":[]}";

        private sealed class LobbyFakeServer
        {
            public List<string> Calls { get; } = new List<string>();
            public string AvailableJson = Listings(Listing(1, "Alice", 3, 1));
            public string MineJson = NoListings;
            public string JoinedJson = NoListings;
            public HttpResponse JoinResponse = MainSceneTests.Reply(201, "{\"status\":\"waiting\",\"joined_count\":1,\"target_player_count\":3}");

            public HttpResponse Handle(HttpRequest request)
            {
                var path = request.Url.Substring(request.Url.IndexOf("/app/", StringComparison.Ordinal) + 4);
                Calls.Add($"{request.Method} {path} {request.Body}");

                switch (path)
                {
                    case "/me": return MainSceneTests.Reply(200, Fixture("me"));
                    case "/friends": return MainSceneTests.Reply(200, Fixture("friends"));
                    case "/friends/invites": return MainSceneTests.Reply(200, Fixture("friends_invites"));
                    case "/games": return request.Method == "POST"
                        ? MainSceneTests.Reply(201, "{\"status\":\"ok\",\"game_id\":408}")
                        : MainSceneTests.Reply(200, Fixture("games"));
                    case "/games/past": return MainSceneTests.Reply(200, Fixture("games_past"));
                    case "/games/bots": return MainSceneTests.Reply(200, Fixture("games_bots"));
                    case "/open-games": return request.Method == "POST"
                        ? MainSceneTests.Reply(201, "{\"status\":\"ok\",\"listing_id\":7}")
                        : MainSceneTests.Reply(200, AvailableJson);
                    case "/open-games?mine=1": return MainSceneTests.Reply(200, MineJson);
                    case "/open-games?joined=1": return MainSceneTests.Reply(200, JoinedJson);
                    case "/open-games/join": return JoinResponse;
                    case "/open-games/leave":
                    case "/open-games/cancel": return MainSceneTests.Reply(200, "{\"status\":\"ok\"}");
                }

                return MainSceneTests.Reply(404, "{\"status\":\"error\"}");
            }
        }

        private static string Fixture(string name) =>
            File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        private static string Listing(int id, string creator, int target, int joined) =>
            $"{{\"id\":{id},\"created_by_user_id\":{id + 100},\"creator_username\":\"{creator}\",\"create_game_params\":" +
            "{\"format\":\"standard\",\"wins_needed\":3,\"deck_type\":\"structure\",\"default_selections_mode\":false}," +
            $"\"target_player_count\":{target},\"joined_count\":{joined},\"created_at\":\"2026-10-01 10:00:00\"}}";

        private static string Listings(params string[] listings) => "{\"status\":\"ok\",\"listings\":[" + string.Join(",", listings) + "]}";

        private static IEnumerator SignedInAtHome(LobbyFakeServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
        }

        private static IEnumerator OpenPlay(LobbyFakeServer server)
        {
            yield return SignedInAtHome(server);
            yield return PhaseTwoSceneTests.Click("Play  (3 waiting on you)");
            yield return MainSceneTests.WaitFor<PlayScreen>();
        }

        private static IEnumerator OpenNewGame(LobbyFakeServer server)
        {
            yield return OpenPlay(server);
            yield return PhaseTwoSceneTests.Click("New game");
            yield return MainSceneTests.WaitFor<NewGameScreen>();
        }

        private static IEnumerator OpenOpenGames(LobbyFakeServer server)
        {
            yield return OpenPlay(server);
            yield return PhaseTwoSceneTests.Click("Open games");
            yield return MainSceneTests.WaitFor<OpenGamesScreen>();
        }

        private static string StartButtonText() =>
            MainSceneTests.Screen<NewGameScreen>().GetComponentsInChildren<Button>()
                .Select(b => b.GetComponentInChildren<Text>().text)
                .Single(t => t == "Start game" || t == "Post to open lobby");

        private static Button StartButton() => PhaseTwoSceneTests.FindButton(StartButtonText());

        private static bool AnyCall(LobbyFakeServer server, string prefix, params string[] contains) =>
            server.Calls.Any(c => c.StartsWith(prefix) && contains.All(c.Contains));

        [UnityTest]
        public IEnumerator Home_ShowsHowManyGamesAreWaitingOnYou()
        {
            var server = new LobbyFakeServer();
            yield return SignedInAtHome(server);

            Assert.AreEqual("Play  (3 waiting on you)", MainSceneTests.Screen<HomeScreen>().PlayButtonText);
            ScreenshotHelper.Capture("home-play-badge");
        }

        [UnityTest]
        public IEnumerator Play_ListsInProgressGamesAndTheMostRecentFinishedOnes()
        {
            var server = new LobbyFakeServer();
            yield return OpenPlay(server);

            // In progress: title + 3 games. Finished: title + 15 shown + a "Show all" button.
            Assert.AreEqual(1 + 3 + 1 + 15 + 1, MainSceneTests.Screen<PlayScreen>().RowCount);
            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Show all 69"));
            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.AreEqual(2, texts.Count(t => t == "Your response is needed"));
            Assert.AreEqual(1, texts.Count(t => t == "Your turn"));
            Assert.IsTrue(texts.Contains("vs BotSage, BotSageQuick, BotSageDeep"));
            ScreenshotHelper.Capture("play");
        }

        [UnityTest]
        public IEnumerator Play_ShowAllRevealsEveryFinishedGame()
        {
            var server = new LobbyFakeServer();
            yield return OpenPlay(server);

            yield return PhaseTwoSceneTests.Click("Show all 69");

            Assert.AreEqual(1 + 3 + 1 + 69, MainSceneTests.Screen<PlayScreen>().RowCount);
            Assert.IsNull(PhaseTwoSceneTests.FindButton("Show all 69"));
        }

        [UnityTest]
        public IEnumerator Play_RematchOpensNewGamePrefilledWithTheSameOpponentAndDeck()
        {
            var server = new LobbyFakeServer();
            yield return OpenPlay(server);

            yield return PhaseTwoSceneTests.Click("Rematch");
            yield return MainSceneTests.WaitFor<NewGameScreen>();

            // The most recent finished game was a Traditional/Structure match against jceddy, who is a friend.
            Assert.IsTrue(PhaseTwoSceneTests.FindToggle("jceddy  (online)").isOn);
            Assert.IsTrue(PhaseTwoSceneTests.FindToggle("Structure").isOn);
            Assert.IsTrue(StartButton().interactable, "a prefilled setup is ready to start");
            ScreenshotHelper.Capture("new-game-rematch");
        }

        [UnityTest]
        public IEnumerator NewGame_ListsBotsAndFriends_AndNeedsAnOpponentBeforeItCanStart()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);

            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("BotAlice"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("BotSage  (tactical)"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("jceddy  (online)"));
            Assert.IsFalse(StartButton().interactable, "no opponent picked yet");
            ScreenshotHelper.Capture("new-game");

            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsTrue(StartButton().interactable);
        }

        [UnityTest]
        public IEnumerator NewGame_StartingAGameVsBots_PostsItAndReturnsToPlay()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            PhaseTwoSceneTests.FindToggle("BotSageQuick  (tactical)").isOn = true;
            PhaseTwoSceneTests.FindToggle("Power").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"opponent_user_ids\":[18,20]", "\"deck_type\":\"power\"", "\"format\":\"standard\""),
                string.Join("\n", server.Calls));
            Assert.AreEqual("Game created.", MainSceneTests.Screen<PlayScreen>().StatusText);
        }

        [UnityTest]
        public IEnumerator NewGame_LetABotGoFirst_AppearsOnlyOnceABotIsPicked_AndIsSent()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Let a bot go first"), "no bot picked yet");

            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            var first = PhaseTwoSceneTests.FindToggle("Let a bot go first");
            Assert.IsNotNull(first);
            first.isOn = true;
            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"bot_goes_first\":true"));
        }

        [UnityTest]
        public IEnumerator NewGame_ASeatedGameHoldsAtMostThreeOpponents()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            foreach (var bot in new[] { "BotAlice", "BotBen", "BotCleo" })
            {
                PhaseTwoSceneTests.FindToggle(bot).isOn = true;
            }

            yield return PhaseTwoSceneTests.Frames();
            var fourth = PhaseTwoSceneTests.FindToggle("BotSage  (tactical)");
            fourth.isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsFalse(fourth.isOn, "the fourth is refused");
            StringAssert.Contains("at most 3 opponents", MainSceneTests.Screen<NewGameScreen>().StatusText);
            StringAssert.Contains("Selected: 3 of 3", UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).First(t => t.StartsWith("Selected:")));
            ScreenshotHelper.Capture("new-game-full");
        }

        [UnityTest]
        public IEnumerator NewGame_PostingToTheOpenLobby_SendsTheChosenPlayerCount()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);

            PhaseTwoSceneTests.FindToggle("Post to the open lobby").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("BotAlice"), "no opponent picker for an open game");
            PhaseTwoSceneTests.FindToggle("3 players").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsTrue(StartButton().interactable);
            ScreenshotHelper.Capture("new-game-open-lobby");

            yield return PhaseTwoSceneTests.Click("Post to open lobby");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            Assert.IsTrue(AnyCall(server, "POST /open-games {", "\"target_player_count\":3", "\"deck_type\":\"structure\""),
                string.Join("\n", server.Calls));
            StringAssert.Contains("3 players", MainSceneTests.Screen<PlayScreen>().StatusText);
        }

        [UnityTest]
        public IEnumerator OpenGames_ListsWhatCanBeJoined_PostedAndJoined()
        {
            var server = new LobbyFakeServer
            {
                MineJson = Listings(Listing(3, "bshaftoe", 4, 1)),
                JoinedJson = Listings(Listing(4, "Cleo", 2, 1)),
            };
            yield return OpenOpenGames(server);

            // 3 section titles + 1 row each.
            Assert.AreEqual(6, MainSceneTests.Screen<OpenGamesScreen>().RowCount);
            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Join"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Take down"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Leave"));
            ScreenshotHelper.Capture("open-games");
        }

        [UnityTest]
        public IEnumerator OpenGames_JoiningAGameThatStillNeedsPlayers_SaysHowManyMore()
        {
            var server = new LobbyFakeServer();
            yield return OpenOpenGames(server);

            yield return PhaseTwoSceneTests.Click("Join");

            Assert.IsTrue(AnyCall(server, "POST /open-games/join", "{\"id\":1}"));
            Assert.AreEqual("Joined. Waiting for 1 more player.", MainSceneTests.Screen<OpenGamesScreen>().StatusText);
        }

        [UnityTest]
        public IEnumerator OpenGames_JoiningAsTheLastPlayer_StartsTheGame()
        {
            var server = new LobbyFakeServer
            {
                JoinResponse = MainSceneTests.Reply(201, "{\"status\":\"started\",\"game_id\":55}"),
            };
            yield return OpenOpenGames(server);

            yield return PhaseTwoSceneTests.Click("Join");

            Assert.AreEqual("The game is starting!", MainSceneTests.Screen<OpenGamesScreen>().StatusText);
            Assert.IsTrue(server.Calls.Count(c => c == "GET /games ") >= 2, "your games were refreshed after it started");
        }

        [UnityTest]
        public IEnumerator OpenGames_TakeDownAndLeave_PostTheListingId()
        {
            var server = new LobbyFakeServer
            {
                MineJson = Listings(Listing(3, "bshaftoe", 4, 1)),
                JoinedJson = Listings(Listing(4, "Cleo", 2, 1)),
            };
            yield return OpenOpenGames(server);

            yield return PhaseTwoSceneTests.Click("Take down");
            Assert.IsTrue(AnyCall(server, "POST /open-games/cancel", "{\"id\":3}"));
            Assert.AreEqual("Your game was taken down.", MainSceneTests.Screen<OpenGamesScreen>().StatusText);

            yield return PhaseTwoSceneTests.Click("Leave");
            Assert.IsTrue(AnyCall(server, "POST /open-games/leave", "{\"id\":4}"));
            Assert.AreEqual("You left the game.", MainSceneTests.Screen<OpenGamesScreen>().StatusText);
        }

        [UnityTest]
        public IEnumerator OpenGames_PostAGameOpensNewGameOnTheOpenLobbyOption()
        {
            var server = new LobbyFakeServer();
            yield return OpenOpenGames(server);

            yield return PhaseTwoSceneTests.Click("Post a game");
            yield return MainSceneTests.WaitFor<NewGameScreen>();

            Assert.IsTrue(PhaseTwoSceneTests.FindToggle("Post to the open lobby").isOn);
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("2 players"));
        }

        [UnityTest]
        public IEnumerator LoggingOut_ForgetsTheLobby()
        {
            var server = new LobbyFakeServer();
            yield return SignedInAtHome(server);
            Assert.AreEqual(3, AppServices.Lobby.ActiveGames.Count);

            yield return PhaseTwoSceneTests.Click("Log out");
            yield return MainSceneTests.WaitFor<LoginScreen>();

            Assert.AreEqual(0, AppServices.Lobby.ActiveGames.Count);
            Assert.AreEqual(0, AppServices.Lobby.PastGames.Count);
        }
    }
}
