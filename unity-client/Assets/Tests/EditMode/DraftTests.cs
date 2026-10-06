using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class DraftTests
    {
        private static BoardCard Card(int id, string name, string color, string rarity) =>
            new BoardCard { CardId = id, CatalogCardId = id, Name = name, Color = color, Rarity = rarity };

        private static GameState DraftGame(string deckType, string status, string block)
        {
            var state = BoardFixtures.Load(405);
            state.Game.Status = status;
            state.Game.Format = "draft";
            state.Game.DeckType = deckType;
            state.QuickDraft = JsonConvert.DeserializeObject<DraftMatchState>(block);
            return state;
        }

        private const string Players =
            "\"players\":[{\"user_id\":2,\"username\":\"bshaftoe\",\"wins\":0,\"is_you\":true},{\"user_id\":18,\"username\":\"BotSage\",\"wins\":1,\"is_you\":false}]";

        private static string Drafting(string status = "picking", int stage = 1) =>
            "{\"status\":\"drafting\",\"games_to_win\":2,\"your_wins\":0,\"opponent_wins\":1," + Players + "," +
            "\"drafting\":{\"round\":2,\"total_rounds\":4,\"stage\":" + stage + ",\"total_stages\":2,\"pass_direction\":\"left\",\"status\":\"" + status + "\"," +
            "\"pack\":[{\"card_id\":7,\"catalog_card_id\":7,\"name\":\"Calm\",\"color\":\"white\",\"rarity\":\"common\"}],\"kept_so_far\":[]}}";

        private static string Building(bool you, bool bot, string caps = "[]") =>
            "{\"status\":\"deck_building\",\"games_to_win\":2,\"your_wins\":0,\"opponent_wins\":1," + Players + "," +
            "\"deck_building\":{\"drafted_cards\":[],\"min_deck_size\":12,\"max_deck_size\":16,\"you_submitted\":" + (you ? "true" : "false") +
            ",\"opponent_submitted\":" + (bot ? "true" : "false") + ",\"other_players\":[{\"user_id\":18,\"username\":\"BotSage\",\"submitted\":" + (bot ? "true" : "false") + "}]," +
            "\"rarity_caps\":" + caps + "}}";

        [Test]
        public void EachDraftType_ReadsItsOwnBlock()
        {
            var state = DraftGame("quick_draft", "waiting", Drafting());
            Assert.AreSame(state.QuickDraft, DraftDisplay.BlockOf(state));

            state.Game.DeckType = "chaos_draft";
            Assert.AreSame(state.QuickDraft, DraftDisplay.BlockOf(state), "Chaos Draft is a Quick Draft underneath");

            state.Game.DeckType = "weekly_sealed_pool";
            Assert.IsNull(DraftDisplay.BlockOf(state), "its block isn't there");

            state.Game.DeckType = "structure";
            Assert.IsNull(DraftDisplay.BlockOf(state));
            Assert.IsFalse(DraftDisplay.IsDraftGame(state));
        }

        [Test]
        public void APickInProgress_IsTheDraftStage_AndNeedsYou()
        {
            var state = DraftGame("quick_draft", "waiting", Drafting());

            Assert.IsTrue(DraftDisplay.InDraftStage(state));
            Assert.IsTrue(DraftDisplay.NeedsAction(state));
            Assert.AreEqual("Choose 2 cards to keep", DraftDisplay.Banner(state));
            Assert.AreEqual("Draft round 2 of 4  -  first pick", DraftDisplay.Line(state));
            Assert.IsTrue(BoardDisplay.BannerNeedsViewer(state));
        }

        [Test]
        public void OnceYouHavePicked_YouWaitForTheOthers()
        {
            var state = DraftGame("quick_draft", "waiting", Drafting("awaiting_others", stage: 2));

            Assert.IsFalse(DraftDisplay.NeedsAction(state));
            Assert.AreEqual("Waiting for the others to pick", BoardDisplay.TurnBanner(state));
            Assert.AreEqual("Draft round 2 of 4  -  second pick", DraftDisplay.Line(state));
        }

        [Test]
        public void WithMoreThanTwoPlayers_TheStagesAreNumberedAndSayWhichWayTheyPass()
        {
            var drafting = new QuickDrafting { Round = 1, TotalRounds = 4, Stage = 2, TotalStages = 3, PassDirection = "right" };

            Assert.AreEqual("Draft round 1 of 4  -  stage 2 of 3 (passing right)", DraftDisplay.QuickTitle(drafting));
        }

        [Test]
        public void ACustomDuel_IsNotADraft_SoItHasNoDraftStage()
        {
            var state = DraftGame("custom_duel", "waiting", Drafting());

            Assert.IsFalse(DraftDisplay.NeedsAction(state));
            Assert.IsFalse(DraftDisplay.InDraftStage(state));
            Assert.IsNull(DraftDisplay.Banner(state));
        }

        [Test]
        public void DeckBuilding_BannerFollowsWhoHasSubmitted()
        {
            Assert.AreEqual("Build your deck", DraftDisplay.Banner(DraftGame("quick_draft", "waiting", Building(false, false))));
            Assert.AreEqual("Waiting for BotSage's deck", DraftDisplay.Banner(DraftGame("quick_draft", "waiting", Building(true, false))));
            Assert.AreEqual("Starting the game...", DraftDisplay.Banner(DraftGame("quick_draft", "waiting", Building(true, true))));
            Assert.AreEqual("Building decks", DraftDisplay.Line(DraftGame("quick_draft", "waiting", Building(false, false))));
        }

        [Test]
        public void TheGameStartsOnlyOnceEveryDeckIsIn()
        {
            Assert.IsFalse(BoardDisplay.ReadyToStart(DraftGame("quick_draft", "waiting", Building(false, true))), "yours is missing");
            Assert.IsFalse(BoardDisplay.ReadyToStart(DraftGame("quick_draft", "waiting", Building(true, false))), "theirs is missing");
            Assert.IsFalse(BoardDisplay.ReadyToStart(DraftGame("quick_draft", "waiting", Drafting())), "still drafting");
            Assert.IsTrue(BoardDisplay.ReadyToStart(DraftGame("quick_draft", "waiting", Building(true, true))));
        }

        [Test]
        public void ASynchronousDraft_WaitsForTheReadyCheckFirst()
        {
            var state = DraftGame("quick_draft", "waiting", Drafting());
            state.Game.SynchronousMode = true;
            state.Players.First().Ready = false;

            Assert.IsFalse(DraftDisplay.InDraftStage(state));
            Assert.AreEqual("Waiting for everyone to be ready", BoardDisplay.TurnBanner(state));
        }

        [Test]
        public void ADraftMatch_ReadsLikeAnyOtherMatch()
        {
            var state = DraftGame("quick_draft", "waiting", Building(false, false));
            var match = BoardDisplay.CurrentMatch(state);

            Assert.IsNotNull(match);
            Assert.AreEqual(1, match.OpponentWins);
            Assert.AreEqual(2, match.GamesToWin);
            Assert.IsNotNull(BoardDisplay.MatchLine(state));
        }

        [Test]
        public void ThePool_IsSortedByColorThenRarityThenName()
        {
            var sorted = DraftDisplay.SortPool(new[]
            {
                Card(1, "Zeal", "green", "common"),
                Card(2, "Awe", "white", "rare"),
                Card(3, "Calm", "white", "common"),
                Card(4, "Bliss", "white", "common"),
                Card(5, "Fury", "red", "mythic"),
                Card(6, "Envy", "black", "uncommon"),
            });

            CollectionAssert.AreEqual(new[] { "Bliss", "Calm", "Awe", "Envy", "Fury", "Zeal" }, sorted.Select(c => c.Name).ToArray());
        }

        [Test]
        public void DeckSize_IsExplainedAsARangeOrExactly()
        {
            Assert.AreEqual("12 to 16 cards", DraftDisplay.DeckSizeText(new DraftDeckBuilding { MinDeckSize = 12, MaxDeckSize = 16 }));
            Assert.AreEqual("15 cards", DraftDisplay.DeckSizeText(new DraftDeckBuilding { MinDeckSize = 15, MaxDeckSize = 15 }));
        }

        [Test]
        public void ADeck_MustBeWithinTheSizeLimits_AndRarityCaps()
        {
            var building = JsonConvert.DeserializeObject<DraftMatchState>(Building(false, false, "{\"rare\":1,\"mythic\":0}")).DeckBuilding;
            Assert.AreEqual(1, building.RarityCaps["rare"]);

            var commons = Enumerable.Range(0, 12).Select(i => Card(i, "C" + i, "white", "common")).ToList();
            Assert.IsNull(DraftDisplay.DeckProblem(building, commons));
            Assert.AreEqual("Choose at least 12 cards (11 so far).", DraftDisplay.DeckProblem(building, commons.Take(11).ToList()));
            Assert.AreEqual("Choose at most 16 cards (17 chosen).", DraftDisplay.DeckProblem(building, commons.Concat(Enumerable.Range(20, 5).Select(i => Card(i, "D" + i, "red", "common"))).ToList()));

            var twoRares = commons.Take(10).Concat(new[] { Card(50, "R1", "red", "rare"), Card(51, "R2", "blue", "rare") }).ToList();
            Assert.AreEqual("At most 1 rare cards (2 chosen).", DraftDisplay.DeckProblem(building, twoRares));

            var mythic = commons.Take(11).Concat(new[] { Card(52, "M", "red", "mythic") }).ToList();
            Assert.AreEqual("At most 0 mythic cards (1 chosen).", DraftDisplay.DeckProblem(building, mythic));
        }

        [Test]
        public void RarityCaps_AreAbsentWhenTheServerSendsAnEmptyList()
        {
            var building = JsonConvert.DeserializeObject<DraftMatchState>(Building(false, false)).DeckBuilding;

            Assert.AreEqual(0, building.RarityCaps?.Count ?? 0, "PHP encodes an empty map as []");
        }

        [Test]
        public void ThePackAndTheCardsKeptSoFar_AreRead()
        {
            var quick = JsonConvert.DeserializeObject<DraftMatchState>(Drafting()).AsQuickDrafting();

            Assert.AreEqual(2, quick.Round);
            Assert.AreEqual("left", quick.PassDirection);
            Assert.AreEqual("Calm", quick.Pack.Single().Name);
            Assert.AreEqual("common", quick.Pack.Single().Rarity);
            Assert.AreEqual(7, quick.Pack.Single().CatalogCardId);
        }

        // --- setting up a draft -------------------------------------------------------------------

        [Test]
        public void ADraftGame_IsSentWithItsFormatDeckTypeAndPool()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.AreEqual("quick_draft", setup.DeckType, "the draft formats' own decks, not Structure");
            Assert.IsTrue(setup.UsesPoolSource);
            Assert.IsNull(setup.ValidateDirectGame());

            setup.PoolSource = "jceddys_75";
            var body = setup.ToDirectGameBody();
            Assert.AreEqual("draft", body["format"]);
            Assert.AreEqual("quick_draft", body["deck_type"]);
            Assert.AreEqual("jceddys_75", body["quick_draft_pool_source"]);
        }

        [Test]
        public void TheOtherFormats_SendNoPoolSource()
        {
            var setup = new GameSetup { OpponentUserIds = { 18 } };

            Assert.IsFalse(setup.ToDirectGameBody().ContainsKey("quick_draft_pool_source"));
        }

        [Test]
        public void ADraft_IsAlwaysAMatch_SoBestOfThreeIsNotOffered_AndTheLobbyCountIsChoosable()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, BestOfThree = true, OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.IsFalse(setup.BestOfThreeAvailable);
            Assert.IsFalse(setup.BestOfThree);
            Assert.IsTrue(setup.OpenLobbyCountIsChoosable);

            setup.OpenLobbyPlayerCount = 3;
            Assert.AreEqual(3, setup.EffectiveOpenLobbyPlayerCount);
            Assert.AreEqual(3, setup.ToOpenGameBody()["target_player_count"]);
        }

        [Test]
        public void SwitchingFromADraftBack_PutsAReadyMadeDeckBack()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, OpponentUserIds = { 18 } };
            setup.Normalize(false);
            setup.Format = GameSetup.DuelFormat;
            setup.Normalize(false);

            Assert.AreEqual("structure", setup.DeckType);
        }

        [Test]
        public void AnUnknownPoolSource_FallsBackToTheDefault()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, PoolSource = "nonsense", OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.AreEqual("random_48", setup.PoolSource);
        }

        // --- the other draft types ----------------------------------------------------------------

        private static GameState Drafted(string deckType, string drafting, string extra = "")
        {
            var state = BoardFixtures.Load(405);
            state.Game.Status = "waiting";
            state.Game.Format = "draft";
            state.Game.DeckType = deckType;
            var block = JsonConvert.DeserializeObject<DraftMatchState>(
                "{\"status\":\"drafting\",\"games_to_win\":2," + Players + ",\"drafting\":" + drafting + "}");
            switch (deckType)
            {
                case "winston_draft": state.WinstonDraft = block; break;
                case "grid_draft": state.GridDraft = block; break;
                case "rotisserie_draft": state.RotisserieDraft = block; break;
                default: state.TieredRotisserieDraft = block; break;
            }

            return state;
        }

        [Test]
        public void Winston_NamesThePileOnYourTurn_AndWhoWeWaitForOtherwise()
        {
            var mine = Drafted("winston_draft", "{\"is_your_turn\":true,\"current_pile_number\":3,\"pile_sizes\":[1,1,2],\"remaining_deck_count\":0}");
            var theirs = Drafted("winston_draft", "{\"is_your_turn\":false,\"current_turn_username\":\"BotSage\",\"current_pile_number\":1,\"pile_sizes\":[1,1,1]}");

            Assert.AreEqual("Take pile 3, or pass", DraftDisplay.Banner(mine));
            Assert.IsTrue(DraftDisplay.NeedsAction(mine));
            Assert.AreEqual("Winston Draft", DraftDisplay.Title(mine));
            Assert.AreEqual("Waiting for BotSage's pick", DraftDisplay.Banner(theirs));
            Assert.IsFalse(DraftDisplay.NeedsAction(theirs));
            Assert.IsTrue(DraftDisplay.PassingGivesNothing(mine.WinstonDraft.AsWinstonDrafting()), "third pile, nothing left to draw");
        }

        [Test]
        public void Winston_PassingGivesNothing_OnlyOnTheLastPileWithAtMostOneCardLeft()
        {
            Assert.IsFalse(DraftDisplay.PassingGivesNothing(new WinstonDrafting { CurrentPileNumber = 2, RemainingDeckCount = 0 }));
            Assert.IsFalse(DraftDisplay.PassingGivesNothing(new WinstonDrafting { CurrentPileNumber = 3, RemainingDeckCount = 2 }));
            Assert.IsTrue(DraftDisplay.PassingGivesNothing(new WinstonDrafting { CurrentPileNumber = 3, RemainingDeckCount = 1 }));
        }

        [Test]
        public void Grid_TitleCountsRoundsAndPicks_AndLinesCountWhatIsLeft()
        {
            var state = Drafted(
                "grid_draft",
                "{\"is_your_turn\":true,\"current_round\":2,\"total_rounds\":9,\"grid_size\":3,\"picks_this_round\":1,\"total_picks_per_round\":2," +
                "\"grid_cards\":[{\"card_id\":1,\"name\":\"A\"},null,{\"card_id\":3,\"name\":\"C\"},null,null,null,{\"card_id\":7,\"name\":\"G\"},{\"card_id\":8,\"name\":\"H\"},{\"card_id\":9,\"name\":\"I\"}]}");
            var grid = state.GridDraft.AsGridDrafting();

            Assert.AreEqual("Grid Draft  -  round 2 of 9 (pick 2 of 2)", DraftDisplay.Title(state));
            Assert.AreEqual("Choose a row or column", DraftDisplay.Banner(state));
            Assert.AreEqual(2, DraftDisplay.CardsInLine(grid, "row", 0));
            Assert.AreEqual(0, DraftDisplay.CardsInLine(grid, "row", 1), "a line the first pick cleared");
            Assert.AreEqual(3, DraftDisplay.CardsInLine(grid, "row", 2));
            Assert.AreEqual(2, DraftDisplay.CardsInLine(grid, "column", 0));
            Assert.AreEqual(1, DraftDisplay.CardsInLine(grid, "column", 1));
            CollectionAssert.AreEqual(new[] { 2, 5, 8 }, DraftDisplay.LineCells("column", 2, 3));
            CollectionAssert.AreEqual(new[] { 3, 4, 5 }, DraftDisplay.LineCells("row", 1, 3));
        }

        [Test]
        public void ATakenCell_IsNullInTheGrid()
        {
            var grid = Drafted("grid_draft", "{\"grid_size\":2,\"grid_cards\":[null,{\"card_id\":2,\"name\":\"B\"},null,null]}").GridDraft.AsGridDrafting();

            Assert.IsNull(grid.GridCards[0]);
            Assert.AreEqual("B", grid.GridCards[1].Name);
        }

        [Test]
        public void Rotisserie_TitleCountsPicks()
        {
            var state = Drafted("rotisserie_draft", "{\"is_your_turn\":true,\"picks_made\":3,\"total_picks_needed\":28,\"pool_cards\":[]}");

            Assert.AreEqual("Rotisserie Draft  -  pick 4 of 28", DraftDisplay.Title(state));
            Assert.AreEqual("Choose a card", DraftDisplay.Banner(state));
            Assert.IsFalse(state.RotisserieDraft.AsRotisserieDrafting().IsTiered);
        }

        [Test]
        public void TieredRotisserie_NamesTheCurrentTier()
        {
            var state = Drafted(
                "tiered_rotisserie_draft",
                "{\"is_your_turn\":false,\"current_turn_username\":\"BotSage\",\"current_tier_index\":1,\"picks_made_this_tier\":2,\"total_picks_needed_this_tier\":8," +
                "\"tiers\":[{\"label\":\"common\",\"cutoff_count\":8,\"status\":\"completed\"},{\"label\":\"rare\",\"cutoff_count\":4,\"status\":\"current\"}],\"pool_cards\":[]}");

            Assert.AreEqual("Tiered Rotisserie Draft  -  Rare  -  pick 3 of 8", DraftDisplay.Title(state));
            Assert.AreEqual("Waiting for BotSage's pick", DraftDisplay.Banner(state));
            Assert.IsTrue(state.TieredRotisserieDraft.AsRotisserieDrafting().IsTiered);
        }

        [Test]
        public void ATierWithNoLabel_IsCalledByItsNumber()
        {
            Assert.AreEqual("Tier 3", DraftDisplay.TierName(new DraftTier(), 2));
            Assert.AreEqual("Tier 1", DraftDisplay.TierName(null, 0));
            Assert.AreEqual("Mythic", DraftDisplay.TierName(new DraftTier { Label = "mythic" }, 0));
        }

        [Test]
        public void EveryDraftTypeIsPlayable()
        {
            foreach (var type in new[] { "quick_draft", "winston_draft", "grid_draft", "rotisserie_draft", "tiered_rotisserie_draft", "sealed_deck", "sealed_pool_of_the_day", "weekly_sealed_pool", "chaos_draft" })
            {
                Assert.IsTrue(DraftDisplay.Implemented.Contains(type), type);
            }

            Assert.IsFalse(DraftDisplay.Implemented.Contains("custom_duel"));
        }

        [Test]
        public void ARotisserieDraftOnAnInProgressGame_IsNotMistakenForUnsupported()
        {
            var state = BoardFixtures.Load(406);
            state.Game.DeckType = "rotisserie_draft";

            Assert.IsNull(BoardDisplay.UnsupportedReason(state));
        }

        [TestCase("quick_draft", "quick_draft_pool_source")]
        [TestCase("winston_draft", "winston_draft_pool_source")]
        [TestCase("grid_draft", "grid_draft_pool_source")]
        [TestCase("rotisserie_draft", "rotisserie_draft_pool_source")]
        public void EachDraftType_NamesItsPoolInItsOwnField(string deckType, string field)
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = deckType, PoolSource = "structure", OpponentUserIds = { 18 } };
            setup.Normalize(false);

            var body = setup.ToDirectGameBody();
            Assert.AreEqual("structure", body[field]);
            Assert.AreEqual(1, body.Keys.Count(k => k.EndsWith("_pool_source")), "only its own");
        }

        [Test]
        public void ARotisserieDraft_SendsItsCutoff_WithinTheServersLimits()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "rotisserie_draft", RotisserieCutoff = 99, OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.AreEqual(20, setup.RotisserieCutoff);
            Assert.AreEqual(20, setup.ToDirectGameBody()["rotisserie_draft_cutoff_count"]);

            setup.RotisserieCutoff = 1;
            setup.Normalize(false);
            Assert.AreEqual(13, setup.RotisserieCutoff);
            Assert.IsFalse(new GameSetup { OpponentUserIds = { 18 } }.ToDirectGameBody().ContainsKey("rotisserie_draft_cutoff_count"));
        }

        [Test]
        public void ATieredRotisserieDraft_UsesTheRarityTiers_AndNoPoolChoice()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "tiered_rotisserie_draft", OpponentUserIds = { 18 } };
            setup.Normalize(false);

            Assert.IsFalse(setup.UsesPoolSource);
            var body = setup.ToDirectGameBody();
            Assert.AreEqual("rarity", body["tiered_rotisserie_draft_mode"]);
            Assert.IsFalse(body.Keys.Any(k => k.EndsWith("_pool_source")));
        }

        [Test]
        public void TeamGames_CanAlsoDraft_ButNotWithTheReadyMadePowerDeck()
        {
            var team = new GameSetup { Format = GameSetup.OpenTeamFormat, OpponentUserIds = { 18, 20, 21 }, PartnerUserId = 18 };
            var decks = team.DecksForFormat.Select(d => d.Id).ToList();

            CollectionAssert.IsSubsetOf(new[] { "structure", "quick_draft", "grid_draft", "tiered_rotisserie_draft" }, decks);
            CollectionAssert.DoesNotContain(decks, "power");

            team.DeckType = "winston_draft";
            team.BestOfThree = true;
            team.Normalize(false);
            Assert.AreEqual("winston_draft", team.DeckType, "kept: it is one of this format's decks");
            Assert.IsFalse(team.BestOfThree, "a drafted game is a match already");
            Assert.AreEqual("winston_draft_pool_source", team.ToDirectGameBody().Keys.Single(k => k.EndsWith("_pool_source")));
            Assert.AreEqual("team", team.ToDirectGameBody()["format"]);
        }

        // --- sealed ------------------------------------------------------------------------------

        [Test]
        public void ASealedDeck_NeedsNoPoolChoice_AndIsAMatchOfItsOwn()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "sealed_deck", BestOfThree = true, OpponentUserIds = { 18, 20 } };
            setup.Normalize(false);

            Assert.IsFalse(setup.UsesPoolSource);
            Assert.IsFalse(setup.BestOfThree);
            Assert.IsNull(setup.ValidateDirectGame());
            Assert.IsFalse(setup.ToDirectGameBody().Keys.Any(k => k.EndsWith("_pool_source")));
            Assert.AreEqual("sealed_deck", setup.ToDirectGameBody()["deck_type"]);
        }

        [Test]
        public void TheSealedPoolOfTheDay_IsForExactlyTwoPlayers()
        {
            var setup = new GameSetup { Format = GameSetup.DraftFormat, DeckType = "sealed_pool_of_the_day", OpponentUserIds = { 18, 20, 21 } };
            setup.Normalize(false);

            Assert.AreEqual(1, setup.MaxOpponents);
            CollectionAssert.AreEqual(new[] { 18 }, setup.OpponentUserIds, "extras are dropped, keeping the earliest");
            Assert.IsNull(setup.ValidateDirectGame());

            setup.OpponentUserIds.Clear();
            Assert.IsNotNull(setup.ValidateDirectGame());

            setup.OpenLobbyPlayerCount = 4;
            Assert.AreEqual(2, setup.EffectiveOpenLobbyPlayerCount);
            Assert.IsFalse(setup.OpenLobbyCountIsChoosable);
        }

        [Test]
        public void SealedDeck_IsOfferedToTeamGames_ButThePoolOfTheDayIsNot()
        {
            var team = new GameSetup { Format = GameSetup.ClosedTeamFormat };
            var decks = team.DecksForFormat.Select(d => d.Id).ToList();

            CollectionAssert.Contains(decks, "sealed_deck");
            CollectionAssert.DoesNotContain(decks, "sealed_pool_of_the_day");
        }

        [Test]
        public void ASealedGame_ReadsItsOwnBlock_AndWaitsForDecks()
        {
            var state = BoardFixtures.Load(405);
            state.Game.Status = "waiting";
            state.Game.Format = "draft";
            state.Game.DeckType = "weekly_sealed_pool";
            state.SealedDeck = JsonConvert.DeserializeObject<DraftMatchState>(Building(false, false, "{\"rare\":4,\"mythic\":2}"));

            Assert.IsTrue(DraftDisplay.InDraftStage(state));
            Assert.AreEqual("Build your deck", DraftDisplay.Banner(state));
            Assert.IsNull(BoardDisplay.UnsupportedReason(state));
            Assert.AreEqual(2, state.SealedDeck.DeckBuilding.RarityCaps["mythic"]);
        }

        // --- the sealed games as formats of their own ----------------------------------------------

        [Test]
        public void TheSealedFormats_AreChosenAsFormats_ButAreDraftGamesToTheServer()
        {
            var setup = new GameSetup { OpponentUserIds = { 18 } };

            setup.FormatChoice = "sealed_deck";

            Assert.AreEqual("draft", setup.Format);
            Assert.AreEqual("sealed_deck", setup.DeckType);
            Assert.AreEqual("sealed_deck", setup.FormatChoice);
            Assert.IsTrue(setup.IsSealedFormat);
            var body = setup.ToDirectGameBody();
            Assert.AreEqual("draft", body["format"]);
            Assert.AreEqual("sealed_deck", body["deck_type"]);

            setup.FormatChoice = "sealed_pool_of_the_day";
            Assert.AreEqual("sealed_pool_of_the_day", setup.ToDirectGameBody()["deck_type"]);
            Assert.AreEqual(1, setup.MaxOpponents);
        }

        [Test]
        public void ADraftFormat_NoLongerListsTheSealedGamesAmongItsDecks()
        {
            var setup = new GameSetup { FormatChoice = "draft" };

            var decks = setup.DecksForFormat.Select(d => d.Id).ToList();

            CollectionAssert.DoesNotContain(decks, "sealed_deck");
            CollectionAssert.DoesNotContain(decks, "sealed_pool_of_the_day");
            CollectionAssert.Contains(decks, "quick_draft");
        }

        [Test]
        public void ASealedFormat_HasNothingToChooseBeyondItself()
        {
            var setup = new GameSetup { FormatChoice = "sealed_deck", OpponentUserIds = { 18 } };
            setup.Normalize(false);

            CollectionAssert.AreEqual(new[] { "sealed_deck" }, setup.DecksForFormat.Select(d => d.Id).ToArray());
            Assert.IsFalse(setup.UsesPoolSource);
            Assert.IsNull(setup.ValidateDirectGame());
        }

        [Test]
        public void LeavingASealedFormat_PutsAnOrdinaryDeckBack()
        {
            var setup = new GameSetup { FormatChoice = "sealed_pool_of_the_day", OpponentUserIds = { 18 } };

            setup.FormatChoice = "draft";
            setup.Normalize(false);
            Assert.AreEqual("draft", setup.FormatChoice);
            Assert.AreEqual("quick_draft", setup.DeckType);

            setup.FormatChoice = "sealed_deck";
            setup.FormatChoice = "duel";
            setup.Normalize(false);
            Assert.AreEqual("duel", setup.Format);
            Assert.AreEqual("structure", setup.DeckType);
            Assert.IsFalse(setup.IsSealedFormat);
        }

        [Test]
        public void SealedDeck_IsStillADeckOfTheTeamFormats()
        {
            var setup = new GameSetup { FormatChoice = "team", DeckType = "sealed_deck" };
            setup.Normalize(false);

            Assert.AreEqual("sealed_deck", setup.DeckType);
            Assert.IsFalse(setup.IsSealedFormat, "a team game, not the sealed format");
            Assert.AreEqual("team", setup.FormatChoice);
        }
    }
}
