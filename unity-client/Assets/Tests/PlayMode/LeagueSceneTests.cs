using System.Collections;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.UI;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>League play on screen: the League screen and your collection, and building a deck inside a paired game.</summary>
    public class LeagueSceneTests
    {
        private const string Running =
            "{\"status\":\"ok\",\"event\":{\"id\":4,\"name\":\"Spring Cup\",\"set_code\":\"MSW\",\"started_at\":\"2026-10-01 10:00:00\"}," +
            "\"joined\":true,\"queued\":false,\"in_progress_count\":1,\"concurrent_match_cap\":2,\"wins\":3,\"losses\":1," +
            "\"matches\":[{\"id\":9,\"game_id\":812,\"opponent_username\":\"Alder\",\"status\":\"in_progress\",\"won\":null}," +
            "{\"id\":8,\"game_id\":790,\"opponent_username\":\"Birch\",\"status\":\"completed\",\"won\":true}]," +
            "\"collection\":{\"unique_cards\":3,\"total_cards\":5},\"deck_rules\":{\"min_cards\":12,\"max_mythics\":2,\"max_rares\":6}}";

        private const string Leaderboard =
            "{\"status\":\"ok\",\"event\":{\"id\":4,\"name\":\"Spring Cup\",\"set_code\":\"MSW\",\"status\":\"active\"},\"standings\":[" +
            "{\"user_id\":3,\"username\":\"Alder\",\"wins\":3,\"losses\":0,\"rank\":1,\"percentile\":10}," +
            "{\"user_id\":2,\"username\":\"bshaftoe\",\"wins\":2,\"losses\":1,\"rank\":2,\"percentile\":20}]}";

        private static PhaseFiveSceneTests.PlayServer Serve() => new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };

        private static string[] Texts() =>
            Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        private static Button Named(string name) => PhaseFiveSceneTests.ButtonNamed(name);

        private static LeagueScreen League() => MainSceneTests.Screen<LeagueScreen>();

        private static IEnumerator OpenLeague(PhaseFiveSceneTests.PlayServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
            yield return PhaseTwoSceneTests.Click("Play  (3 waiting on you)");
            yield return MainSceneTests.WaitFor<PlayScreen>();
            yield return PhaseTwoSceneTests.Frames(6);
            ScreenshotHelper.Capture("play-with-league-button");
            yield return PhaseTwoSceneTests.Click("League");
            yield return MainSceneTests.WaitFor<LeagueScreen>();
            yield return PhaseTwoSceneTests.Frames(10);
        }

        // --- the League screen -------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator League_WithNoEventRunning_SaysSo_AndOffersNothingToJoin()
        {
            var server = Serve();
            yield return OpenLeague(server);

            Assert.AreEqual("There is no League event running right now.", League().EventText);
            Assert.IsFalse(League().JoinShown);
            Assert.IsFalse(League().CollectionShown);
            Assert.IsTrue(Texts().Contains("There is no League event running right now."));
        }

        [UnityTest]
        public IEnumerator League_ShowsTheEvent_YourRecord_YourMatches_AndTheLeaderboardWithYouMarked()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.LeagueLeaderboardJson = Leaderboard;
            yield return OpenLeague(server);

            Assert.AreEqual("Spring Cup (MSW)", League().EventText);
            StringAssert.StartsWith("Record 3-1. 1/2 matches in progress.", League().SummaryText);
            Assert.IsTrue(League().JoinEnabled);
            Assert.AreEqual("Queue for a match", League().JoinText);
            Assert.IsFalse(League().LeaveShown);
            Assert.IsTrue(League().CollectionShown);
            Assert.IsTrue(Texts().Contains("vs Alder -- in progress"), string.Join(" | ", Texts()));
            Assert.IsTrue(Texts().Contains("vs Birch -- won"));
            Assert.IsNotNull(Named("Open Alder"));
            Assert.IsTrue(Texts().Contains("#1 Alder  -  3-0 (top 10%)"));
            Assert.IsTrue(Texts().Contains("#2 bshaftoe (you)  -  2-1 (top 20%)"));
            ScreenshotHelper.Capture("league");
        }

        [UnityTest]
        public IEnumerator League_TheFirstJoin_IsOfferedAsJoiningTheLeague()
        {
            var server = Serve();
            server.LeagueStatusJson = Running.Replace("\"joined\":true", "\"joined\":false");
            yield return OpenLeague(server);

            Assert.AreEqual("Join League", League().JoinText);
            StringAssert.Contains("granted a random Structure deck", League().QueueText);
        }

        [UnityTest]
        public IEnumerator League_AtTheMatchCap_JoiningIsOff()
        {
            var server = Serve();
            server.LeagueStatusJson = Running.Replace("\"in_progress_count\":1", "\"in_progress_count\":2");
            yield return OpenLeague(server);

            Assert.IsTrue(League().JoinShown);
            Assert.IsFalse(League().JoinEnabled);
        }

        [UnityTest]
        public IEnumerator League_JoiningWithNobodyWaiting_QueuesYou_AndLeavingTakesYouOut()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            yield return OpenLeague(server);

            server.LeagueStatusJson = Running.Replace("\"queued\":false", "\"queued\":true");
            yield return PhaseFiveSceneTests.Tap(Named("League join"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(1, server.Posts("/league/join").Count());
            StringAssert.StartsWith("You're in the queue, waiting for an opponent.", League().QueueText);
            Assert.IsTrue(League().LeaveShown);
            Assert.IsFalse(League().JoinShown);

            server.LeagueStatusJson = Running;
            yield return PhaseFiveSceneTests.Tap(Named("Leave queue"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual(1, server.Posts("/league/queue/leave").Count());
            Assert.IsFalse(League().LeaveShown);
            Assert.IsTrue(League().JoinEnabled);
        }

        [UnityTest]
        public IEnumerator League_JoiningWhenSomeoneIsWaiting_OpensTheNewGame()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.LeagueJoinJson = "{\"status\":\"paired\",\"game_id\":812,\"opponent_username\":\"Alder\"}";
            server.StateFor = id => PhaseFiveSceneTests.Load(405);
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("League join"));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=812")));
        }

        [UnityTest]
        public IEnumerator League_ARefusedJoin_ShowsTheServersMessage()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.LeagueJoinJson = "{\"status\":\"error\",\"message\":\"There is no League event running right now.\"}";
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("League join"));
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual("There is no League event running right now.", League().StatusText);
        }

        [UnityTest]
        public IEnumerator League_AMatchOpensItsGame()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.StateFor = id => PhaseFiveSceneTests.Load(405);
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("Open Alder"));
            yield return MainSceneTests.WaitFor<BoardScreen>();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/state?game_id=812")));
        }

        [UnityTest]
        public IEnumerator League_ThePreviousEvent_CanBeLookedAt_OrNotExist()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.LeagueLeaderboardJson = Leaderboard;
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("Previous event"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(Texts().Contains("There is no previous League event yet."), string.Join(" | ", Texts()));
            Assert.IsFalse(Texts().Contains("#1 Alder  -  3-0 (top 10%)"));

            yield return PhaseFiveSceneTests.Tap(Named("Current event"));
            yield return PhaseTwoSceneTests.Frames(8);
            Assert.IsTrue(Texts().Contains("#1 Alder  -  3-0 (top 10%)"));
        }

        // --- your collection ---------------------------------------------------------------------------

        private static string Cards(params (int id, string name, string color, string rarity, int copies)[] cards) =>
            "{\"status\":\"ok\",\"cards\":[" + string.Join(",", cards.Select(c =>
                $"{{\"card_id\":{c.id},\"catalog_card_id\":{c.id},\"name\":\"{c.name}\",\"color\":\"{c.color}\",\"rarity\":\"{c.rarity}\",\"rules_text\":\"{c.name} does a thing.\",\"value\":3,\"base_value\":3,\"copies\":{c.copies}}}")) + "]}";

        [UnityTest]
        public IEnumerator Collection_ShowsEveryCardYouOwn_WithHowManyOfEach()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            server.LeagueCollectionJson = Cards((1, "Calm", "white", "common", 3), (2, "Awe", "blue", "rare", 1), (3, "Joy", "red", "mythic", 1));
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("View my collection"));
            yield return MainSceneTests.WaitFor<LeagueCollectionScreen>();
            yield return PhaseTwoSceneTests.Frames(10);

            var screen = MainSceneTests.Screen<LeagueCollectionScreen>();
            Assert.AreEqual("5 cards, 3 different.", screen.SummaryText);
            Assert.AreEqual(3, screen.CardCount);
            Assert.IsTrue(Texts().Contains("x3"), string.Join(" | ", Texts()));
            ScreenshotHelper.Capture("league-collection");

            // Tapping a card shows it large, with how many you own.
            var calm = Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).First(t => t.name == "Card Calm");
            yield return PhaseFiveSceneTests.Tap(calm.GetComponent<Button>());
            Assert.IsTrue(screen.Detail.IsOpen);
            StringAssert.Contains("3 owned", screen.Detail.BodyText);
        }

        [UnityTest]
        public IEnumerator Collection_WhenEmpty_SaysHowToGetCards()
        {
            var server = Serve();
            server.LeagueStatusJson = Running;
            yield return OpenLeague(server);

            yield return PhaseFiveSceneTests.Tap(Named("View my collection"));
            yield return MainSceneTests.WaitFor<LeagueCollectionScreen>();
            yield return PhaseTwoSceneTests.Frames(10);

            Assert.IsTrue(Texts().Contains("Your collection is empty -- join the League to be granted a starter deck."));
        }

        // --- the deck, built in the game ---------------------------------------------------------------

        private static string BuilderJson(bool fixedPool = false, string defaults = "[1,1,2,3]", int minCards = 3) =>
            "{\"pool\":[" + string.Join(",",
                Card(1, "Calm", "white", "common", 3), Card(2, "Awe", "blue", "rare", 2), Card(3, "Joy", "red", "mythic", 1), Card(5, "Hope", "green", "common", 2)) + "],"
            + $"\"default_deck_card_ids\":{defaults},\"min_cards\":{minCards},\"rarity_limits\":{{\"mythic\":1,\"rare\":2}},"
            + $"\"sideboard_max\":{(fixedPool ? 0 : 3)},\"fixed_pool\":{(fixedPool ? "true" : "false")}}}";

        private static string Card(int id, string name, string color, string rarity, int copies) =>
            $"{{\"card_id\":{id},\"catalog_card_id\":{id},\"name\":\"{name}\",\"color\":\"{color}\",\"rarity\":\"{rarity}\",\"rules_text\":\"{name} does a thing.\",\"value\":3,\"base_value\":3,\"copies\":{copies}}}";

        private static JObject LeagueGame(bool fixedPool = false, string defaults = "[1,1,2,3]", int minCards = 3, bool youSubmitted = false) =>
            PhaseEightSceneTests.DuelWaiting(youSubmitted: youSubmitted, edit: s =>
            {
                s["league_deck_builder"] = JObject.Parse(BuilderJson(fixedPool, defaults, minCards));
                s["game"]["is_league_match"] = true;
            });

        private static BoardScreen Board() => PhaseFiveSceneTests.Board();

        private static Transform Cell(string name) =>
            Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).First(t => t.name == "Cell " + name);

        private static Button CardButton(string name) => Cell(name).Find("Card " + name).GetComponent<Button>();

        private static string BadgeText(string badge, string card)
        {
            var box = Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).FirstOrDefault(t => t.name == badge + " " + card);
            return box != null ? box.GetComponentInChildren<Text>().text : null;
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_ShowsYourPool_WithCopiesAndWhatsInTheDeck_StartingFromTheSuggestedDeck()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame() };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.AreEqual("Choose your deck", Board().Draft.TitleText);
            StringAssert.Contains("Build your deck from your collection", Board().Draft.StatusText);
            StringAssert.Contains("4 card(s) in your deck", Board().Draft.StatusText);
            Assert.AreEqual("x3", BadgeText("Copies", "Calm"));
            Assert.AreEqual("2", BadgeText("In deck", "Calm"));
            Assert.AreEqual("1", BadgeText("In deck", "Joy"));
            Assert.IsNull(BadgeText("In deck", "Hope"), "not in the deck, so no badge");
            Assert.IsNotNull(Named("Submit deck"));
            Assert.IsTrue(Named("Submit deck").interactable);
            ScreenshotHelper.Capture("league-deck-builder");
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_ATappedCard_OpensItsPopup_ToAddAndRemoveCopies()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame() };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            yield return PhaseFiveSceneTests.Tap(CardButton("Hope"));
            var popup = Board().Draft.LeaguePopup;
            Assert.IsTrue(popup.IsOpen);
            Assert.AreEqual("Hope", popup.Card.Name);
            Assert.AreEqual("0", popup.DeckText);

            yield return PhaseFiveSceneTests.Tap(Named("Deck plus"));
            yield return PhaseFiveSceneTests.Tap(Named("Deck plus"));
            Assert.AreEqual("2", popup.DeckText);
            Assert.IsFalse(Named("Deck plus").interactable, "both copies are in");
            Assert.AreEqual("2", BadgeText("In deck", "Hope"), "the grid behind follows");
            StringAssert.Contains("6 card(s) in your deck", Board().Draft.StatusText);
            ScreenshotHelper.Capture("league-card-popup");

            yield return PhaseFiveSceneTests.Tap(Named("Deck minus"));
            Assert.AreEqual("1", popup.DeckText);

            yield return PhaseFiveSceneTests.Tap(Named("Sideboard plus"));
            Assert.AreEqual("1", popup.SideboardText);
            Assert.AreEqual("SB 1", BadgeText("In sideboard", "Hope"));
            Assert.IsFalse(Named("Deck plus").interactable, "the other copy is in the sideboard");

            yield return PhaseFiveSceneTests.Tap(Named("Done"));
            Assert.IsFalse(popup.IsOpen);
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_BackClosesThePopup_BeforeAnythingElse()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame() };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            yield return PhaseFiveSceneTests.Tap(CardButton("Calm"));
            Assert.IsTrue(Board().Draft.LeaguePopup.IsOpen);

            Assert.IsTrue(Board().HandleBack());
            Assert.IsFalse(Board().Draft.LeaguePopup.IsOpen);
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_TooFewCards_SaysSo_AndCannotBeSubmitted()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame(defaults: "[1,1]", minCards: 3) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.AreEqual("Choose at least 3 cards (2 so far).", Board().Draft.ProblemText);
            Assert.IsFalse(Named("Submit deck").interactable);

            yield return PhaseFiveSceneTests.Tap(Named("Reset"));
            Assert.AreEqual("Choose at least 3 cards (2 so far).", Board().Draft.ProblemText, "the suggested deck is that small");
            yield return PhaseFiveSceneTests.Tap(CardButton("Hope"));
            yield return PhaseFiveSceneTests.Tap(Named("Deck plus"));
            yield return PhaseFiveSceneTests.Tap(Named("Done"));

            Assert.AreEqual(string.Empty, Board().Draft.ProblemText);
            Assert.IsTrue(Named("Submit deck").interactable);
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_SubmittingSendsTheDeckAndItsSideboardAsText()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame() };
            server.OnPost = (path, body) =>
            {
                server.State = LeagueGame(youSubmitted: true);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            yield return PhaseFiveSceneTests.Tap(CardButton("Hope"));
            yield return PhaseFiveSceneTests.Tap(Named("Sideboard plus"));
            yield return PhaseFiveSceneTests.Tap(Named("Sideboard plus"));
            yield return PhaseFiveSceneTests.Tap(Named("Done"));
            yield return PhaseFiveSceneTests.Tap(Named("Submit deck"));

            var post = server.Posts("/games/decklist").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.AreEqual("About\nName League deck\n\n2 Calm\n1 Awe\n1 Joy\n\nSideboard\n2 Hope", (string)post["decklist_text"]);
            StringAssert.Contains("You chose Mine.", Board().Draft.StatusText);
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_FromGameTwo_SwapsWithinAFixedPool_WithNoSideboardToEdit()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame(fixedPool: true, defaults: "[1,2,3]") };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            StringAssert.StartsWith("Sideboarding:", Board().Draft.StatusText);
            Assert.AreEqual("SB 2", BadgeText("In sideboard", "Calm"), "three owned, one in the deck");

            yield return PhaseFiveSceneTests.Tap(CardButton("Calm"));
            Assert.IsNull(Named("Sideboard plus"), "the sideboard is whatever the deck leaves over");
            yield return PhaseFiveSceneTests.Tap(Named("Deck plus"));
            Assert.AreEqual("SB 1", BadgeText("In sideboard", "Calm"));
            yield return PhaseFiveSceneTests.Tap(Named("Done"));
        }

        [UnityTest]
        public IEnumerator TheDeckBuilder_OnceYouHaveSubmitted_ItIsReplacedByTheWait()
        {
            var server = new PhaseFiveSceneTests.PlayServer { State = LeagueGame(youSubmitted: true) };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            StringAssert.Contains("You chose Mine.", Board().Draft.StatusText);
            Assert.IsNull(Named("Submit deck"));
        }

        [UnityTest]
        public IEnumerator ALeagueGame_ThatIsOver_OffersNoRematch()
        {
            var server = new PhaseFiveSceneTests.PlayServer
            {
                State = PhaseFiveSceneTests.Load(405, s =>
                {
                    s["game"]["status"] = "completed";
                    s["game"]["is_league_match"] = true;
                    s["game"]["created_by_user_id"] = 2;
                    s["game"]["winner_usernames"] = new JArray("bshaftoe");
                }),
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.IsNull(Named("Rematch action"));
        }
    }
}
