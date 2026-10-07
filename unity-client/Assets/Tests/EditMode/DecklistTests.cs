using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class DecklistTests
    {
        private FakeHttpTransport _transport;
        private ApiClient _api;
        private DecklistFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _api = new ApiClient(new ApiConfig("https://example.test"), _transport);
            _flow = new DecklistFlow(_api);
        }

        private static CatalogResponse RealCatalog() => JsonConvert.DeserializeObject<CatalogResponse>(TestFixtures.Read("cards_catalog"));

        private DeckEditor Editor() => new DeckEditor(RealCatalog().Cards);

        private static BoardCard CardNamed(IEnumerable<BoardCard> cards, string name) => cards.First(c => c.Name == name);

        // --- the card catalog and the editor ------------------------------------------------------

        [Test]
        public void TheRealCatalog_IsRead()
        {
            var catalog = RealCatalog().Cards;

            Assert.AreEqual(133, catalog.Count);
            Assert.AreEqual("rare", CardNamed(catalog, "Altruism").Rarity);
            Assert.IsTrue(catalog.All(c => c.CardId > 0 && !string.IsNullOrEmpty(c.Color)));
        }

        [Test]
        public void Cards_AreAddedAndTakenOutOneCopyAtATime()
        {
            var deck = Editor();
            var id = deck.Catalog[0].CardId;

            deck.Add(id);
            deck.Add(id);
            deck.Add(deck.Catalog[1].CardId);
            Assert.AreEqual(3, deck.Count);
            Assert.AreEqual(2, deck.CountOf(id));

            deck.Remove(id);
            Assert.AreEqual(1, deck.CountOf(id));
            deck.Remove(id);
            Assert.AreEqual(0, deck.CountOf(id));
            deck.Remove(id);
            Assert.AreEqual(1, deck.Count, "taking out what isn't there changes nothing");
        }

        [Test]
        public void ACardNotInTheCatalog_IsIgnored()
        {
            var deck = Editor();

            deck.Add(987654);

            Assert.AreEqual(0, deck.Count);
        }

        [Test]
        public void TheDeckReadsByColorRarityAndName_AndSendsEveryCopy()
        {
            var deck = Editor();
            var catalog = deck.Catalog;
            var red = catalog.First(c => c.Color == "red");
            var white = catalog.First(c => c.Color == "white");
            deck.Add(red.CardId);
            deck.Add(white.CardId);
            deck.Add(white.CardId);

            var entries = deck.Entries();

            Assert.AreEqual("white", entries[0].Card.Color, "white comes before red");
            Assert.AreEqual(2, entries[0].Count);
            CollectionAssert.AreEqual(new[] { white.CardId, white.CardId, red.CardId }, deck.ToCardIds());
        }

        [Test]
        public void ADeckNeedsANameAndCards_ToBeSaved()
        {
            var deck = Editor();
            Assert.AreEqual("Give the deck a name.", deck.Problem());

            deck.Name = "  ";
            Assert.AreEqual("Give the deck a name.", deck.Problem(), "blank doesn't count");

            deck.Name = "Mine";
            Assert.AreEqual("Add some cards.", deck.Problem());

            deck.Add(deck.Catalog[0].CardId);
            Assert.IsNull(deck.Problem());
        }

        [Test]
        public void ASmallDeck_GetsAReminderAboutCustomGames()
        {
            var deck = Editor();
            foreach (var card in deck.Catalog.Take(14))
            {
                deck.Add(card.CardId);
            }

            StringAssert.Contains("at least 15", deck.SizeHint());
            deck.Add(deck.Catalog[14].CardId);
            Assert.IsNull(deck.SizeHint());
        }

        [Test]
        public void ChangesAreNoticed_UntilTheyAreSaved()
        {
            var deck = Editor();
            Assert.IsFalse(deck.HasUnsavedChanges);

            deck.Name = "Mine";
            Assert.IsTrue(deck.HasUnsavedChanges);
            deck.MarkSaved();
            Assert.IsFalse(deck.HasUnsavedChanges);

            deck.Add(deck.Catalog[0].CardId);
            Assert.IsTrue(deck.HasUnsavedChanges);
            deck.Remove(deck.Catalog[0].CardId);
            Assert.IsFalse(deck.HasUnsavedChanges, "back to how it was");

            deck.Visibility = DeckEditor.Friends;
            Assert.IsTrue(deck.HasUnsavedChanges);
        }

        private static DecklistDetail Detail(DeckEditor deck, int id = 9, string name = "2nd Tier")
        {
            var card = deck.Catalog[0];
            var other = deck.Catalog[1];
            return new DecklistDetail
            {
                Id = id, Name = name, Visibility = "friends", OwnerUserId = 1,
                Cards = new System.Collections.Generic.List<BoardCard> { card, card, other },
                SideboardCards = new System.Collections.Generic.List<BoardCard> { deck.Catalog[2] },
            };
        }

        [Test]
        public void ASavedDeck_IsLoadedWithItsCopies_AndKeepsItsSideboard()
        {
            var deck = Editor();
            deck.Load(Detail(deck));

            Assert.AreEqual("2nd Tier", deck.Name);
            Assert.AreEqual(9, deck.DecklistId);
            Assert.AreEqual(DeckEditor.Friends, deck.Visibility);
            Assert.AreEqual(3, deck.Count);
            Assert.AreEqual(2, deck.CountOf(deck.Catalog[0].CardId));
            CollectionAssert.AreEqual(new[] { deck.Catalog[2].CardId }, deck.SideboardCardIds());
            Assert.IsFalse(deck.HasUnsavedChanges);
        }

        [Test]
        public void ACopyOfADeck_IsANewPrivateOne_ThatKeepsItsSideboard()
        {
            var deck = Editor();
            deck.Load(Detail(deck), asCopy: true);

            Assert.AreEqual("2nd Tier (copy)", deck.Name);
            Assert.IsNull(deck.DecklistId);
            Assert.AreEqual(DeckEditor.Private, deck.Visibility);
            Assert.AreEqual(3, deck.Count);
            Assert.AreEqual(1, deck.SideboardCount, "a copy is the whole deck, sideboard too");
        }

        [Test]
        public void TheCatalogFilter_NarrowsByColorRarityAndText()
        {
            var all = RealCatalog().Cards;

            var white = CatalogFilter.Apply(all, new[] { "white" }, null, null);
            Assert.IsTrue(white.Count > 0 && white.All(c => c.Color == "white"));

            var whiteOrBlue = CatalogFilter.Apply(all, new[] { "white", "blue" }, null, null);
            Assert.IsTrue(whiteOrBlue.Count > white.Count);

            var rares = CatalogFilter.Apply(all, null, new[] { "rare" }, null);
            Assert.IsTrue(rares.All(c => c.Rarity == "rare"));

            var named = CatalogFilter.Apply(all, null, null, "  altRU ");
            Assert.AreEqual("Altruism", named.First().Name, "a bit of the name, any case");

            var both = CatalogFilter.Apply(all, new[] { "white" }, new[] { "rare" }, null);
            Assert.IsTrue(both.All(c => c.Color == "white" && c.Rarity == "rare"));

            Assert.AreEqual(all.Count, CatalogFilter.Apply(all, new string[0], new string[0], string.Empty).Count, "no filter, every card");
        }

        [Test]
        public void TheFilterTextAlsoSearchesTheRules()
        {
            var all = RealCatalog().Cards;
            var word = all.First(c => !string.IsNullOrEmpty(c.RulesText) && c.RulesText.Contains("discard pile")).RulesText.Contains("discard pile");

            Assert.IsTrue(word);
            Assert.IsTrue(CatalogFilter.Apply(all, null, null, "discard pile").Count > 1);
        }

        // --- the list ------------------------------------------------------------------------------

        [Test]
        public void TheDecklistsAreRead_YoursAndYourFriends()
        {
            _transport.Enqueue(200, TestFixtures.Read("decklists"));

            var result = _flow.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/decklists", _transport.LastRequest.Url);
            Assert.AreEqual(0, _flow.Own.Count);
            Assert.AreEqual("jceddy", _flow.Friends.Single().FriendUsername);
            Assert.AreEqual(10, _flow.Friends.Single().Decklists.Count);
            Assert.IsTrue(_flow.Loaded);
            var combo = _flow.Friends.Single().Decklists.First(d => d.Name == "Combo-Aggro");
            Assert.AreEqual("15 cards + 5 sideboard  -  shared with friends", DecklistDisplay.Describe(combo));
        }

        [Test]
        public void AFailedRead_SaysSo_AndKeepsWhatItHad()
        {
            _transport.EnqueueNetworkError("offline");

            var result = _flow.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.IsNotEmpty(result.Message);
            Assert.IsFalse(_flow.Loaded);
        }

        [Test]
        public void TheCatalog_IsFetchedOnlyOnce()
        {
            _transport.Enqueue(200, TestFixtures.Read("cards_catalog"));

            _flow.LoadCatalogAsync().GetAwaiter().GetResult();
            _flow.LoadCatalogAsync().GetAwaiter().GetResult();

            Assert.AreEqual(1, _transport.Requests.Count);
            Assert.AreEqual(133, _flow.Catalog.Count);
        }

        [Test]
        public void ADeckIsViewed_ByItsId()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"decklist\":{\"id\":5,\"name\":\"Combo-Aggro\",\"visibility\":\"friends\",\"owner_user_id\":1," +
                "\"cards\":[{\"card_id\":1,\"name\":\"Altruism\"}],\"sideboard_cards\":[]}}");

            var result = _flow.ViewAsync(5).GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/decklists/view?id=5", _transport.LastRequest.Url);
            Assert.AreEqual("Combo-Aggro", result.Decklist.Name);
            Assert.AreEqual(1, result.Decklist.Cards.Count);
        }

        // --- saving ---------------------------------------------------------------------------------

        private DeckEditor ReadyDeck()
        {
            var deck = Editor();
            deck.Name = " Mine ";
            deck.Add(deck.Catalog[0].CardId);
            deck.Add(deck.Catalog[0].CardId);
            deck.Add(deck.Catalog[3].CardId);
            return deck;
        }

        [Test]
        public void ANewDeck_IsCreated_WithItsNameVisibilityAndCopies()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"decklist_id\":42}");
            _transport.Enqueue(200, TestFixtures.Read("decklists"));
            var deck = ReadyDeck();

            var result = _flow.SaveAsync(deck).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(42, result.DecklistId);
            Assert.AreEqual("https://example.test/app/decklists", _transport.Requests[0].Url);
            var body = JObject.Parse(_transport.Requests[0].Body);
            Assert.AreEqual("Mine", (string)body["name"], "trimmed");
            Assert.AreEqual("private", (string)body["visibility"]);
            Assert.AreEqual(3, body["card_ids"].Count());
            Assert.AreEqual(0, body["sideboard_card_ids"].Count());
            Assert.AreEqual(42, deck.DecklistId, "the next save updates it");
            Assert.IsFalse(deck.HasUnsavedChanges);
        }

        [Test]
        public void ASavedDeck_IsUpdatedInPlace()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, TestFixtures.Read("decklists"));
            var deck = Editor();
            deck.Load(Detail(deck));
            deck.Name = "Renamed";

            var result = _flow.SaveAsync(deck).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/decklists/update", _transport.Requests[0].Url);
            var body = JObject.Parse(_transport.Requests[0].Body);
            Assert.AreEqual(9, (int)body["id"]);
            Assert.AreEqual("Renamed", (string)body["name"]);
            Assert.AreEqual("friends", (string)body["visibility"]);
            Assert.AreEqual(1, body["sideboard_card_ids"].Count(), "the sideboard goes back as it was");
        }

        [Test]
        public void ADeckThatCantBeSaved_IsNotSent()
        {
            var deck = Editor();

            var result = _flow.SaveAsync(deck).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("Give the deck a name.", result.Message);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void TheServersReasonForRefusing_IsShown()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"A deck name is required.\"}");

            var result = _flow.SaveAsync(ReadyDeck()).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("A deck name is required.", result.Message);
        }

        [Test]
        public void ADeckIsDeleted_ThenTheListIsReread()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\",\"message\":\"Deck deleted.\"}");
            _transport.Enqueue(200, TestFixtures.Read("decklists"));

            var result = _flow.DeleteAsync(7).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/decklists/delete", _transport.Requests[0].Url);
            Assert.AreEqual(7, (int)JObject.Parse(_transport.Requests[0].Body)["id"]);
            Assert.AreEqual("https://example.test/app/decklists", _transport.Requests[1].Url);
        }

        // --- playing a saved deck ----------------------------------------------------------------

        [Test]
        public void ACustomDeck_IsOfferedToTraditionalAndTeamGames_ButNotToDuels()
        {
            CollectionAssert.Contains(new GameSetup().DecksForFormat.Select(d => d.Id).ToList(), "custom");
            CollectionAssert.Contains(new GameSetup { Format = GameSetup.ClosedTeamFormat }.DecksForFormat.Select(d => d.Id).ToList(), "custom");
            var duel = new GameSetup { Format = GameSetup.DuelFormat }.DecksForFormat.Select(d => d.Id).ToList();
            CollectionAssert.DoesNotContain(duel, "custom");
            CollectionAssert.Contains(duel, "custom_duel");
            CollectionAssert.DoesNotContain(new GameSetup().DecksForFormat.Select(d => d.Id).ToList(), "custom_duel");
        }

        [Test]
        public void ACustomDeck_NeedsASavedDeck_WithEnoughCardsForThePlayers()
        {
            var setup = new GameSetup { DeckType = "custom", OpponentUserIds = { 18 } };

            StringAssert.Contains("saved decks", setup.ValidateDirectGame());

            setup.SavedDecklistId = 30;
            setup.SavedDeckCardCount = 14;
            StringAssert.Contains("at least 15", setup.ValidateDirectGame());

            setup.SavedDeckCardCount = 15;
            Assert.IsNull(setup.ValidateDirectGame());

            setup.OpponentUserIds.Add(20);
            StringAssert.Contains("at least 30", setup.ValidateDirectGame(), "15 more for the third player");
            Assert.AreEqual(30, setup.ToDirectGameBody()["saved_decklist_id"] is int id ? id : 0);
        }

        [Test]
        public void TheOpenLobbyChecksTheDeckAgainstTheLobbysPlayerCount()
        {
            var setup = new GameSetup { DeckType = "custom", PostToOpenLobby = true, OpenLobbyPlayerCount = 3, SavedDecklistId = 30, SavedDeckCardCount = 20 };

            StringAssert.Contains("at least 30", setup.ValidateOpenGame());
            setup.OpenLobbyPlayerCount = 2;
            Assert.IsNull(setup.ValidateOpenGame());
            Assert.AreEqual(30, setup.ToOpenGameBody()["saved_decklist_id"]);
        }

        [Test]
        public void ASavedDeckThatIsntUsed_IsNotSent()
        {
            var setup = new GameSetup { DeckType = "structure", SavedDecklistId = 30, OpponentUserIds = { 18 } };

            Assert.IsFalse(setup.ToDirectGameBody().ContainsKey("saved_decklist_id"));
        }

        [Test]
        public void ADraftCanDealFromASavedDeck()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "grid_draft", PoolSource = "saved_deck", OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.IsTrue(setup.UsesSavedDeck);
            StringAssert.Contains("saved deck to draft from", setup.ValidateDirectGame());

            setup.SavedDecklistId = 12;
            Assert.IsNull(setup.ValidateDirectGame(), "the card count is the draft's to judge");
            var body = setup.ToDirectGameBody();
            Assert.AreEqual("saved_deck", body["grid_draft_pool_source"]);
            Assert.AreEqual(12, body["saved_decklist_id"]);
        }

        [Test]
        public void ACustomDuel_SendsItsRules_AndEachBotsDeck()
        {
            var setup = new GameSetup
            {
                Format = GameSetup.DuelFormat, DeckType = "custom_duel", DuelPreset = "jceddys_75",
                OpponentUserIds = { 9, 20 }, BotUserIds = new System.Collections.Generic.HashSet<int> { 9 },
            };
            setup.Normalize(false);

            StringAssert.Contains("each practice bot", setup.ValidateDirectGame());

            setup.BotDecklistIds[9] = 31;
            Assert.IsNull(setup.ValidateDirectGame(), "a human opponent brings their own");
            var body = setup.ToDirectGameBody();
            Assert.AreEqual("jceddys_75", ((System.Collections.Generic.Dictionary<string, object>)body["duel_deck_rules"])["preset"]);
            var bots = (System.Collections.Generic.Dictionary<string, object>)body["bot_decklists"];
            Assert.AreEqual(31, ((System.Collections.Generic.Dictionary<string, object>)bots["9"])["saved_decklist_id"]);
        }

        [Test]
        public void ABotsDeckIsForgotten_WhenTheBotIsNoLongerAnOpponent()
        {
            var setup = new GameSetup { Format = GameSetup.DuelFormat, DeckType = "custom_duel", OpponentUserIds = { 20 } };
            setup.BotDecklistIds[9] = 31;

            setup.Normalize(false);

            Assert.AreEqual(0, setup.BotDecklistIds.Count);
        }

        [Test]
        public void Sideboarding_IsOnlyForAPowerDuelPlayedAsAMatch()
        {
            var setup = new GameSetup { Format = GameSetup.DuelFormat, DeckType = "custom_duel", DuelPreset = "structure", BestOfThree = true, AllowSideboarding = true, OpponentUserIds = { 20 } };
            setup.Normalize(false);
            Assert.IsFalse(setup.SideboardingAvailable);
            Assert.IsFalse(setup.AllowSideboarding, "dropped when it doesn't apply");

            setup.DuelPreset = "power";
            setup.AllowSideboarding = true;
            setup.Normalize(false);
            Assert.IsTrue(setup.SideboardingAvailable);
            Assert.AreEqual(true, setup.ToDirectGameBody()["allow_sideboarding"]);

            setup.BestOfThree = false;
            setup.Normalize(false);
            Assert.IsFalse(setup.ToDirectGameBody().ContainsKey("allow_sideboarding"));
        }

        [Test]
        public void ARematchOfACustomDeckGame_IsNotOffered()
        {
            Assert.IsFalse(GameSetup.SupportsDeck("custom"));
            Assert.IsFalse(GameSetup.SupportsDeck("custom_duel"));
        }

        // --- a custom duel's waiting room ---------------------------------------------------------

        private const string Rules =
            "{\"preset\":\"power\",\"min_cards\":3,\"rarity_limits\":{\"mythic\":1,\"rare\":2},\"duplicate_limits\":{\"common\":2},\"even_color_distribution_rarities\":[]}";

        private static GameState DuelWaiting(bool youSubmitted = false, bool otherSubmitted = false)
        {
            var state = BoardFixtures.Load(405);
            state.Game.Status = "waiting";
            state.Game.Format = "duel";
            state.Game.DeckType = "custom_duel";
            state.Game.DuelDeckRules = JsonConvert.DeserializeObject<DuelDeckRules>(Rules);
            var me = state.Players.First(p => p.GamePlayerId == state.You.GamePlayerId);
            me.DeckSubmitted = youSubmitted;
            foreach (var other in state.Players.Where(p => p != me))
            {
                other.DeckSubmitted = otherSubmitted;
            }

            return state;
        }

        private static BoardCard Pick(int id, string name, string rarity) => new BoardCard { CardId = id, Name = name, Rarity = rarity };

        [Test]
        public void TheDuelRules_AreRead_EvenWhenTheServerSendsEmptyMapsAsLists()
        {
            var rules = JsonConvert.DeserializeObject<DuelDeckRules>(Rules);
            Assert.AreEqual(3, rules.MinCards);
            Assert.AreEqual(1, rules.RarityLimits["mythic"]);
            Assert.AreEqual(2, rules.DuplicateLimits["common"]);

            var none = JsonConvert.DeserializeObject<DuelDeckRules>("{\"min_cards\":15,\"rarity_limits\":[],\"duplicate_limits\":[],\"even_color_distribution_rarities\":[]}");
            Assert.AreEqual(0, none.RarityLimits.Count);
            Assert.AreEqual("At least 15 cards.", DuelDeckDisplay.RulesSummary(none));
        }

        [Test]
        public void TheRules_ReadAsASentence()
        {
            var rules = JsonConvert.DeserializeObject<DuelDeckRules>(Rules);
            rules.EvenColorDistributionRarities.Add("common");

            Assert.AreEqual(
                "At least 3 cards. At most 2 rare, 1 mythic. At most 2 copies of any common card. common cards split evenly across all 5 colors.",
                DuelDeckDisplay.RulesSummary(rules));
        }

        [Test]
        public void ADeck_IsCheckedAgainstTheRules()
        {
            var rules = JsonConvert.DeserializeObject<DuelDeckRules>(Rules);
            var fine = new[] { Pick(1, "A", "common"), Pick(2, "B", "rare"), Pick(3, "C", "mythic") };
            Assert.IsNull(DuelDeckDisplay.Problem(rules, fine));
            Assert.AreEqual("Choose at least 3 cards (2 so far).", DuelDeckDisplay.Problem(rules, fine.Take(2).ToArray()));

            var tooRare = new[] { Pick(1, "A", "rare"), Pick(2, "B", "rare"), Pick(3, "C", "rare") };
            Assert.AreEqual("At most 2 rare cards (3 chosen).", DuelDeckDisplay.Problem(rules, tooRare));

            var tooMany = new[] { Pick(1, "Calm", "common"), Pick(1, "Calm", "common"), Pick(1, "Calm", "common") };
            Assert.AreEqual("At most 2 copies of Calm (3 chosen).", DuelDeckDisplay.Problem(rules, tooMany));
            Assert.IsNull(DuelDeckDisplay.Problem(null, tooMany), "no rules, nothing to break");
        }

        [Test]
        public void ADeck_IsWrittenOutAsCountsAndNames()
        {
            var text = DuelDeckDisplay.DeckText(new[] { Pick(1, "Calm", "common"), Pick(2, "Awe", "rare"), Pick(1, "Calm", "common") });

            Assert.AreEqual("2 Calm\n1 Awe", text);
        }

        [Test]
        public void TheWaitingRoom_AsksYouForADeck_ThenWaitsForTheOthers()
        {
            var asking = DuelWaiting();
            Assert.IsTrue(DuelDeckDisplay.InStage(asking));
            Assert.IsTrue(DuelDeckDisplay.NeedsAction(asking));
            Assert.AreEqual("Choose your deck", BoardDisplay.TurnBanner(asking));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(asking));
            StringAssert.StartsWith("Choosing decks", BoardDisplay.HeaderLine(asking, false, System.DateTime.UtcNow));

            var waiting = DuelWaiting(youSubmitted: true);
            Assert.IsFalse(DuelDeckDisplay.NeedsAction(waiting));
            StringAssert.StartsWith("Waiting for ", BoardDisplay.TurnBanner(waiting));
            StringAssert.EndsWith("'s deck", BoardDisplay.TurnBanner(waiting));
        }

        [Test]
        public void TheGameStartsOnlyOnceEveryDeckIsChosen()
        {
            Assert.IsFalse(BoardDisplay.ReadyToStart(DuelWaiting()));
            Assert.IsFalse(BoardDisplay.ReadyToStart(DuelWaiting(youSubmitted: true)));
            Assert.IsFalse(BoardDisplay.ReadyToStart(DuelWaiting(otherSubmitted: true)));
            Assert.IsTrue(BoardDisplay.ReadyToStart(DuelWaiting(youSubmitted: true, otherSubmitted: true)));
            Assert.AreEqual("Starting the game...", BoardDisplay.TurnBanner(DuelWaiting(youSubmitted: true, otherSubmitted: true)));
        }

        [Test]
        public void ACustomDuel_IsPlayable_OnceItIsUnderWay()
        {
            var state = DuelWaiting(true, true);
            state.Game.Status = "in_progress";

            Assert.IsNull(BoardDisplay.UnsupportedReason(state));
            Assert.IsFalse(DuelDeckDisplay.InStage(state));
            Assert.IsTrue(BoardDisplay.HasSeparateDecks(state));
        }

        [Test]
        public void ASpectatorIsNotAskedForADeck()
        {
            var state = DuelWaiting();
            state.You.GamePlayerId = null;

            Assert.IsFalse(DuelDeckDisplay.InStage(state));
        }

        [Test]
        public void ADeckIsSubmitted_AsASavedDeckOrAsText()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJsonFor405());
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, ChaosStateJsonFor405());
            var session = BoardSession.ForPlayer(_api, 405);

            session.SubmitDuelDeckAsync(30).GetAwaiter().GetResult();
            session.SubmitDuelDeckAsync(null, "2 Calm\n1 Awe").GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/games/decklist", _transport.Requests[0].Url);
            var saved = JObject.Parse(_transport.Requests[0].Body);
            Assert.AreEqual(30, (int)saved["saved_decklist_id"]);
            Assert.IsNull(saved["decklist_text"]);
            var text = JObject.Parse(_transport.Requests[2].Body);
            Assert.AreEqual("2 Calm\n1 Awe", (string)text["decklist_text"]);
            Assert.IsNull(text["saved_decklist_id"]);
        }

        private static string ChaosStateJsonFor405() => TestFixtures.Read("game_405_state");

        // --- the sideboard -------------------------------------------------------------------------

        [Test]
        public void TheSideboard_IsBuiltLikeTheDeck_ButKeptApart()
        {
            var deck = Editor();
            var a = deck.Catalog[0].CardId;
            var b = deck.Catalog[1].CardId;

            deck.Add(a);
            deck.Add(a, sideboard: true);
            deck.Add(a, sideboard: true);
            deck.Add(b, sideboard: true);

            Assert.AreEqual(1, deck.Count);
            Assert.AreEqual(3, deck.SideboardCount);
            Assert.AreEqual(1, deck.CountOf(a));
            Assert.AreEqual(2, deck.CountOf(a, sideboard: true));
            CollectionAssert.AreEqual(new[] { a }, deck.ToCardIds(), "the deck's own cards");
            CollectionAssert.AreEquivalent(new[] { a, a, b }, deck.SideboardCardIds());

            deck.Remove(a, sideboard: true);
            Assert.AreEqual(1, deck.CountOf(a, sideboard: true));
            deck.Remove(a, sideboard: true);
            Assert.AreEqual(0, deck.CountOf(a, sideboard: true));
            Assert.AreEqual(1, deck.CountOf(a), "taking it out of the sideboard leaves the deck's copy");
        }

        [Test]
        public void TheSideboardsLines_ReadInTheSameOrder()
        {
            var deck = Editor();
            var red = deck.Catalog.First(c => c.Color == "red");
            var white = deck.Catalog.First(c => c.Color == "white");
            deck.Add(red.CardId, sideboard: true);
            deck.Add(white.CardId, sideboard: true);

            var lines = deck.Entries(sideboard: true);

            CollectionAssert.AreEqual(new[] { "white", "red" }, lines.Select(l => l.Card.Color).ToArray());
            Assert.AreEqual(0, deck.Entries().Count);
        }

        [Test]
        public void ChangingTheSideboard_IsAChangeToSave()
        {
            var deck = Editor();
            deck.Name = "Mine";
            deck.MarkSaved();

            deck.Add(deck.Catalog[2].CardId, sideboard: true);
            Assert.IsTrue(deck.HasUnsavedChanges);

            deck.Remove(deck.Catalog[2].CardId, sideboard: true);
            Assert.IsFalse(deck.HasUnsavedChanges);
        }

        [Test]
        public void ADecksSideboard_IsSentWhenItIsSaved()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"decklist_id\":42}");
            _transport.Enqueue(200, TestFixtures.Read("decklists"));
            var deck = ReadyDeck();
            deck.Add(deck.Catalog[5].CardId, sideboard: true);
            deck.Add(deck.Catalog[5].CardId, sideboard: true);

            _flow.SaveAsync(deck).GetAwaiter().GetResult();

            var body = JObject.Parse(_transport.Requests[0].Body);
            CollectionAssert.AreEqual(new[] { deck.Catalog[5].CardId, deck.Catalog[5].CardId }, body["sideboard_card_ids"].Select(t => (int)t).ToArray());
            Assert.AreEqual(3, body["card_ids"].Count());
        }
    }
}
