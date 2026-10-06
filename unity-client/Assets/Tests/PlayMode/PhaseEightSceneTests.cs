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
    /// <summary>
    /// Drafting on the board, against the same scripted server as the earlier phases: a draft game
    /// waits while its cards are chosen, then its deck is built, and starts once every deck is in.
    /// </summary>
    public class PhaseEightSceneTests
    {
        private static PhaseFiveSceneTests.PlayServer Serve(JObject state) => new PhaseFiveSceneTests.PlayServer { State = state };

        private static BoardScreen Board() => PhaseFiveSceneTests.Board();

        private static Button Named(string name) => PhaseFiveSceneTests.ButtonNamed(name);

        private static readonly string[] Colors = { "white", "blue", "black", "red", "green" };
        private static readonly string[] Rarities = { "common", "uncommon", "rare", "mythic" };

        private static JObject DraftCard(int id, string name, string color = "white", string rarity = "common", int value = 3) =>
            new JObject
            {
                ["card_id"] = id, ["catalog_card_id"] = id, ["name"] = name, ["color"] = color, ["rarity"] = rarity,
                ["value"] = value, ["base_value"] = value, ["rules_text"] = name + " does a thing.",
            };

        private static JArray Cards(int count, int firstId = 1) =>
            new JArray(Enumerable.Range(0, count).Select(i =>
                DraftCard(firstId + i, "Card" + (firstId + i), Colors[i % Colors.Length], Rarities[i % Rarities.Length], i % 5)));

        /// <summary>Game 405 as a Quick Draft that is waiting while its players pick.</summary>
        // Where the game state keeps a deck type's block: Chaos Draft's is Quick Draft's, and every sealed type shares one.
        private static string BlockKey(string deckType) =>
            deckType == "chaos_draft" ? "quick_draft" : deckType.Contains("sealed") ? "sealed_deck" : deckType;

        private static JObject DraftBoard(JObject block, System.Action<JObject> edit = null, string deckType = "quick_draft") => PhaseFiveSceneTests.Load(405, s =>
        {
            s["round"]["pending_decision"] = null;
            s["game"]["status"] = "waiting";
            s["game"]["format"] = "draft";
            s["game"]["deck_type"] = deckType;
            s["game"]["draft_match_id"] = 70;
            s[BlockKey(deckType)] = block;
            edit?.Invoke(s);
        });

        private static JObject Match(string status) => new JObject
        {
            ["draft_match_id"] = 70, ["match_game_number"] = 1, ["status"] = status, ["games_to_win"] = 2,
            ["next_game_id"] = null, ["your_wins"] = 0, ["opponent_wins"] = 0,
            ["players"] = new JArray(
                new JObject { ["user_id"] = 2, ["username"] = "bshaftoe", ["wins"] = 0, ["is_you"] = true },
                new JObject { ["user_id"] = 18, ["username"] = "BotSage", ["wins"] = 0, ["is_you"] = false }),
        };

        private static JObject Drafting(int round = 1, int stage = 1, string status = "picking", int packSize = 6)
        {
            var block = Match("drafting");
            block["drafting"] = new JObject
            {
                ["round"] = round, ["total_rounds"] = 4, ["stage"] = stage, ["total_stages"] = 2, ["pass_direction"] = "left",
                ["status"] = status, ["pack"] = Cards(packSize, 100), ["kept_so_far"] = Cards(stage == 1 ? 0 : 2, 200),
            };
            return block;
        }

        private static JObject DeckBuilding(int poolSize = 14, bool youSubmitted = false, bool botSubmitted = false, JToken caps = null)
        {
            var block = Match("deck_building");
            block["deck_building"] = new JObject
            {
                ["drafted_cards"] = Cards(poolSize, 300), ["deck_card_ids"] = null, ["previous_deck_card_ids"] = null,
                ["min_deck_size"] = 12, ["max_deck_size"] = 16, ["you_submitted"] = youSubmitted, ["opponent_submitted"] = botSubmitted,
                ["other_players"] = new JArray(new JObject { ["user_id"] = 18, ["username"] = "BotSage", ["submitted"] = botSubmitted }),
                ["rarity_caps"] = caps ?? new JArray(),
            };
            return block;
        }

        private static string[] Texts() =>
            Object.FindObjectsByType<Text>(FindObjectsInactive.Exclude).Select(t => t.text).ToArray();

        private static IEnumerator ToggleCard(string name)
        {
            var card = PhaseFiveSceneTests.Child("Card " + name);
            Assert.IsNotNull(card, "No card " + name + " on screen");
            yield return PhaseFiveSceneTests.Tap(card.GetComponent<Button>());
        }

        // --- Quick Draft: the pick ----------------------------------------------------------------

        [UnityTest]
        public IEnumerator AQuickDraftPick_ShowsThePile_AndTheStage()
        {
            var server = Serve(DraftBoard(Drafting(round: 2, stage: 2)));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsTrue(Board().Draft.IsShown);
            Assert.AreEqual("Draft round 2 of 4  -  second pick", Board().Draft.TitleText);
            Assert.AreEqual("Choose 2 cards to keep", Board().BannerText);
            Assert.AreEqual("Draft round 2 of 4  -  second pick  -  Match: you 0 - 0 BotSage", Board().RoundText);
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Card Card100"));
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Card Card105"));
            Assert.IsTrue(Texts().Contains("Kept so far (2)"), string.Join(" | ", Texts()));
            Assert.IsNull(PhaseFiveSceneTests.Child("Primary action"), "no table while drafting");
            ScreenshotHelper.Capture("draft-pick");
        }

        [UnityTest]
        public IEnumerator KeepIsOnlyAvailableOnceTwoCardsAreChosen_AndNoMoreThanTwoCanBe()
        {
            var server = Serve(DraftBoard(Drafting()));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsFalse(Named("Keep these cards").interactable);
            yield return ToggleCard("Card100");
            Assert.IsFalse(Named("Keep these cards").interactable);
            yield return ToggleCard("Card101");
            Assert.IsTrue(Named("Keep these cards").interactable);

            yield return ToggleCard("Card102");
            Assert.AreEqual(2, Board().Draft.SelectedCount, "a third card is refused");

            yield return ToggleCard("Card101");
            Assert.AreEqual(1, Board().Draft.SelectedCount);
            Assert.IsFalse(Named("Keep these cards").interactable);
        }

        [UnityTest]
        public IEnumerator Keeping_SendsTheRoundStageAndCards()
        {
            var server = Serve(DraftBoard(Drafting(round: 3, stage: 1)));
            server.OnPost = (path, body) =>
            {
                server.State = DraftBoard(Drafting(round: 3, stage: 1, status: "awaiting_others"));
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return ToggleCard("Card103");
            yield return ToggleCard("Card100");
            yield return PhaseFiveSceneTests.Tap(Named("Keep these cards"));

            var post = server.Posts("/games/draft/pick").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.AreEqual(3, (int)post["round"]);
            Assert.AreEqual(1, (int)post["stage"]);
            CollectionAssert.AreEquivalent(new[] { 100, 103 }, post["card_ids"].Select(t => (int)t).ToArray());
            Assert.AreEqual("Waiting for the others to pick", Board().BannerText);
            Assert.IsNull(Named("Keep these cards"), "nothing to keep while you wait");
        }

        [UnityTest]
        public IEnumerator AChoiceInProgress_SurvivesTheNextPoll()
        {
            var server = Serve(DraftBoard(Drafting()));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return ToggleCard("Card104");
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(1, Board().Draft.SelectedCount);
        }

        [UnityTest]
        public IEnumerator ANewStage_StartsWithNothingChosen()
        {
            var server = Serve(DraftBoard(Drafting(stage: 1)));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return ToggleCard("Card100");

            server.State = DraftBoard(Drafting(stage: 2));
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(0, Board().Draft.SelectedCount);
        }

        [UnityTest]
        public IEnumerator TheInfoButton_ShowsTheCardLarge()
        {
            var server = Serve(DraftBoard(Drafting()));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Inspect Card102"));

            Assert.AreEqual(0, Board().Draft.SelectedCount, "looking isn't choosing");
            Assert.IsTrue(Texts().Any(t => t.Contains("Card102 does a thing.")), string.Join(" | ", Texts()));
        }

        // --- deck building ------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator DeckBuilding_StartsWithEveryCardIn_AndSaysWhatsWrong()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 18)));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Build your deck (12 to 16 cards)", Board().Draft.TitleText);
            Assert.AreEqual("Build your deck", Board().BannerText);
            Assert.AreEqual("Building decks  -  Match: you 0 - 0 BotSage", Board().RoundText);
            Assert.AreEqual(18, Board().Draft.SelectedCount);
            Assert.AreEqual("Choose at most 16 cards (18 chosen).", Board().Draft.ProblemText);
            Assert.IsFalse(Named("Submit deck").interactable);

            yield return ToggleCard("Card300");
            yield return ToggleCard("Card301");
            Assert.AreEqual(16, Board().Draft.SelectedCount);
            Assert.IsTrue(Named("Submit deck").interactable);
            ScreenshotHelper.Capture("draft-deck-building");
        }

        [UnityTest]
        public IEnumerator ADeckThatIsTooSmall_CantBeSubmitted()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 14)));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Clear"));
            Assert.AreEqual(0, Board().Draft.SelectedCount);
            Assert.AreEqual("Choose at least 12 cards (0 so far).", Board().Draft.ProblemText);
            Assert.IsFalse(Named("Submit deck").interactable);

            yield return PhaseFiveSceneTests.Tap(Named("Select all"));
            Assert.AreEqual(14, Board().Draft.SelectedCount);
            Assert.IsTrue(Named("Submit deck").interactable);
        }

        [UnityTest]
        public IEnumerator SubmittingADeck_SendsTheChosenCards_AndThenWaitsForTheOthers()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 13)));
            server.OnPost = (path, body) =>
            {
                server.State = DraftBoard(DeckBuilding(poolSize: 13, youSubmitted: true));
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return ToggleCard("Card300");
            yield return PhaseFiveSceneTests.Tap(Named("Submit deck"));

            var post = server.Posts("/games/draft/deck").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.AreEqual(12, post["card_ids"].Count());
            Assert.IsFalse(post["card_ids"].Any(t => (int)t == 300), "the card taken out isn't sent");
            Assert.AreEqual("Waiting for BotSage's deck", Board().BannerText);
            StringAssert.Contains("Waiting for BotSage", Board().Draft.StatusText);
            Assert.IsNull(Named("Submit deck"));
        }

        [UnityTest]
        public IEnumerator OnceEveryDeckIsIn_TheGameIsStarted()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 13, youSubmitted: true, botSubmitted: true)));
            server.OnPost = (path, body) =>
            {
                if (path == "/games/start")
                {
                    server.State = PhaseFiveSceneTests.Load(406);
                }

                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(1, server.Posts("/games/start").Count(), string.Join("\n", server.Calls));
            Assert.IsFalse(Board().Draft.IsShown, "the table replaces the draft");
        }

        [UnityTest]
        public IEnumerator WhileDecksAreOutstanding_TheGameIsNotStarted()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 13, youSubmitted: true, botSubmitted: false)));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(0, server.Posts("/games/start").Count());
        }

        // --- Winston Draft ------------------------------------------------------------------------

        private static JObject Winston(bool yourTurn = true, int pile = 2, int deckLeft = 30, System.Action<JObject> edit = null)
        {
            var block = Match("drafting");
            block["drafting"] = new JObject
            {
                ["is_your_turn"] = yourTurn, ["current_turn_username"] = yourTurn ? "bshaftoe" : "BotSage", ["current_pile_number"] = pile,
                ["pile_sizes"] = new JArray(2, 1, 1), ["remaining_deck_count"] = deckLeft,
                ["current_pile_cards"] = yourTurn ? Cards(2, 100) : new JArray(),
                ["drafted_so_far"] = Cards(3, 200),
                ["other_players"] = new JArray(new JObject { ["user_id"] = 18, ["username"] = "BotSage", ["drafted_card_count"] = 4, ["last_take_pile_number"] = 3, ["last_drew_from_deck"] = false }),
                ["team_drafted_cards"] = null,
            };
            edit?.Invoke((JObject)block["drafting"]);
            return DraftBoard(block, null, "winston_draft");
        }

        [UnityTest]
        public IEnumerator Winston_OnYourTurn_ShowsThePileYouAreLookingAt_WithTakeAndPass()
        {
            var server = Serve(Winston());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Winston Draft", Board().Draft.TitleText);
            Assert.AreEqual("Take pile 2, or pass", Board().BannerText);
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Card Card100"), "the pile you're looking at is face up");
            var texts = Texts();
            Assert.IsTrue(texts.Contains("Pile 1 (2 cards)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("Pile 2 (1 card)"));
            Assert.IsTrue(texts.Contains("30 cards left in the deck."));
            Assert.IsTrue(texts.Contains("BotSage has drafted 4 cards so far, last taking pile 3."));
            Assert.IsTrue(texts.Contains("Drafted so far (3)"));
            Assert.AreEqual("Pass", Named("Pass").GetComponentInChildren<Text>().text);
            ScreenshotHelper.Capture("draft-winston");
        }

        [UnityTest]
        public IEnumerator Winston_TakingAndPassing_SendTheAction()
        {
            var server = Serve(Winston());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Pass"));
            yield return PhaseFiveSceneTests.Tap(Named("Take pile 2"));

            var posts = server.Posts("/games/draft/winston-pick").ToList();
            Assert.AreEqual(2, posts.Count);
            Assert.AreEqual("pass", (string)posts[0]["action"]);
            Assert.AreEqual("take", (string)posts[1]["action"]);
            Assert.AreEqual(405, (int)posts[0]["game_id"]);
        }

        [UnityTest]
        public IEnumerator Winston_WhenItIsNotYourTurn_OffersNothing()
        {
            var server = Serve(Winston(yourTurn: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Waiting for BotSage's pick", Board().BannerText);
            Assert.IsNull(Named("Take pile 2"));
            Assert.IsNull(Named("Pass"));
            Assert.IsNull(PhaseFiveSceneTests.Child("Card Card100"), "the other piles are face down");
        }

        [UnityTest]
        public IEnumerator Winston_PassingTheLastPile_WithNothingLeftToDraw_AsksFirst()
        {
            var server = Serve(Winston(pile: 3, deckLeft: 1));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            Assert.AreEqual("Pass (draw from deck)", Named("Pass (draw from deck)").GetComponentInChildren<Text>().text);

            yield return PhaseFiveSceneTests.Tap(Named("Pass (draw from deck)"));

            Assert.IsTrue(Board().Confirm.IsOpen);
            StringAssert.Contains("you'll get nothing", Board().Confirm.MessageText);
            Assert.AreEqual(0, server.Posts("/games/draft/winston-pick").Count());

            Board().Confirm.Press(true);
            yield return PhaseTwoSceneTests.Frames(4);
            Assert.AreEqual("pass", (string)server.Posts("/games/draft/winston-pick").Single()["action"]);
        }

        [UnityTest]
        public IEnumerator Winston_PassingTheLastPile_WithCardsLeft_JustPasses()
        {
            var server = Serve(Winston(pile: 3, deckLeft: 5));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Pass (draw from deck)"));

            Assert.IsFalse(Board().Confirm.IsOpen);
            Assert.AreEqual(1, server.Posts("/games/draft/winston-pick").Count());
        }

        // --- Grid Draft ---------------------------------------------------------------------------

        private static JObject Grid(bool yourTurn = true, int picks = 0, int[] taken = null)
        {
            var block = Match("drafting");
            var cells = new JArray();
            for (var i = 0; i < 9; i++)
            {
                cells.Add(taken != null && taken.Contains(i) ? JValue.CreateNull() : DraftCard(100 + i, "Grid" + i, Colors[i % 5], "common", i % 5));
            }

            block["drafting"] = new JObject
            {
                ["is_your_turn"] = yourTurn, ["current_turn_username"] = yourTurn ? "bshaftoe" : "BotSage", ["current_round"] = 2, ["total_rounds"] = 9,
                ["grid_size"] = 3, ["first_picker_user_id"] = 2, ["picks_this_round"] = picks, ["total_picks_per_round"] = 2, ["grid_cards"] = cells,
                ["remaining_deck_count"] = 20, ["drafted_so_far"] = Cards(2, 200), ["opponent_drafted_so_far"] = new JArray(),
                ["other_players_drafted_so_far"] = new JArray(new JObject { ["user_id"] = 18, ["username"] = "BotSage", ["drafted_so_far"] = Cards(1, 300) }),
                ["teams_drafted_so_far"] = null,
            };
            return DraftBoard(block, null, "grid_draft");
        }

        [UnityTest]
        public IEnumerator Grid_ShowsTheGrid_AndWhatEveryoneHasTaken()
        {
            var server = Serve(Grid());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Grid Draft  -  round 2 of 9 (pick 1 of 2)", Board().Draft.TitleText);
            Assert.AreEqual("Choose a row or column", Board().BannerText);
            Assert.IsNotNull(PhaseFiveSceneTests.Child("Card Grid4"));
            var texts = Texts();
            Assert.IsTrue(texts.Contains("Row 1 (3)"), string.Join(" | ", texts));
            Assert.IsTrue(texts.Contains("Col 3 (3)"));
            Assert.IsTrue(texts.Contains("20 cards left in the pool."));
            Assert.IsTrue(texts.Contains("BotSage's drafted so far (1)"));
            Assert.IsFalse(Named("Take").interactable, "nothing chosen yet");
            ScreenshotHelper.Capture("draft-grid");
        }

        [UnityTest]
        public IEnumerator Grid_ChoosingARowAndColumn_SendsItsAxisAndIndex()
        {
            var server = Serve(Grid());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            yield return PhaseFiveSceneTests.Tap(Named("Pick row 2"));
            Assert.AreEqual("row 2", Board().Draft.SelectedLine);
            Assert.AreEqual("Take row 2 (3 cards)", Named("Take").GetComponentInChildren<Text>().text);

            yield return PhaseFiveSceneTests.Tap(Named("Pick column 3"));
            Assert.AreEqual("column 3", Board().Draft.SelectedLine, "choosing another line changes the choice");

            yield return PhaseFiveSceneTests.Tap(Named("Take"));

            var post = server.Posts("/games/draft/grid-pick").Single();
            Assert.AreEqual("column", (string)post["axis"]);
            Assert.AreEqual(2, (int)post["index"], "the server counts from 0");
        }

        [UnityTest]
        public IEnumerator Grid_ALineWithNothingLeft_CantBeChosen()
        {
            var server = Serve(Grid(picks: 1, taken: new[] { 3, 4, 5 }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsFalse(Named("Pick row 2").interactable);
            Assert.IsTrue(Named("Pick row 1").interactable);
            Assert.IsTrue(Texts().Contains("Col 1 (2)"));
        }

        [UnityTest]
        public IEnumerator Grid_WhenItIsNotYourTurn_YouCanOnlyLook()
        {
            var server = Serve(Grid(yourTurn: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Waiting for BotSage's pick", Board().BannerText);
            Assert.IsFalse(Named("Pick row 1").interactable);
            Assert.IsNull(Named("Take"));
        }

        // --- Rotisserie and Tiered Rotisserie -----------------------------------------------------

        private static JObject Rotisserie(bool yourTurn = true, bool tiered = false)
        {
            var block = Match("drafting");
            var drafting = new JObject
            {
                ["is_your_turn"] = yourTurn, ["current_turn_username"] = yourTurn ? "bshaftoe" : "BotSage", ["cutoff_count"] = 14,
                ["picks_made"] = 3, ["total_picks_needed"] = 28, ["pool_cards"] = Cards(20, 100), ["drafted_so_far"] = Cards(2, 200),
                ["opponent_drafted_so_far"] = new JArray(),
                ["other_players_drafted_so_far"] = new JArray(new JObject { ["user_id"] = 18, ["username"] = "BotSage", ["drafted_so_far"] = Cards(1, 300) }),
                ["teams_drafted_so_far"] = null,
            };
            if (tiered)
            {
                drafting["current_tier_index"] = 1;
                drafting["current_tier_label"] = "rare";
                drafting["tiers"] = new JArray(
                    new JObject { ["label"] = "common", ["cutoff_count"] = 8, ["status"] = "completed" },
                    new JObject { ["label"] = "rare", ["cutoff_count"] = 4, ["status"] = "current" },
                    new JObject { ["label"] = null, ["cutoff_count"] = 2, ["status"] = "upcoming" });
                drafting["picks_made_this_tier"] = 3;
                drafting["total_picks_needed_this_tier"] = 8;
                drafting["total_picks_made"] = 19;
            }

            block["drafting"] = drafting;
            return DraftBoard(block, null, tiered ? "tiered_rotisserie_draft" : "rotisserie_draft");
        }

        [UnityTest]
        public IEnumerator Rotisserie_ShowsTheSharedPool_AndDraftsTheCardYouChoose()
        {
            var server = Serve(Rotisserie());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Rotisserie Draft  -  pick 4 of 28", Board().Draft.TitleText);
            Assert.AreEqual("Choose a card", Board().BannerText);
            Assert.IsFalse(Named("Draft").interactable);

            yield return ToggleCard("Card105");
            Assert.AreEqual("Draft Card105", Named("Draft").GetComponentInChildren<Text>().text);

            yield return ToggleCard("Card107");
            Assert.AreEqual(1, Board().Draft.SelectedCount, "one card at a time");
            yield return PhaseFiveSceneTests.Tap(Named("Draft"));

            var post = server.Posts("/games/draft/rotisserie-pick").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.AreEqual(107, (int)post["card_id"]);
            Assert.IsTrue(Texts().Contains("BotSage's drafted so far (1)"));
            ScreenshotHelper.Capture("draft-rotisserie");
        }

        [UnityTest]
        public IEnumerator Rotisserie_WhenItIsNotYourTurn_TheCardsAreOnlyForLooking()
        {
            var server = Serve(Rotisserie(yourTurn: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Waiting for BotSage's pick", Board().BannerText);
            Assert.IsNull(Named("Draft"));
            var card = PhaseFiveSceneTests.Child("Card Card105");
            Assert.IsNotNull(card);
            Assert.IsNull(card.GetComponent<Button>(), "you can look (with the i button) but not choose");
            Assert.IsNotNull(Named("Inspect Card105"));
        }

        [UnityTest]
        public IEnumerator TieredRotisserie_ShowsTheTiers_AndPostsToItsOwnEndpoint()
        {
            var server = Serve(Rotisserie(tiered: true));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.AreEqual("Tiered Rotisserie Draft  -  Rare  -  pick 4 of 8", Board().Draft.TitleText);
            StringAssert.Contains("Tiers:  Common (done)  >  [Rare, 4 picks each]  >  Tier 3 (2 picks each)", Board().Draft.StatusText);

            yield return ToggleCard("Card102");
            yield return PhaseFiveSceneTests.Tap(Named("Draft"));

            Assert.AreEqual(0, server.Posts("/games/draft/rotisserie-pick").Count());
            Assert.AreEqual(102, (int)server.Posts("/games/draft/tiered-rotisserie-pick").Single()["card_id"]);
        }

        [UnityTest]
        public IEnumerator ASelection_IsKeptWhileThePoolIsUnchanged_EvenThroughARefresh()
        {
            var server = Serve(Rotisserie());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return ToggleCard("Card105");

            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(1, Board().Draft.SelectedCount);
            Assert.AreEqual("Draft Card105", Named("Draft").GetComponentInChildren<Text>().text);
        }

        // --- Sealed -------------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator ASealedDeck_StartsAtDeckBuilding_WithNoDraftBefore()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 20), null, "sealed_deck"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsTrue(Board().Draft.IsShown);
            Assert.AreEqual("Build your deck", Board().BannerText);
            Assert.AreEqual("Build your deck (12 to 16 cards)", Board().Draft.TitleText);
            Assert.AreEqual(20, Board().Draft.SelectedCount);
        }

        [UnityTest]
        public IEnumerator ASealedPoolOfTheDay_ExplainsItsRarityCaps_AndHoldsYouToThem()
        {
            var caps = new JObject { ["rare"] = 1, ["mythic"] = 1 };
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 14, caps: caps), null, "sealed_pool_of_the_day"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            StringAssert.Contains("At most 1 rare and 1 mythic.", Board().Draft.StatusText);
            Assert.AreEqual("At most 1 rare cards (3 chosen).", Board().Draft.ProblemText);
            Assert.IsFalse(Named("Submit deck").interactable);

            // The pool's rarities run common, uncommon, rare, mythic card by card: three rares, three mythics.
            yield return ToggleCard("Card302");
            yield return ToggleCard("Card306");
            Assert.AreEqual("At most 1 mythic cards (3 chosen).", Board().Draft.ProblemText, "the rares are fine now");
            ScreenshotHelper.Capture("draft-sealed-caps");
        }

        // --- Chaos Draft --------------------------------------------------------------------------

        private const string TwoEffects =
            "\"effect_1\":{\"id\":11,\"rarity\":\"rare\",\"shape\":\"x\",\"rules_text\":\"Draw a card.\"}," +
            "\"effect_2\":{\"id\":12,\"rarity\":\"common\",\"shape\":\"y\",\"rules_text\":\"Gain a point.\"}";

        private static string OfferJson(bool team = false, string phase = "choose", int? proposer = null, bool ready = false) =>
            "{\"status\":\"ok\",\"offer\":{" + TwoEffects + ",\"is_team_offer\":" + (team ? "true" : "false") + ",\"phase\":\"" + phase +
            "\",\"proposer_game_player_id\":" + (proposer.HasValue ? proposer.ToString() : "null") + "},\"round_ready\":" + (ready ? "true" : "false") + "}";

        private const string NoOffer = "{\"status\":\"ok\",\"offer\":null,\"round_ready\":true}";

        private static JObject ChaosGame(System.Action<JObject> edit = null) => PhaseFiveSceneTests.Load(406, s =>
        {
            s["game"]["deck_type"] = "chaos_draft";
            edit?.Invoke(s);
        });

        [UnityTest]
        public IEnumerator Chaos_TheRoundOpensWithAChoiceOfTwoEffects_AndPlayWaitsForIt()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsTrue(Board().ChaosOverlay.IsOpen);
            Assert.AreEqual("Choose a Chaos effect", Board().BannerText);
            StringAssert.Contains("Choose one of these two effects", Board().ChaosOverlay.StatusText);
            Assert.IsTrue(Texts().Contains("Rare  -  Draw a card."), string.Join(" | ", Texts()));
            Assert.IsTrue(Texts().Contains("Common  -  Gain a point."));
            Assert.IsFalse(Named("Primary action").interactable, "nobody plays until everyone has chosen");
            ScreenshotHelper.Capture("chaos-offer");
        }

        [UnityTest]
        public IEnumerator Chaos_ChoosingAnEffectThenACard_AttachesIt()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson();
            server.OnPost = (path, body) =>
            {
                server.ChaosOfferJson = NoOffer;
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(Named("Effect 12"));
            Assert.AreEqual(12, Board().ChaosOverlay.ChosenEffectId);
            StringAssert.Contains("Attach it to which card?", Board().ChaosOverlay.StatusText);
            Assert.IsFalse(Named("Attach").interactable, "no card chosen yet");

            var hand = ChaosGame()["you"]["hand"][0];
            var cardName = (string)hand["name"];
            var cardId = (int)hand["card_id"];
            var cell = UnityEngine.Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude)
                .First(t => t.name == "Card " + cardName && t.GetComponentInParent<Canvas>() != null && IsUnder(t, "Chaos overlay"));
            yield return PhaseFiveSceneTests.Tap(cell.GetComponent<Button>());
            Assert.IsTrue(Named("Attach").interactable);

            yield return PhaseFiveSceneTests.Tap(Named("Attach"));

            var post = server.Posts("/games/chaos-draft-effect").Single();
            Assert.AreEqual("choose", (string)post["action"]);
            Assert.AreEqual(12, (int)post["chosen_effect_id"]);
            Assert.AreEqual(cardId, (int)post["attach_game_card_id"]);
            Assert.IsFalse(Board().ChaosOverlay.IsOpen, "done: back to the table");
            Assert.IsTrue(Named("Primary action").interactable, "and play can go on");
        }

        private static bool IsUnder(Transform t, string ancestorName)
        {
            for (var p = t; p != null; p = p.parent)
            {
                if (p.name == ancestorName)
                {
                    return true;
                }
            }

            return false;
        }

        [UnityTest]
        public IEnumerator Chaos_BackFromTheCardsReturnsToTheEffects()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(Named("Effect 11"));

            yield return PhaseFiveSceneTests.Tap(Named("Back to effects"));

            Assert.IsNull(Board().ChaosOverlay.ChosenEffectId);
            Assert.IsNotNull(Named("Effect 11"));
        }

        [UnityTest]
        public IEnumerator Chaos_WhenYouAreDone_ButOthersAreNot_YouWaitWithNoPanel()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = "{\"status\":\"ok\",\"offer\":null,\"round_ready\":false}";
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsFalse(Board().ChaosOverlay.IsOpen);
            Assert.AreEqual("Waiting for everyone to choose their Chaos effect", Board().BannerText);
            Assert.IsFalse(Named("Primary action").interactable);
        }

        [UnityTest]
        public IEnumerator Chaos_TheOverlayGoesAwayOnceTheRoundMovesOn()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.IsTrue(Board().ChaosOverlay.IsOpen);

            server.ChaosOfferJson = NoOffer;
            yield return PhaseFiveSceneTests.Poll();

            Assert.IsFalse(Board().ChaosOverlay.IsOpen);
        }

        [UnityTest]
        public IEnumerator Chaos_ATeamProposal_IsAgreedToOrSentBack()
        {
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson(team: true, phase: "confirm", proposer: 99999);
            server.OnPost = (path, body) =>
            {
                server.ChaosOfferJson = NoOffer;
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.AreEqual("Your partner proposed a Chaos effect", Board().BannerText);
            StringAssert.Contains("proposed a Chaos effect. Do you agree?", Board().ChaosOverlay.StatusText);

            yield return PhaseFiveSceneTests.Tap(Named("Agree"));

            var post = server.Posts("/games/chaos-draft-effect").Single();
            Assert.AreEqual("confirm", (string)post["action"]);
            Assert.IsTrue((bool)post["approve"]);
        }

        [UnityTest]
        public IEnumerator Chaos_AProposerWaitsForTheirPartner_WithNothingToPress()
        {
            var mine = (int)ChaosGame()["you"]["game_player_id"];
            var server = Serve(ChaosGame());
            server.ChaosOfferJson = OfferJson(team: true, phase: "confirm", proposer: mine);
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsFalse(Board().ChaosOverlay.IsOpen);
            Assert.AreEqual("Waiting for your partner to confirm your Chaos effect", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator Chaos_ACardCarryingAnEffect_IsMarked_AndTheDetailSaysWhatItDoes()
        {
            var server = Serve(ChaosGame(s =>
            {
                var card = (JObject)s["you"]["hand"][0];
                card["chaos_effect"] = JObject.Parse("{\"id\":3,\"rarity\":\"mythic\",\"shape\":\"z\",\"rules_text\":\"Does the thing.\"}");
                card["chaos_value_delta"] = 2;
            }));
            var name = (string)ChaosGame(s => { })["you"]["hand"][0]["name"];
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var card = PhaseFiveSceneTests.Child("Card " + name);
            Assert.IsNotNull(card.Find("Chaos badge"), "a pill on the card");
        }

        // --- the loop shortcut --------------------------------------------------------------------

        private static JObject WithLoop(int cap = 20) => ChaosGame(s =>
            s["game"]["chaos_loop_shortcut"] = JObject.Parse(
                "{\"game_player_id\":" + (int)s["you"]["game_player_id"] + ",\"kind\":\"spawn\",\"cap\":" + cap + ",\"label\":\"Spawn tokens up to 20 times?\"}"));

        [UnityTest]
        public IEnumerator Loop_OffersToApplyItUpToTheCap_AndSendsTheCount()
        {
            var server = Serve(WithLoop());
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsTrue(Board().LoopShortcut.IsOpen);
            Assert.AreEqual("Spawn tokens up to 20 times?", Board().LoopShortcut.MessageText);
            Assert.AreEqual(20, Board().LoopShortcut.Times, "starts at the most");

            yield return PhaseFiveSceneTests.Tap(Named("More times"));
            Assert.AreEqual(20, Board().LoopShortcut.Times, "no more than the cap");
            yield return PhaseFiveSceneTests.Tap(Named("Fewer times"));
            yield return PhaseFiveSceneTests.Tap(Named("Fewer times"));
            Assert.AreEqual(18, Board().LoopShortcut.Times);

            yield return PhaseFiveSceneTests.Tap(Named("Apply loop"));

            Assert.AreEqual(18, (int)server.Posts("/games/apply-chaos-loop-shortcut").Single()["count"]);
            Assert.IsFalse(Board().LoopShortcut.IsOpen);
        }

        [UnityTest]
        public IEnumerator Loop_NotNow_IsNotAskedAgainOnTheNextPoll()
        {
            var server = Serve(WithLoop());
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(Named("Not now"));
            Assert.IsFalse(Board().LoopShortcut.IsOpen);
            yield return PhaseFiveSceneTests.Poll();

            Assert.IsFalse(Board().LoopShortcut.IsOpen, "once per offer");
            Assert.AreEqual(0, server.Posts("/games/apply-chaos-loop-shortcut").Count());
        }

        [UnityTest]
        public IEnumerator Loop_AnOfferAboutSomeoneElse_IsNotShownToYou()
        {
            var server = Serve(ChaosGame(s => s["game"]["chaos_loop_shortcut"] = JObject.Parse(
                "{\"game_player_id\":99999,\"kind\":\"spawn\",\"cap\":5,\"label\":\"Not yours\"}")));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsFalse(Board().LoopShortcut.IsOpen);
        }

        [UnityTest]
        public IEnumerator ChaosDraft_DraftsLikeQuickDraft()
        {
            var server = Serve(DraftBoard(Drafting(), null, "chaos_draft"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            Assert.IsTrue(Board().Draft.IsShown);
            Assert.AreEqual("Draft round 1 of 4  -  first pick", Board().Draft.TitleText);
            Assert.AreEqual("Choose 2 cards to keep", Board().BannerText);
        }

        // --- a custom duel's deck -----------------------------------------------------------------

        private const string DuelRulesJson =
            "{\"preset\":\"power\",\"min_cards\":3,\"rarity_limits\":{\"mythic\":1,\"rare\":2},\"duplicate_limits\":{\"common\":2},\"even_color_distribution_rarities\":[]}";

        private static JObject DuelWaiting(bool youSubmitted = false, bool otherSubmitted = false, System.Action<JObject> edit = null) =>
            PhaseFiveSceneTests.Load(405, s =>
            {
                s["round"]["pending_decision"] = null;
                s["game"]["status"] = "waiting";
                s["game"]["format"] = "duel";
                s["game"]["deck_type"] = "custom_duel";
                s["game"]["duel_deck_rules"] = JObject.Parse(DuelRulesJson);
                var me = (int)s["you"]["game_player_id"];
                foreach (var player in s["players"])
                {
                    player["deck_submitted"] = (int)player["game_player_id"] == me ? youSubmitted : otherSubmitted;
                    player["custom_deck_name"] = youSubmitted && (int)player["game_player_id"] == me ? "Mine" : null;
                }

                edit?.Invoke(s);
            });

        private const string TwoDecks =
            "{\"status\":\"ok\",\"friends\":[],\"own\":[" +
            "{\"id\":30,\"name\":\"Mine\",\"visibility\":\"private\",\"card_count\":20,\"sideboard_card_count\":0}," +
            "{\"id\":31,\"name\":\"Other\",\"visibility\":\"private\",\"card_count\":15,\"sideboard_card_count\":0}]}";

        [UnityTest]
        public IEnumerator Duel_TheWaitingRoomOffersYourSavedDecks_AndSubmitsTheOneYouChoose()
        {
            var server = Serve(DuelWaiting());
            server.DecklistsJson = TwoDecks;
            server.OnPost = (path, body) =>
            {
                server.State = DuelWaiting(youSubmitted: true);
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(Board().Draft.IsShown);
            Assert.AreEqual("Choose your deck", Board().BannerText);
            StringAssert.Contains("At least 3 cards. At most 2 rare, 1 mythic.", Board().Draft.StatusText);
            Assert.IsFalse(Named("Submit deck").interactable, "no deck chosen yet");
            ScreenshotHelper.Capture("duel-deck");

            PhaseFiveSceneTests.Child("Deck Other").GetComponent<Toggle>().isOn = true;
            yield return PhaseTwoSceneTests.Frames(2);
            Assert.IsTrue(Named("Submit deck").interactable);

            yield return PhaseFiveSceneTests.Tap(Named("Submit deck"));

            var post = server.Posts("/games/decklist").Single();
            Assert.AreEqual(405, (int)post["game_id"]);
            Assert.AreEqual(31, (int)post["saved_decklist_id"]);
            StringAssert.Contains("You chose Mine.", Board().Draft.StatusText);
            StringAssert.StartsWith("Waiting for ", Board().BannerText);
        }

        [UnityTest]
        public IEnumerator Duel_WithNoSavedDecks_SaysHowToGetOne()
        {
            var server = Serve(DuelWaiting());
            server.DecklistsJson = "{\"status\":\"ok\",\"friends\":[],\"own\":[]}";
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseTwoSceneTests.Frames(8);

            Assert.IsTrue(Texts().Any(t => t.StartsWith("You have no saved decks yet")), string.Join(" | ", Texts()));
            Assert.IsNull(Named("Submit deck"));
        }

        [UnityTest]
        public IEnumerator Duel_OnceEveryDeckIsIn_TheGameStarts()
        {
            var server = Serve(DuelWaiting(youSubmitted: true, otherSubmitted: true));
            server.OnPost = (path, body) =>
            {
                if (path == "/games/start")
                {
                    server.State = PhaseFiveSceneTests.Load(406);
                }

                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);
            yield return PhaseFiveSceneTests.Poll();

            Assert.AreEqual(1, server.Posts("/games/start").Count(), string.Join("\n", server.Calls));
        }

        [UnityTest]
        public IEnumerator Duel_Sideboarding_BuildsTheNextDeckFromThePool_AndSendsItAsText()
        {
            var pool = new JArray(
                DraftCard(1, "Calm", "white", "common"), DraftCard(1, "Calm", "white", "common"), DraftCard(1, "Calm", "white", "common"),
                DraftCard(2, "Awe", "blue", "rare"), DraftCard(3, "Joy", "red", "mythic"));
            var server = Serve(DuelWaiting(edit: s =>
            {
                s["power_duel_sideboard_pool"] = pool;
                s["power_duel_previous_deck_card_ids"] = new JArray(1, 2, 3);
            }));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            StringAssert.Contains("Sideboarding is on", Board().Draft.StatusText);
            Assert.AreEqual(3, Board().Draft.SelectedCount, "starts from last game's deck");
            Assert.IsTrue(Named("Submit deck").interactable);
            Assert.IsNotNull(Named("Last game's deck"));

            var calms = Object.FindObjectsByType<Transform>(FindObjectsInactive.Exclude)
                .Where(t => t.name == "Card Calm").OrderBy(t => t.parent.GetSiblingIndex()).ToList();
            Assert.AreEqual(3, calms.Count);
            yield return PhaseFiveSceneTests.Tap(calms[1].GetComponent<Button>());
            yield return PhaseFiveSceneTests.Tap(calms[2].GetComponent<Button>());
            Assert.AreEqual("At most 2 copies of Calm (3 chosen).", Board().Draft.ProblemText);
            Assert.IsFalse(Named("Submit deck").interactable);
        }

        // --- playing from the discard pile (Harmony, Grief, Angst, Melancholy) -------------------------

        private static JObject HarmonyGame(bool discardPlayable = true) => PhaseFiveSceneTests.Load(406, s =>
        {
            foreach (var card in (JArray)s["you"]["hand"])
            {
                card["is_playable"] = false;
            }

            ((JObject)s["discard_pile"][0])["is_playable"] = discardPlayable;
        });

        [UnityTest]
        public IEnumerator Discard_WhenAnEffectMakesACardPlayable_ThePileSaysSo()
        {
            var server = Serve(HarmonyGame());
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsTrue(Texts().Contains("play from it"), string.Join(" | ", Texts()));
            Assert.IsFalse(Board().DiscardOverlay.IsOpen);
            ScreenshotHelper.Capture("discard-playable");
        }

        [UnityTest]
        public IEnumerator Discard_ANormalPile_JustSaysHowManyCards()
        {
            var server = Serve(HarmonyGame(discardPlayable: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            Assert.IsTrue(Texts().Contains("Discard 1"));
            Assert.IsFalse(Texts().Any(t => t.Contains("play from it")));
        }

        [UnityTest]
        public IEnumerator Discard_TappingThePile_ShowsEveryCard_AndAPlayableOneStartsAPlay()
        {
            var server = Serve(HarmonyGame());
            server.OnPost = (path, body) =>
            {
                server.State = PhaseFiveSceneTests.Load(406, s => ((JArray)s["discard_pile"]).Clear());
                return null;
            };
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Discard").GetComponentInChildren<Button>());
            Assert.IsTrue(Board().DiscardOverlay.IsOpen);
            StringAssert.Contains("tap a framed card", Board().DiscardOverlay.NoteText);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Discarded Confusion"));
            Assert.IsFalse(Board().DiscardOverlay.IsOpen, "the pile gives way to the play form");
            Assert.IsTrue(Board().Choices.IsOpen);
            Assert.AreEqual("Confusion", Board().Choices.TitleText);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Submit"));

            var play = server.Posts("/games/play").Single();
            Assert.AreEqual(14174, (int)play["card_id"]);
            Assert.AreEqual("left", (string)play["choices"]["direction"]);
        }

        [UnityTest]
        public IEnumerator Discard_APlayFormFromThePile_SurvivesARefresh()
        {
            var server = Serve(HarmonyGame());
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Discard").GetComponentInChildren<Button>());
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Discarded Confusion"));

            yield return PhaseFiveSceneTests.Poll();

            Assert.IsTrue(Board().Choices.IsOpen, "the card is still playable, so the form stays");
        }

        [UnityTest]
        public IEnumerator Discard_ACardThatCantBePlayed_JustOpensItsDetail()
        {
            var server = Serve(HarmonyGame(discardPlayable: false));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Discard").GetComponentInChildren<Button>());
            StringAssert.Contains("Newest first", Board().DiscardOverlay.NoteText);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Discarded Confusion"));

            Assert.IsFalse(Board().Choices.IsOpen);
            Assert.IsTrue(Board().DetailOpen);
        }

        [UnityTest]
        public IEnumerator Discard_TheOverlayClosesWithBack()
        {
            var server = Serve(HarmonyGame());
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Discard").GetComponentInChildren<Button>());

            Assert.IsTrue(Board().HandleBack());

            Assert.IsFalse(Board().DiscardOverlay.IsOpen);
        }

        // --- the deck list ------------------------------------------------------------------------

        private static string DeckListJson() => new JObject
        {
            ["status"] = "ok",
            // Deliberately out of order: the list is sorted on the device.
            ["cards"] = new JArray(
                DraftCard(1, "Zeal", "green", "common"),
                DraftCard(2, "Bliss", "white", "rare"),
                DraftCard(3, "Calm", "white", "common"),
                DraftCard(4, "Awe", "white", "common"),
                DraftCard(5, "Fury", "red", "mythic"),
                DraftCard(6, "Envy", "black", "uncommon"),
                DraftCard(7, "Calm", "white", "common"),
                DraftCard(8, "Glee", "blue", "common")),
        }.ToString();

        [UnityTest]
        public IEnumerator DeckList_TappingTheDeck_ShowsEveryCardItStartedWith_ByColorRarityAndName()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406));
            server.DeckJson = DeckListJson();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.IsTrue(Texts().Contains("see the list"));

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Deck").GetComponentInChildren<Button>());
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsTrue(Board().DeckList.IsOpen);
            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("GET /games/deck?game_id=406")), string.Join("\n", server.Calls));
            Assert.AreEqual("Deck list (8 cards)", Board().DeckList.TitleText);
            CollectionAssert.AreEqual(
                new[] { "Awe", "Calm", "Calm", "Bliss", "Glee", "Envy", "Fury", "Zeal" },
                Board().DeckList.CardNames.ToArray(),
                "white commons by name, then the white rare, blue, black, red, green");
            ScreenshotHelper.Capture("deck-list");
        }

        [UnityTest]
        public IEnumerator DeckList_ACardInItCanBeRead_AndBackClosesTheList_AndItIsFetchedOnce()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406));
            server.DeckJson = DeckListJson();
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Deck").GetComponentInChildren<Button>());
            yield return PhaseTwoSceneTests.Frames(3);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.ButtonNamed("Listed Awe"));
            Assert.IsTrue(Board().DetailOpen);
            Assert.IsTrue(Texts().Any(t => t.Contains("In the deck")));

            Board().HandleBack(); // closes the detail
            Assert.IsTrue(Board().HandleBack());
            Assert.IsFalse(Board().DeckList.IsOpen);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Deck").GetComponentInChildren<Button>());
            yield return PhaseTwoSceneTests.Frames(2);

            Assert.IsTrue(Board().DeckList.IsOpen);
            Assert.AreEqual(1, server.Calls.Count(c => c.StartsWith("GET /games/deck")), "the list never changes, so it is read once");
        }

        [UnityTest]
        public IEnumerator DeckList_WhenEachPlayerHasTheirOwnDeck_SaysThereIsNoSingleList()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406, s => s["game"]["format"] = "duel"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);
            Assert.IsFalse(Texts().Contains("see the list"));

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Deck").GetComponentInChildren<Button>());

            Assert.IsFalse(Board().DeckList.IsOpen);
            StringAssert.Contains("their own deck", Board().MessageText);
            Assert.IsFalse(server.Calls.Any(c => c.StartsWith("GET /games/deck")), "nothing to ask the server");
        }

        [UnityTest]
        public IEnumerator DeckList_WhenTheServerRefuses_SaysWhy()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406));
            server.DeckStatus = 409;
            server.DeckJson = "{\"status\":\"error\",\"message\":\"That game has no single shared deck.\"}";
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            yield return PhaseFiveSceneTests.Tap(PhaseFiveSceneTests.Child("Deck").GetComponentInChildren<Button>());
            yield return PhaseTwoSceneTests.Frames(3);

            Assert.IsFalse(Board().DeckList.IsOpen);
            Assert.AreEqual("That game has no single shared deck.", Board().MessageText);
        }

        // --- the card back --------------------------------------------------------------------------

        [UnityTest]
        public IEnumerator Deck_IsDrawnAsTheBackOfACard()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var back = PhaseFiveSceneTests.Child("Deck").Find("Card back");

            Assert.IsNotNull(back, "the pile is a card back, not a grey box");
            Assert.AreSame(MoodSwings.Core.CardArtLibrary.CardBack(), back.GetComponent<Image>().sprite);
            Assert.IsNull(back.GetComponent<CanvasGroup>(), "not faded while there are cards in it");
            ScreenshotHelper.Capture("deck-card-back");
        }

        [UnityTest]
        public IEnumerator Deck_FadesOnceItHasRunOut()
        {
            var server = Serve(PhaseFiveSceneTests.Load(406, s => s["deck_count"] = 0));
            yield return PhaseFiveSceneTests.OpenBoard(server, 406);

            var back = PhaseFiveSceneTests.Child("Deck").Find("Card back");

            Assert.AreEqual(0.35f, back.GetComponent<CanvasGroup>().alpha, 0.001f);
        }

        // --- the eye, on every card you can inspect --------------------------------------------------

        // The eye is in the card's top-right corner, under the value die, and leaves the name along the top clear.
        private static void AssertEyeUnderTheDie(string cardName)
        {
            var button = Named("Inspect " + cardName);
            var card = PhaseFiveSceneTests.Child("Card " + cardName);
            Assert.IsNotNull(button, "an eye for " + cardName);
            Assert.AreSame(UiIcons.Eye(), button.transform.Find("Eye").GetComponent<Image>().sprite);
            Assert.IsNull(button.GetComponentsInChildren<Text>().FirstOrDefault(t => t.text == "i"));

            var cardCorners = new Vector3[4];
            ((RectTransform)card).GetWorldCorners(cardCorners);
            var eyeCorners = new Vector3[4];
            ((RectTransform)button.transform).GetWorldCorners(eyeCorners);
            var cardWidth = cardCorners[2].x - cardCorners[0].x;
            var cardHeight = cardCorners[1].y - cardCorners[0].y;

            Assert.Less(eyeCorners[2].x, cardCorners[2].x, cardName + ": inside the card");
            Assert.Greater(eyeCorners[2].x, cardCorners[2].x - cardWidth * 0.12f, cardName + ": against the right edge");
            Assert.Less(eyeCorners[1].y, cardCorners[1].y - cardHeight * 0.14f, cardName + ": below the die");
            Assert.Greater(eyeCorners[0].y, cardCorners[1].y - cardHeight * 0.40f, cardName + ": still up in the corner");
            Assert.Greater(eyeCorners[0].x, cardCorners[0].x + cardWidth * 0.5f, cardName + ": on the right half, off the name");
        }

        [UnityTest]
        public IEnumerator Eye_OnTheSealedDeckBuildingScreen_SitsUnderTheDie()
        {
            var server = Serve(DraftBoard(DeckBuilding(poolSize: 14), null, "sealed_deck"));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            AssertEyeUnderTheDie("Card300");
            AssertEyeUnderTheDie("Card313");
            ScreenshotHelper.Capture("sealed-deck-building-eye");
        }

        [UnityTest]
        public IEnumerator Eye_OnAPack_SitsUnderTheDie()
        {
            var server = Serve(DraftBoard(Drafting()));
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            AssertEyeUnderTheDie("Card100");
            yield return PhaseFiveSceneTests.Tap(Named("Inspect Card100"));
            Assert.AreEqual(0, Board().Draft.SelectedCount, "reading isn't choosing");
        }

        [UnityTest]
        public IEnumerator Eye_OnTheSmallerCardsOfAGrid_ScalesWithThem()
        {
            var server = Serve(Grid());
            yield return PhaseFiveSceneTests.OpenBoard(server, 405);

            AssertEyeUnderTheDie("Grid0");
            AssertEyeUnderTheDie("Grid8");
        }
    }
}
