using System.Collections;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>The decklists and the deck builder, against the scripted server and the real captured card list and decks.</summary>
    public class PhaseEightDecksSceneTests
    {
        private static PhaseFiveSceneTests.PlayServer Serve() =>
            new PhaseFiveSceneTests.PlayServer { State = PhaseFiveSceneTests.Load(405) };

        private static string Fixture(string name) =>
            System.IO.File.ReadAllText(System.IO.Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        private static Button Named(string name) => PhaseFiveSceneTests.ButtonNamed(name);

        private static string[] Texts() =>
            Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        private static DeckBuilderScreen Builder() => MainSceneTests.Screen<DeckBuilderScreen>();

        private static IEnumerator OpenDecklists(PhaseFiveSceneTests.PlayServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return PhaseTwoSceneTests.Click("Decklists");
            yield return MainSceneTests.WaitFor<DecklistsScreen>();
            yield return PhaseTwoSceneTests.Frames(6);
        }

        private static IEnumerator OpenBuilder(PhaseFiveSceneTests.PlayServer server)
        {
            yield return OpenDecklists(server);
            yield return PhaseFiveSceneTests.Tap(Named("New deck"));
            yield return MainSceneTests.WaitFor<DeckBuilderScreen>();
            yield return PhaseTwoSceneTests.Frames(8);
        }

        private static string OwnDecks(params string[] names) =>
            "{\"status\":\"ok\",\"friends\":[],\"own\":[" +
            string.Join(",", names.Select((n, i) =>
                "{\"id\":" + (20 + i) + ",\"name\":\"" + n + "\",\"visibility\":\"private\",\"card_count\":15,\"sideboard_card_count\":0,\"updated_at\":\"2026-09-01 10:00:00\"}")) + "]}";

        private static InputField Field(string name) =>
            Object.FindObjectsByType<InputField>(FindObjectsInactive.Exclude).First(f => f.name == name);

        private static int CatalogCells() =>
            Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude).Count(t => t.name.StartsWith("Cell ") && t.GetComponentInParent<ScrollRect>() != null && t.parent.name == "Cards");

        private static BoardCard FirstOfColor(string color) =>
            JsonConvert.DeserializeObject<CatalogResponse>(Fixture("cards_catalog")).Cards
                .OrderBy(c => c.Name).First(c => c.Color == color);

        // --- the list ------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Decklists_ShowFriendsSharedDecks_WithACopyButton()
        {
            var server = Serve();
            yield return OpenDecklists(server);

            var texts = Texts();
            Assert.IsTrue(texts.Contains("Your decks"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Any(t => t.StartsWith("You haven't saved any decks")));
            Assert.IsTrue(texts.Contains("jceddy's decks"));
            Assert.IsTrue(texts.Contains("Combo-Aggro"));
            Assert.IsTrue(texts.Contains("15 cards + 5 sideboard  -  shared with friends"));
            Assert.IsNotNull(Named("Copy Combo-Aggro"));
            Assert.IsNull(Named("Edit Combo-Aggro"), "a friend's deck isn't yours to change");
            ScreenshotHelper.Capture("decklists");
        }

        [UnityTest]
        public IEnumerator YourDecks_CanBeEdited_OrDeletedAfterAsking()
        {
            var server = Serve();
            server.DecklistsJson = OwnDecks("Mine", "Other");
            yield return OpenDecklists(server);
            Assert.IsNotNull(Named("Edit Mine"));

            yield return PhaseFiveSceneTests.Tap(Named("Delete Mine"));
            var screen = MainSceneTests.Screen<DecklistsScreen>();
            var confirm = Object.FindAnyObjectByType<ScreenRouter>();
            Assert.IsNotNull(confirm);
            Assert.AreEqual(0, server.Posts("/decklists/delete").Count(), "asked first");

            // The confirm box is the last thing on the screen; answer it the way a tap would.
            var yes = Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude).First(b => b.GetComponentInChildren<Text>()?.text == "Delete" && b.name == "Button");
            yes.onClick.Invoke();
            yield return PhaseTwoSceneTests.Frames(6);

            Assert.AreEqual(20, (int)server.Posts("/decklists/delete").Single()["id"]);
            Assert.IsNotNull(screen);
        }

        // --- the builder ---------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator TheBuilder_ShowsEveryCard_AndAddsACopyWhenYouTapOne()
        {
            var server = Serve();
            yield return OpenBuilder(server);

            Assert.AreEqual("0 cards", Builder().CountText);
            Assert.AreEqual(133, CatalogCells());
            var card = FirstOfColor("white");

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());

            Assert.AreEqual("2 cards", Builder().CountText);
            Assert.AreEqual(2, Builder().Deck.CountOf(card.CardId));
            Assert.IsTrue(Texts().Contains("x2"), string.Join(" | ", Texts()));
            Assert.IsNotNull(Named("Less " + card.Name));
            ScreenshotHelper.Capture("deck-builder");
        }

        [UnityTest]
        public IEnumerator ThePlusAndMinusBesideALine_ChangeItsCopies()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var card = FirstOfColor("blue");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());

            yield return PhaseFiveSceneTests.Tap(Named("More " + card.Name));
            Assert.AreEqual(2, Builder().Deck.CountOf(card.CardId));
            yield return PhaseFiveSceneTests.Tap(Named("Less " + card.Name));
            yield return PhaseFiveSceneTests.Tap(Named("Less " + card.Name));

            Assert.AreEqual(0, Builder().Deck.Count);
            Assert.IsNull(Named("Less " + card.Name), "the line goes with its last copy");
        }

        [UnityTest]
        public IEnumerator TheColorFilter_NarrowsTheCatalog()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var red = JsonConvert.DeserializeObject<CatalogResponse>(Fixture("cards_catalog")).Cards.Count(c => c.Color == "red");

            yield return PhaseFiveSceneTests.Tap(Named("Filter red"));
            Assert.AreEqual(red, CatalogCells());

            yield return PhaseFiveSceneTests.Tap(Named("Filter red"));
            Assert.AreEqual(133, CatalogCells(), "tapping again clears it");
        }

        [UnityTest]
        public IEnumerator Searching_FindsCardsByName()
        {
            var server = Serve();
            yield return OpenBuilder(server);

            Field("Search").text = "altru";
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.AreEqual(1, CatalogCells());
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Card Altruism"));
        }

        [UnityTest]
        public IEnumerator TheInfoButton_ReadsACard()
        {
            var server = Serve();
            yield return OpenBuilder(server);

            yield return PhaseFiveSceneTests.Tap(Named("Inspect Altruism"));

            Assert.IsTrue(Builder().Detail.IsOpen);
            StringAssert.Contains("Altruism", Builder().Detail.BodyText);
            Assert.AreEqual(0, Builder().Deck.Count, "reading isn't adding");
        }

        [UnityTest]
        public IEnumerator Saving_NeedsANameAndCards_ThenCreatesTheDeck()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            Assert.IsFalse(Named("Save deck").interactable);

            var card = FirstOfColor("green");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());
            Assert.IsFalse(Named("Save deck").interactable, "still unnamed");

            Field("Deck name").text = "Green stuff";
            yield return PhaseTwoSceneTests.Frames(2);
            Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude).First(t => t.name == "Share toggle").isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            yield return PhaseFiveSceneTests.Tap(Named("Save deck"));
            yield return PhaseTwoSceneTests.Frames(4);

            var post = server.Posts("/decklists").Single();
            Assert.AreEqual("Green stuff", (string)post["name"]);
            Assert.AreEqual("friends", (string)post["visibility"]);
            CollectionAssert.AreEqual(new[] { card.CardId }, post["card_ids"].Select(t => (int)t).ToArray());
            Assert.AreEqual("Saved.", Builder().StatusText);
            Assert.AreEqual(42, Builder().Deck.DecklistId);
        }

        [UnityTest]
        public IEnumerator ACopyOfAFriendsDeck_OpensAsANewDeck()
        {
            var server = Serve();
            var catalog = JsonConvert.DeserializeObject<CatalogResponse>(Fixture("cards_catalog")).Cards;
            var cards = catalog.Take(3).ToList();
            server.DecklistViewJson = JsonConvert.SerializeObject(new
            {
                status = "ok",
                decklist = new { id = 5, name = "Combo-Aggro", visibility = "friends", owner_user_id = 1, cards, sideboard_cards = new object[0] },
            });
            yield return OpenDecklists(server);

            yield return PhaseFiveSceneTests.Tap(Named("Copy Combo-Aggro"));
            yield return MainSceneTests.WaitFor<DeckBuilderScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.AreEqual("Combo-Aggro (copy)", Builder().Deck.Name);
            Assert.AreEqual("Combo-Aggro (copy)", Field("Deck name").text);
            Assert.IsNull(Builder().Deck.DecklistId);
            Assert.AreEqual("3 cards", Builder().CountText);
        }

        [UnityTest]
        public IEnumerator LeavingWithUnsavedChanges_AsksFirst()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var card = FirstOfColor("red");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());

            var router = Object.FindAnyObjectByType<ScreenRouter>();
            Assert.IsTrue(Builder().HandleBack(), "Back is held while there are unsaved changes");
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsNotNull(MainSceneTests.Screen<DeckBuilderScreen>(), "still here");
            Assert.IsTrue(Texts().Any(t => t.StartsWith("This deck has changes you haven't saved")));
            Assert.IsNotNull(router);
        }

        [UnityTest]
        public IEnumerator TheInspectButton_IsAnEye_UnderTheValueDieInTheCardsTopRightCorner()
        {
            var server = Serve();
            yield return OpenBuilder(server);

            var button = Named("Inspect Altruism");
            var card = PhaseFiveSceneTests.Child("Card Altruism");
            Assert.IsNotNull(button);
            Assert.IsNull(button.GetComponentsInChildren<Text>().FirstOrDefault(t => t.text == "i"), "no letter any more");
            var eye = button.transform.Find("Eye").GetComponent<Image>();
            Assert.AreSame(UiIcons.Eye(), eye.sprite);

            // The corners of the card and the eye, in screen space.
            var cardCorners = new Vector3[4];
            ((RectTransform)card).GetWorldCorners(cardCorners);
            var eyeCorners = new Vector3[4];
            ((RectTransform)button.transform).GetWorldCorners(eyeCorners);
            var cardTop = cardCorners[1].y;
            var cardRight = cardCorners[2].x;
            var cardHeight = cardCorners[1].y - cardCorners[0].y;

            Assert.Greater(((RectTransform)button.transform).rect.width, 0f);
            Assert.Less(eyeCorners[2].x, cardRight, "inside the card");
            Assert.Greater(eyeCorners[2].x, cardRight - (cardCorners[2].x - cardCorners[0].x) * 0.12f, "against the right edge");
            Assert.Less(eyeCorners[1].y, cardTop - cardHeight * 0.14f, "below the die, which fills the top of the card");
            Assert.Greater(eyeCorners[0].y, cardTop - cardHeight * 0.40f, "but still up in the corner");
            ScreenshotHelper.Capture("deck-builder-eye");
        }

        // --- the sideboard -------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Sideboard_ATabSendsTappedCardsThere_AndShowsBothCounts()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var card = FirstOfColor("white");
            var other = FirstOfColor("blue");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());
            Assert.IsFalse(Builder().EditingSideboard);
            Assert.IsTrue(Texts().Contains("Deck (1)") && Texts().Contains("Sideboard (0)"), string.Join(" | ", Texts()));

            yield return PhaseFiveSceneTests.Tap(Named("Sideboard tab"));
            Assert.IsTrue(Builder().EditingSideboard);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + other.Name).GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());

            Assert.AreEqual(1, Builder().Deck.CountOf(card.CardId), "the deck is as it was");
            Assert.AreEqual(2, Builder().Deck.CountOf(card.CardId, sideboard: true));
            Assert.AreEqual(1, Builder().Deck.CountOf(other.CardId, sideboard: true));
            Assert.IsTrue(Texts().Contains("Deck (1)") && Texts().Contains("Sideboard (3)"), string.Join(" | ", Texts()));
            Assert.IsTrue(Texts().Contains("Sideboard: 3 cards"));
            Assert.IsTrue(Texts().Contains("SB x2"), "the sideboard copies show on the card, beside the deck's");
            Assert.IsTrue(Texts().Contains("x1"));
            ScreenshotHelper.Capture("deck-builder-sideboard");
        }

        [UnityTest]
        public IEnumerator Sideboard_ItsOwnLinesAddAndRemoveCopies_AndTheTabsKeepTheirLists()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var card = FirstOfColor("red");
            yield return PhaseFiveSceneTests.Tap(Named("Sideboard tab"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + card.Name).GetComponent<Button>());

            yield return PhaseFiveSceneTests.Tap(Named("More " + card.Name));
            Assert.AreEqual(2, Builder().Deck.CountOf(card.CardId, sideboard: true));
            yield return PhaseFiveSceneTests.Tap(Named("Less " + card.Name));
            Assert.AreEqual(1, Builder().Deck.CountOf(card.CardId, sideboard: true));

            yield return PhaseFiveSceneTests.Tap(Named("Deck tab"));
            Assert.IsNull(Named("Less " + card.Name), "the deck's list is empty");
            Assert.AreEqual(0, Builder().Deck.Count);

            yield return PhaseFiveSceneTests.Tap(Named("Sideboard tab"));
            yield return PhaseFiveSceneTests.Tap(Named("Less " + card.Name));
            Assert.AreEqual(0, Builder().Deck.SideboardCount);
        }

        [UnityTest]
        public IEnumerator Sideboard_IsSavedWithTheDeck()
        {
            var server = Serve();
            yield return OpenBuilder(server);
            var main = FirstOfColor("green");
            var side = FirstOfColor("black");
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + main.Name).GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(Named("Sideboard tab"));
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + side.Name).GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Card " + side.Name).GetComponent<Button>());
            Field("Deck name").text = "With a sideboard";
            yield return PhaseTwoSceneTests.Frames(2);

            yield return PhaseFiveSceneTests.Tap(Named("Save deck"));
            yield return PhaseTwoSceneTests.Frames(4);

            var post = server.Posts("/decklists").Single();
            CollectionAssert.AreEqual(new[] { main.CardId }, post["card_ids"].Select(t => (int)t).ToArray());
            CollectionAssert.AreEqual(new[] { side.CardId, side.CardId }, post["sideboard_card_ids"].Select(t => (int)t).ToArray());
        }

        [UnityTest]
        public IEnumerator Sideboard_ASavedDecksSideboardIsThereToChange()
        {
            var server = Serve();
            var catalog = JsonConvert.DeserializeObject<CatalogResponse>(Fixture("cards_catalog")).Cards;
            server.DecklistsJson = OwnDecks("Mine");
            server.DecklistViewJson = JsonConvert.SerializeObject(new
            {
                status = "ok",
                decklist = new { id = 20, name = "Mine", visibility = "private", owner_user_id = 2, cards = catalog.Take(3), sideboard_cards = catalog.Skip(3).Take(2) },
            });
            yield return OpenDecklists(server);

            yield return PhaseFiveSceneTests.Tap(Named("Edit Mine"));
            yield return MainSceneTests.WaitFor<DeckBuilderScreen>();
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(Texts().Contains("Deck (3)") && Texts().Contains("Sideboard (2)"), string.Join(" | ", Texts()));
            yield return PhaseFiveSceneTests.Tap(Named("Sideboard tab"));
            Assert.IsNotNull(Named("Less " + catalog[3].Name), "its sideboard is listed");
            yield return PhaseFiveSceneTests.Tap(Named("Less " + catalog[3].Name));

            Assert.IsTrue(Builder().Deck.HasUnsavedChanges, "taking a card out of the sideboard is a change");
        }
    }
}
