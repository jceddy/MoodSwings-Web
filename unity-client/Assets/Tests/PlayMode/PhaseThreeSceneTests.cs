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
            public bool SynchronousFlag;
            public string DecklistsJson =
                "{\"status\":\"ok\",\"friends\":[],\"own\":[" +
                "{\"id\":30,\"name\":\"Mine\",\"visibility\":\"private\",\"card_count\":20,\"sideboard_card_count\":0}," +
                "{\"id\":31,\"name\":\"Tiny\",\"visibility\":\"private\",\"card_count\":10,\"sideboard_card_count\":0}]}";
            public HttpResponse JoinResponse = MainSceneTests.Reply(201, "{\"status\":\"waiting\",\"joined_count\":1,\"target_player_count\":3}");

            public HttpResponse Handle(HttpRequest request)
            {
                var path = request.Url.Substring(request.Url.IndexOf("/app/", StringComparison.Ordinal) + 4);
                Calls.Add($"{request.Method} {path} {request.Body}");

                if (path.StartsWith("/games/state"))
                {
                    return MainSceneTests.Reply(200, Fixture("game_406_state"));
                }

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
                    case "/decklists": return MainSceneTests.Reply(200, DecklistsJson);
                    case "/config/synchronous-mode-enabled":
                        return MainSceneTests.Reply(200, "{\"status\":\"ok\",\"enabled\":" + (SynchronousFlag ? "true" : "false") + "}");
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

        /// <summary>A listing as the server sends it. A null creator leaves creator_username out, as the real server does for the listings you posted.</summary>
        private static string Listing(int id, string creator, int target, int joined, int? createdBy = null) =>
            $"{{\"id\":{id},\"created_by_user_id\":{createdBy ?? id + 100}," +
            (creator == null ? string.Empty : $"\"creator_username\":\"{creator}\",") + "\"create_game_params\":" +
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

            Assert.AreEqual(1 + 3 + 1 + GameDisplay.Group(AppServices.Lobby.PastGames).Count, MainSceneTests.Screen<PlayScreen>().RowCount, "a match is one block");
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
        public IEnumerator NewGame_StartingAGameVsBots_PostsItAndOpensItsBoard()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            PhaseTwoSceneTests.FindToggle("BotSageQuick  (tactical)").isOn = true;
            PhaseTwoSceneTests.FindToggle("Power").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"opponent_user_ids\":[18,20]", "\"deck_type\":\"power\"", "\"format\":\"standard\""),
                string.Join("\n", server.Calls));
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=408")), "the new game's board is open");

            // Back from the board is the lobby it was started from.
            UnityEngine.Object.FindAnyObjectByType<ScreenRouter>().Back();
            yield return MainSceneTests.WaitFor<PlayScreen>();
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
            yield return MainSceneTests.WaitFor<BoardScreen>();

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
        public IEnumerator NewGame_OffersTraditionalAndDuel_AndSendsTheFormatChosen()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);

            Assert.IsTrue(PhaseTwoSceneTests.FindToggle("Traditional").isOn, "Traditional is the default");
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Duel"));
            ScreenshotHelper.Capture("new-game-formats");

            PhaseTwoSceneTests.FindToggle("Duel").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"duel\"", "\"deck_type\":\"structure\""), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ADuelPostedToTheLobby_SeatsExactlyTwo()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("Duel").isOn = true;
            PhaseTwoSceneTests.FindToggle("Post to the open lobby").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsNull(PhaseTwoSceneTests.FindToggle("3 players"), "the count isn't the creator's to choose");
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude)
                .Any(t => t.text.Contains("seats exactly 2 players")));

            yield return PhaseTwoSceneTests.Click("Post to open lobby");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            Assert.IsTrue(AnyCall(server, "POST /open-games {", "\"format\":\"duel\"", "\"target_player_count\":2"), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_BestOfThree_IsOfferedForTwoPlayersOnly_AndSent()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Best of three"), "no opponent yet");

            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            var toggle = PhaseTwoSceneTests.FindToggle("Best of three");
            Assert.IsNotNull(toggle);
            toggle.isOn = true;

            PhaseTwoSceneTests.FindToggle("BotSageQuick  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Best of three"), "three players: no single opponent to win against");
            PhaseTwoSceneTests.FindToggle("BotSageQuick  (tactical)").isOn = false;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsFalse(PhaseTwoSceneTests.FindToggle("Best of three").isOn, "switched off, not just hidden");

            PhaseTwoSceneTests.FindToggle("Best of three").isOn = true;
            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"best_of_three\":true"), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_TeamPlay_NeedsThreeOpponentsAndAPartner_AndSendsThem()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);

            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Open Team Play"));
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Closed Team Play"));
            PhaseTwoSceneTests.FindToggle("Closed Team Play").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Power"), "too small a deck for a team");
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Assign my partner at random"));
            Assert.IsFalse(StartButton().interactable);

            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            PhaseTwoSceneTests.FindToggle("BotSageQuick  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsFalse(StartButton().interactable, "two opponents isn't a team game");

            PhaseTwoSceneTests.FindToggle("BotSageDeep  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsTrue(StartButton().interactable);
            ScreenshotHelper.Capture("new-game-team");

            // The partner list is the three picked; choose the middle one.
            var partners = UnityEngine.Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude)
                .Where(t => t.group != null && t.GetComponentInChildren<Text>() != null && t.GetComponentInChildren<Text>().text.StartsWith("BotSage"))
                .ToList();
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("BotSageQuick"), string.Join(",", partners.Select(p => p.GetComponentInChildren<Text>().text)));
            PhaseTwoSceneTests.FindToggle("BotSageQuick").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"closed_team\"", "\"opponent_user_ids\":[18,20,21]", "\"partner_user_id\":20"),
                string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_TeamPlay_CanLeaveThePartnerToChance()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("Open Team Play").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            foreach (var bot in new[] { "BotAlice", "BotBen", "BotCleo" })
            {
                PhaseTwoSceneTests.FindToggle(bot).isOn = true;
            }

            yield return PhaseTwoSceneTests.Frames();
            PhaseTwoSceneTests.FindToggle("Assign my partner at random").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"team\"", "\"random_teams\":true"), string.Join("\n", server.Calls));
            Assert.IsFalse(AnyCall(server, "POST /games {", "partner_user_id"));
        }

        [UnityTest]
        public IEnumerator NewGame_TeamPlayFromTheLobby_SeatsFourAndDrawsTeamsLater()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("Open Team Play").isOn = true;
            PhaseTwoSceneTests.FindToggle("Post to the open lobby").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Assign my partner at random"), "partners are drawn once everyone has joined");
            Assert.IsTrue(UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Any(t => t.text.Contains("seats exactly 4 players")));

            yield return PhaseTwoSceneTests.Click("Post to open lobby");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            Assert.IsTrue(AnyCall(server, "POST /open-games {", "\"format\":\"team\"", "\"target_player_count\":4"), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_BestOfThree_IsOfferedInTeamPlayAtAnyPointOfPickingOpponents()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("Open Team Play").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Best of three"));
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Synchronous (live)"));
        }

        [UnityTest]
        public IEnumerator NewGame_Synchronous_IsHiddenUntilTheServerOffersIt()
        {
            var server = new LobbyFakeServer { SynchronousFlag = false };
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Synchronous (live)"));
        }

        [UnityTest]
        public IEnumerator NewGame_Synchronous_AppearsForExactlyTwoPlayers_AndIsSent()
        {
            var server = new LobbyFakeServer { SynchronousFlag = true };
            yield return OpenNewGame(server);
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Synchronous (live)"), "no opponent yet, so not two players");

            PhaseTwoSceneTests.FindToggle("jceddy  (online)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            var toggle = PhaseTwoSceneTests.FindToggle("Synchronous (live)");
            Assert.IsNotNull(toggle);
            ScreenshotHelper.Capture("new-game-synchronous");
            toggle.isOn = true;

            // A third player makes it meaningless, so it goes away and is not sent.
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Synchronous (live)"));
            PhaseTwoSceneTests.FindToggle("BotSage  (tactical)").isOn = false;
            yield return PhaseTwoSceneTests.Frames();
            Assert.IsFalse(PhaseTwoSceneTests.FindToggle("Synchronous (live)").isOn, "it was switched off, not just hidden");

            PhaseTwoSceneTests.FindToggle("Synchronous (live)").isOn = true;
            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"synchronous_mode\":true"), string.Join("\n", server.Calls));
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
            // The captured account is user 2; its own listing comes back with no creator_username.
            var server = new LobbyFakeServer
            {
                MineJson = Listings(Listing(3, null, 4, 1, createdBy: 2)),
                JoinedJson = Listings(Listing(4, "Cleo", 2, 1)),
            };
            yield return OpenOpenGames(server);

            // 3 section titles + 1 row each.
            Assert.AreEqual(6, MainSceneTests.Screen<OpenGamesScreen>().RowCount);
            var texts = UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();
            Assert.IsTrue(texts.Contains("Your game  -  2 of 4 seated"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("Alice's game  -  2 of 3 seated"));
            Assert.IsTrue(texts.Contains("Cleo's game  -  2 of 2 seated"));
            Assert.IsFalse(texts.Any(t => t.StartsWith("'s game")), "a missing creator name must never render as a bare 's game");
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
                MineJson = Listings(Listing(3, null, 4, 1, createdBy: 2)),
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

        [UnityTest]
        public IEnumerator NewGame_ADraft_IsSentWithItsFormatDeckTypeAndPool()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);

            Assert.IsTrue(PhaseTwoSceneTests.FindToggle("Quick Draft").isOn, "the first of the draft types");
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Best of three"), "a draft is always a match");
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Power"), "no ready-made decks here");

            PhaseTwoSceneTests.FindToggle("Grid Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            PhaseTwoSceneTests.FindToggle("One of Each Card").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            ScreenshotHelper.Capture("new-game-draft");

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"draft\"", "\"deck_type\":\"grid_draft\"", "\"grid_draft_pool_source\":\"one_of_each\""),
                string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ARotisserieDraft_LetsYouChooseHowManyCardsEachPlayerPicks()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            PhaseTwoSceneTests.FindToggle("Rotisserie Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);

            Assert.AreEqual("14 cards each", PhaseFiveSceneTests.Child("Cutoff label").GetComponent<Text>().text);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("More picks"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("More picks"));
            Assert.AreEqual("16 cards each", PhaseFiveSceneTests.Child("Cutoff label").GetComponent<Text>().text);

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"deck_type\":\"rotisserie_draft\"", "\"rotisserie_draft_pool_source\":\"random_48\"", "\"rotisserie_draft_cutoff_count\":16"),
                string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ThePoolOfTheDay_HoldsOneOpponent_AndSendsItsDeckType()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("Sealed Pool of the Day").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Random 48"), "no pool to choose");
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Quick Draft"), "no draft to choose either");

            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("BotBen").isOn = true;
            yield return PhaseTwoSceneTests.Frames();
            StringAssert.Contains("exactly two players", MainSceneTests.Screen<NewGameScreen>().StatusText);
            Assert.IsFalse(PhaseTwoSceneTests.FindToggle("BotBen").isOn);

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"draft\"", "\"deck_type\":\"sealed_pool_of_the_day\""), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ACustomDeck_NeedsASavedDeckBigEnough_AndSendsIt()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Custom Deck").isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Mine  (20 cards)"), "your saved decks are offered");
            Assert.IsFalse(StartButton().interactable, "no deck chosen yet");

            PhaseTwoSceneTests.FindToggle("Tiny  (10 cards)").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.IsFalse(StartButton().interactable, "two players need 15 cards");

            PhaseTwoSceneTests.FindToggle("Mine  (20 cards)").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.IsTrue(StartButton().interactable);
            ScreenshotHelper.Capture("new-game-custom-deck");

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"deck_type\":\"custom\"", "\"saved_decklist_id\":30"), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ACustomDuel_ChoosesTheRules_AndADeckForEachBot()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Duel").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            PhaseTwoSceneTests.FindToggle("Custom Decklists (Duel)").isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);
            PhaseTwoSceneTests.FindToggle("Power Duel").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);

            Assert.IsFalse(StartButton().interactable, "the bot has no deck yet");
            var botDeck = PhaseFiveSceneTests.Child("Deck Mine");
            Assert.IsNotNull(botDeck, "a deck to give BotAlice");
            Assert.IsTrue(Texts().Contains("Deck for BotAlice"), string.Join(" | ", Texts()));
            botDeck.GetComponent<Toggle>().isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsTrue(StartButton().interactable);

            PhaseTwoSceneTests.FindToggle("Best of three").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            var sideboarding = PhaseTwoSceneTests.FindToggle("Allow sideboarding");
            Assert.IsNotNull(sideboarding, "Power rules in a match can be sideboarded");
            sideboarding.isOn = true;
            ScreenshotHelper.Capture("new-game-custom-duel");

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"deck_type\":\"custom_duel\"", "\"preset\":\"power\"", "\"allow_sideboarding\":true",
                "\"bot_decklists\":{\"9\":{\"saved_decklist_id\":30}}", "\"best_of_three\":true"), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_ADraftCanDealFromASavedDeck()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.IsNull(PhaseFiveSceneTests.Child("Deck Mine"), "no deck list until a saved deck is the pool");

            PhaseTwoSceneTests.FindToggle("A saved deck").isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsFalse(StartButton().interactable);
            PhaseFiveSceneTests.Child("Deck Mine").GetComponent<Toggle>().isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"quick_draft_pool_source\":\"saved_deck\"", "\"saved_decklist_id\":30"), string.Join("\n", server.Calls));
        }

        private static string[] Texts() =>
            UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        [UnityTest]
        public IEnumerator Play_AMatchsGamesAreOneBlock_WithItsScoreAndEachGameBeneath()
        {
            var server = new LobbyFakeServer();
            yield return OpenPlay(server);
            yield return PhaseTwoSceneTests.Click("Show all 69");
            yield return PhaseTwoSceneTests.Frames(3);

            var block = PhaseFiveSceneTests.Child("Match draft:132");
            Assert.IsNotNull(block, "games 349-351 are one match");
            var texts = block.GetComponentsInChildren<Text>().Select(t => t.text).ToList();
            Assert.IsTrue(texts.Contains("Match score: you 1, opponent 2 (first to 2 wins)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("jceddy won the match"));
            Assert.IsTrue(texts.Contains("Game 3") && texts.Contains("Game 2") && texts.Contains("Game 1"));
            Assert.IsTrue(texts.IndexOf("Game 3") < texts.IndexOf("Game 1"), "latest game first");
            Assert.IsNotNull(block.Find("Match games/Game 3"));
            Assert.AreEqual(3, block.Find("Match games").GetComponentsInChildren<Button>().Count(b => b.GetComponentInChildren<Text>().text == "Open"));

            // Scroll the match to the top of the list for the picture.
            var scroll = MainSceneTests.Screen<PlayScreen>().GetComponentInChildren<ScrollRect>();
            Canvas.ForceUpdateCanvases();
            var rect = (RectTransform)block;
            scroll.content.anchoredPosition = new Vector2(0f, -rect.anchoredPosition.y - rect.rect.height / 2f - 10f);
            yield return PhaseTwoSceneTests.Frames(2);
            ScreenshotHelper.Capture("play-match-group");
        }

        [UnityTest]
        public IEnumerator Play_AGameInAMatchOpensItsOwnBoard()
        {
            var server = new LobbyFakeServer();
            yield return OpenPlay(server);
            yield return PhaseTwoSceneTests.Click("Show all 69");
            yield return PhaseTwoSceneTests.Frames(3);

            var openGame2 = PhaseFiveSceneTests.Child("Match draft:132").Find("Match games/Game 2").GetComponentsInChildren<Button>()
                .First(b => b.GetComponentInChildren<Text>().text == "Open");
            openGame2.onClick.Invoke();
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=350")), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator NewGame_TheSealedGamesAreFormats_AndDraftNoLongerOffersThem()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Sealed Deck"), "its own format");
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Sealed Pool of the Day"));

            PhaseTwoSceneTests.FindToggle("Draft").isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);
            Assert.IsNotNull(PhaseTwoSceneTests.FindToggle("Quick Draft"));
            Assert.AreEqual(1, UnityEngine.Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude)
                .Count(t => t.GetComponentInChildren<Text>()?.text == "Sealed Deck"), "only the format, not a deck under Draft");
            ScreenshotHelper.Capture("new-game-formats");
        }

        [UnityTest]
        public IEnumerator NewGame_ASealedDeck_SendsItsDeckType_WithNoDeckToChoose()
        {
            var server = new LobbyFakeServer();
            yield return OpenNewGame(server);
            PhaseTwoSceneTests.FindToggle("BotAlice").isOn = true;
            PhaseTwoSceneTests.FindToggle("Sealed Deck").isOn = true;
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Structure"), "no deck list");
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Quick Draft"));
            Assert.IsNull(PhaseTwoSceneTests.FindToggle("Best of three"), "a sealed match is always a match");

            yield return PhaseTwoSceneTests.Click("Start game");
            yield return MainSceneTests.WaitFor<BoardScreen>();

            Assert.IsTrue(AnyCall(server, "POST /games {", "\"format\":\"draft\"", "\"deck_type\":\"sealed_deck\""), string.Join("\n", server.Calls));
        }
    }
}
