using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
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
    /// Opens the real captured 2-, 3- and 4-player games on the real board
    /// (against a fake server, no network) and checks what's actually on
    /// screen: where each seat sits, who holds Hurt Feelings, what polling and
    /// a dropped connection do. The states are the real fixtures; a few tests
    /// edit them (a Hurt Feelings holder, a spectator's missing seat) where the
    /// capture doesn't contain that situation.
    /// </summary>
    public class PhaseFourSceneTests
    {
        private sealed class BoardServer
        {
            public List<string> Calls { get; } = new List<string>();

            /// <summary>The body to answer GET /games/state with, by game id; replaced by tests to change the game.</summary>
            public Func<int, string> PlayerState = id => Fixture($"game_{id}_state");

            public Func<int, string> SpectatorState = id => Fixture($"game_{id}_state");

            /// <summary>When set, answers the state routes instead (e.g. a network error).</summary>
            public Func<HttpResponse> StateOverride;

            public HttpResponse Handle(HttpRequest request)
            {
                var path = request.Url.Substring(request.Url.IndexOf("/app/", StringComparison.Ordinal) + 4);
                Calls.Add($"{request.Method} {path}");

                if (path.StartsWith("/games/state") || path.StartsWith("/games/spectate/state"))
                {
                    if (StateOverride != null)
                    {
                        return StateOverride();
                    }

                    var id = int.Parse(path.Substring(path.IndexOf("game_id=", StringComparison.Ordinal) + 8).Split('&')[0]);
                    var body = path.StartsWith("/games/spectate") ? SpectatorState(id) : PlayerState(id);
                    return MainSceneTests.Reply(200, body);
                }

                switch (path)
                {
                    case "/me": return MainSceneTests.Reply(200, Fixture("me"));
                    case "/friends": return MainSceneTests.Reply(200, Fixture("friends"));
                    case "/friends/invites": return MainSceneTests.Reply(200, Fixture("friends_invites"));
                    case "/games": return MainSceneTests.Reply(200, Fixture("games"));
                    case "/games/past": return MainSceneTests.Reply(200, Fixture("games_past"));
                }

                return MainSceneTests.Reply(404, "{\"status\":\"error\"}");
            }
        }

        private static string Fixture(string name) =>
            File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        /// <summary>A captured state with the edits a test needs applied to its JSON.</summary>
        private static string Edited(int gameId, Action<JObject> edit)
        {
            var state = JObject.Parse(Fixture($"game_{gameId}_state"));
            edit(state);
            return state.ToString();
        }

        private static IEnumerator OpenBoard(BoardServer server, int gameId, bool spectate = false)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            var session = spectate
                ? BoardSession.ForSpectator(AppServices.Api, gameId, "CODE1")
                : BoardSession.ForPlayer(AppServices.Api, gameId);
            router.Show<BoardScreen>(session);
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        private static BoardScreen Board() => MainSceneTests.Screen<BoardScreen>();

        private static Transform Child(string name) =>
            UnityEngine.Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.name == name);

        private static Vector3 Where(string seatName)
        {
            var seat = Child("Seat " + seatName);
            Assert.IsNotNull(seat, "No seat for " + seatName);
            return seat.position;
        }

        private static List<string> VisibleTexts() =>
            UnityEngine.Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToList();

        // --- seating ---------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator FourPlayers_AreSeatedAroundTheTable_TheNextSeatAtYourLeft()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            var you = Where("bshaftoe");
            var next = Where("BotSage");           // seat 1: your left (West)
            var across = Where("BotSageQuick");    // seat 2: across (North)
            var last = Where("BotSageDeep");       // seat 3: your right (East)

            Assert.Less(next.x, across.x, "the next seat in turn order is at the viewer's left");
            Assert.Less(across.x, last.x);
            Assert.Greater(across.y, next.y, "across is higher than the side seats");
            Assert.AreEqual(next.y, last.y, 0.01f, "the side seats are level");
            Assert.Less(you.y, next.y, "you are at the bottom");
            Assert.AreEqual(across.x, you.x, 0.01f, "across is directly opposite you");
            ScreenshotHelper.Capture("board-4-players");
        }

        [UnityTest]
        public IEnumerator ThreePlayers_TheNextSeatIsTopLeft_TheLastTopRight()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 406);

            var left = Where("BotSage");        // seat 1
            var right = Where("BotSageQuick");  // seat 2
            var you = Where("bshaftoe");

            Assert.Less(left.x, right.x);
            Assert.AreEqual(left.y, right.y, 0.01f);
            Assert.Greater(left.y, you.y);
            ScreenshotHelper.Capture("board-3-players");
        }

        [UnityTest]
        public IEnumerator TwoPlayers_TheOpponentIsAcrossFromYou()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 405);

            var opponent = Where("BotSage");
            var you = Where("bshaftoe");

            Assert.Greater(opponent.y, you.y);
            Assert.AreEqual(opponent.x, you.x, 0.01f);
            ScreenshotHelper.Capture("board-2-players");
        }

        [UnityTest]
        public IEnumerator EachGameDrawsOneZonePerSeat_PlusThePilesAndTheHand()
        {
            foreach (var game in new[] { (405, 2), (406, 3), (407, 4) })
            {
                var server = new BoardServer();
                yield return OpenBoard(server, game.Item1);

                Assert.AreEqual(game.Item2 + 2, Board().TableChildCount, $"game {game.Item1}: seats + piles + hand");
            }
        }

        // --- what's on the table ---------------------------------------------------------------

        [UnityTest]
        public IEnumerator TheHeader_SaysWhatsHappeningAndHowFarTheGameIs()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            Assert.AreEqual("Your response is needed  -  Confusion", Board().BannerText);
            Assert.AreEqual("Round 1  -  First to 3 wins", Board().RoundText);
        }

        [UnityTest]
        public IEnumerator EachSeat_ShowsItsPlayerAndTheirCounts()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            var texts = VisibleTexts();
            Assert.IsTrue(texts.Contains("bshaftoe  (you)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("BotSage  (bot)"));
            Assert.IsTrue(texts.Contains("Hand 4  -  Deck 25  -  6 pts  -  0 wins"));
            Assert.IsTrue(texts.Contains("Hand 4  -  Deck 25  -  4 pts  -  0 wins"), "BotSageQuick has 4 points");
        }

        [UnityTest]
        public IEnumerator YourHand_ShowsEveryCard()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            foreach (var name in new[] { "Loyalty", "Patience", "Rationalization", "Cheer" })
            {
                Assert.IsNotNull(Child("Card " + name), "hand card " + name);
            }
        }

        [UnityTest]
        public IEnumerator Moods_AreDrawnInFrontOfTheirOwners()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 405);

            // Laziness is bshaftoe's; Fury and Gluttony are BotSage's.
            Assert.AreEqual("Seat bshaftoe", Child("Card Laziness").parent.parent.name);
            Assert.AreEqual("Seat BotSage", Child("Card Fury").parent.parent.name);
            Assert.AreEqual("Seat BotSage", Child("Card Gluttony").parent.parent.name);
        }

        [UnityTest]
        public IEnumerator AMoodWhoseValueWasChangedByAnEffect_ShowsTheRealValue()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            // Tranquility is printed 3 but is 6 right now.
            var chip = Child("Card Tranquility").Find("Value").GetComponentInChildren<Text>();
            Assert.AreEqual("6", chip.text);
        }

        [UnityTest]
        public IEnumerator TheDiscardPile_ShowsItsTopCardAndHowManyAreInIt()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 405);

            var texts = VisibleTexts();
            Assert.IsTrue(texts.Contains("Discard 1"));
            Assert.IsTrue(texts.Contains("Deck 34"));
            Assert.IsNotNull(Child("Card Celebration"), "the one discarded card is on top");
        }

        [UnityTest]
        public IEnumerator WhoseTurnItIs_IsMarkedOnTheirSeat()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 406); // bshaftoe's turn

            var marker = Child("Seat bshaftoe").Find("RowPanel").Find("Turn").GetComponent<Image>().color;
            var other = Child("Seat BotSage").Find("RowPanel").Find("Turn").GetComponent<Image>().color;

            Assert.AreNotEqual(other, marker);
        }

        // --- Hurt Feelings -----------------------------------------------------------------------

        [UnityTest]
        public IEnumerator HurtFeelings_AppearsOnlyOnTheSeatThatHoldsIt()
        {
            var server = new BoardServer
            {
                PlayerState = id => Edited(407, s => s["round"]["hurt_feelings_game_player_id"] = 914),
            };
            yield return OpenBoard(server, 407);

            var holders = UnityEngine.Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude)
                .Where(t => t.name == "Hurt Feelings").ToList();

            Assert.AreEqual(1, holders.Count);
            Assert.AreEqual("Seat BotSageQuick", holders[0].parent.parent.name, "914 is BotSageQuick");
            Assert.AreEqual(1, VisibleTexts().Count(t => t == "Hurt Feelings"), "and the holder's plate names it");
            ScreenshotHelper.Capture("board-hurt-feelings");
        }

        [UnityTest]
        public IEnumerator WithoutAHurtFeelingsHolder_NoTokenIsDrawn()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            Assert.IsNull(Child("Hurt Feelings"));
        }

        // --- overlays ---------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator ClickingACard_OpensItsCloseUp_AndBackClosesItFirst()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            Child("Card Loyalty").GetComponent<Button>().onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames();

            Assert.IsTrue(Board().DetailOpen);
            var texts = VisibleTexts();
            Assert.IsTrue(texts.Any(t => t.Contains("Loyalty") && t.Contains("While in play")), "the rules text is readable");
            ScreenshotHelper.Capture("board-card-detail");

            Assert.IsTrue(Board().HandleBack(), "Back closes the close-up first");
            Assert.IsFalse(Board().DetailOpen);
            Assert.IsFalse(Board().HandleBack(), "and only then would it leave the board");
        }

        [UnityTest]
        public IEnumerator TheEventLog_ListsWhatJustHappened()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);

            yield return PhaseTwoSceneTests.Click("Log");

            Assert.IsTrue(VisibleTexts().Any(t => t.Contains("BotSageQuick played Confusion")), "the newest event");
            ScreenshotHelper.Capture("board-log");
        }

        [UnityTest]
        public IEnumerator Chat_ShowsTheGamesMessages_AndCountsThem()
        {
            var server = new BoardServer
            {
                PlayerState = id => Edited(407, s => s["chat_messages"] = new JArray(
                    new JObject
                    {
                        ["id"] = 1, ["sender_user_id"] = 2, ["sender_username"] = "bshaftoe", ["channel"] = "game",
                        ["message_text"] = "GL;HF", ["created_at"] = "2026-10-01 12:00:00",
                    })),
            };
            yield return OpenBoard(server, 407);

            Assert.IsNotNull(PhaseTwoSceneTests.FindButton("Chat (1)"));
            yield return PhaseTwoSceneTests.Click("Chat (1)");

            Assert.IsTrue(VisibleTexts().Contains("bshaftoe: GL;HF"));
        }

        // --- spectating -------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Spectating_HasNoHand_AndSaysSo()
        {
            var server = new BoardServer
            {
                SpectatorState = id => Edited(406, s => { s["you"] = new JObject { ["game_player_id"] = null, ["hand"] = new JArray() }; }),
            };
            yield return OpenBoard(server, 406, spectate: true);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/spectate/state?game_id=406&code=CODE1")), string.Join("\n", server.Calls));
            Assert.IsTrue(VisibleTexts().Contains("You're watching this game."));
            StringAssert.Contains("watching", Board().RoundText);
            Assert.IsNull(Child("Card Superiority"), "a spectator is shown none of the seat-0 player's hand");
            Assert.IsNotNull(Child("Seat bshaftoe"));
            ScreenshotHelper.Capture("board-spectator");
        }

        // --- polling and failures ----------------------------------------------------------------

        [UnityTest]
        public IEnumerator ThePoll_PicksUpAChangeInTheGame()
        {
            var calls = 0;
            var server = new BoardServer();
            server.PlayerState = id => calls++ == 0
                ? Fixture("game_406_state")
                : Edited(406, s => s["round"]["current_turn_game_player_id"] = 910);
            yield return OpenBoard(server, 406);
            Assert.AreEqual("Your turn", Board().BannerText);

            yield return new WaitForSeconds(3.6f);
            yield return PhaseTwoSceneTests.Frames();

            Assert.AreEqual("BotSage's turn", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator WhenTheConnectionDrops_TheLastBoardStaysUp_WithAMessage()
        {
            var server = new BoardServer();
            yield return OpenBoard(server, 407);
            server.StateOverride = () => new HttpResponse { NetworkError = "offline" };

            yield return new WaitForSeconds(3.6f);
            yield return PhaseTwoSceneTests.Frames();

            StringAssert.Contains("Can't reach the server", Board().MessageText);
            Assert.IsNotNull(Child("Seat BotSage"), "the board is still there");
            Assert.AreEqual("Your response is needed  -  Confusion", Board().BannerText);

            server.StateOverride = null;
            yield return new WaitForSeconds(3.6f);
            yield return PhaseTwoSceneTests.Frames();
            Assert.AreEqual(string.Empty, Board().MessageText, "and the message clears once it's back");
        }

        [UnityTest]
        public IEnumerator AGameYouCantOpen_ShowsWhy_InsteadOfABoard()
        {
            var server = new BoardServer
            {
                StateOverride = () => MainSceneTests.Reply(403, "{\"status\":\"error\",\"message\":\"You are not a player in this game.\"}"),
            };
            yield return OpenBoard(server, 999);

            Assert.IsTrue(VisibleTexts().Contains("You are not a player in this game."));
            Assert.AreEqual(0, Board().TableChildCount);
        }

        // --- getting there and back ---------------------------------------------------------------

        [UnityTest]
        public IEnumerator PlayRows_OpenTheirBoard_AndBackReturnsToPlay()
        {
            var server = new BoardServer();
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Click("Play  (3 waiting on you)");
            yield return MainSceneTests.WaitFor<PlayScreen>();

            yield return PhaseTwoSceneTests.Click("Open");
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=")));
            Assert.Greater(Board().TableChildCount, 0);

            yield return PhaseTwoSceneTests.Click("< Back");
            yield return MainSceneTests.WaitFor<PlayScreen>();
        }
    }
}
