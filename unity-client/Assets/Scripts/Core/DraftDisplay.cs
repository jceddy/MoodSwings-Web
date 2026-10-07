using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// What drafting looks like from the player's side: which part of the game state is the draft, what to tell
    /// them, whether a deck is acceptable. UI-free. A draft game stays "waiting" while the deck is drafted and built,
    /// and starts once every deck is in.
    /// </summary>
    public static class DraftDisplay
    {
        /// <summary>The draft deck types the app can play; the rest still show the "open it on the web" note.</summary>
        public static readonly HashSet<string> Implemented = new HashSet<string>
        {
            "quick_draft", "winston_draft", "grid_draft", "rotisserie_draft", "tiered_rotisserie_draft",
            "sealed_deck", "sealed_pool_of_the_day", "weekly_sealed_pool", "chaos_draft",
        };

        /// <summary>How many cards you keep from each pack in a Quick Draft.</summary>
        public const int KeepPerStage = 2;

        private static readonly Dictionary<string, int> ColorRank = new Dictionary<string, int>
        {
            ["white"] = 0, ["blue"] = 1, ["black"] = 2, ["red"] = 3, ["green"] = 4,
        };

        private static readonly Dictionary<string, int> RarityRank = new Dictionary<string, int>
        {
            ["common"] = 0, ["uncommon"] = 1, ["rare"] = 2, ["mythic"] = 3,
        };

        /// <summary>Chaos Draft drafts exactly as Quick Draft does; its own part comes once the game is under way.</summary>
        public static string Kind(string deckType) => deckType == "chaos_draft" ? "quick_draft" : deckType;

        /// <summary>The draft state block for this game's deck type; null for a game that isn't a draft or hasn't one yet.</summary>
        public static DraftMatchState BlockOf(GameState state)
        {
            switch (Kind(state.Game.DeckType))
            {
                case "quick_draft":
                case "chaos_draft":
                    return state.QuickDraft;
                case "winston_draft":
                    return state.WinstonDraft;
                case "grid_draft":
                    return state.GridDraft;
                case "rotisserie_draft":
                    return state.RotisserieDraft;
                case "tiered_rotisserie_draft":
                    return state.TieredRotisserieDraft;
                case "sealed_deck":
                case "sealed_pool_of_the_day":
                case "weekly_sealed_pool":
                    return state.SealedDeck;
                default:
                    return null;
            }
        }

        /// <summary>A game whose deck is drafted or dealt as a pool, so it begins with a draft or deck-building stage.</summary>
        public static bool IsDraftGame(GameState state) =>
            state.Game.DeckType != null && state.Game.DeckType != "custom_duel" && BlockOfKnownType(state.Game.DeckType);

        private static bool BlockOfKnownType(string deckType) =>
            deckType == "quick_draft" || deckType == "chaos_draft" || deckType == "winston_draft" || deckType == "grid_draft"
            || deckType == "rotisserie_draft" || deckType == "tiered_rotisserie_draft" || deckType == "sealed_deck"
            || deckType == "sealed_pool_of_the_day" || deckType == "weekly_sealed_pool";

        /// <summary>The draft is under way or its deck is being built: the board shows that instead of a table.</summary>
        public static bool InDraftStage(GameState state)
        {
            var block = BlockOf(state);
            return state.Game.Status == "waiting" && block != null && Implemented.Contains(state.Game.DeckType) && (block.IsDrafting || block.IsBuildingDeck)
                && IsUnderway(state);
        }

        /// <summary>A synchronous game's ready check comes first; drafting starts once everyone is ready.</summary>
        public static bool IsUnderway(GameState state) =>
            !state.Game.SynchronousMode || state.Players.All(p => p.Ready);

        /// <summary>A draft match as the match summary every other match uses, so the score, next game and result read the same.</summary>
        public static MatchSummary AsMatch(DraftMatchState draft) =>
            draft == null
                ? null
                : new MatchSummary
                {
                    Status = draft.Status == "completed" ? "completed" : "in_progress",
                    YourWins = draft.YourWins,
                    OpponentWins = draft.OpponentWins,
                    GamesToWin = draft.GamesToWin,
                    Players = draft.Players,
                    NextGameId = draft.NextGameId,
                    WinnerUsernames = draft.Status == "completed" && draft.YourWins != draft.OpponentWins
                        ? draft.Players.Where(p => draft.Players.All(o => o.Wins <= p.Wins)).Select(p => p.Username).ToList()
                        : new List<string>(),
                };

        /// <summary>Every other player's deck is in.</summary>
        public static bool OthersHaveSubmitted(DraftDeckBuilding building) =>
            building.OtherPlayers.Count > 0 ? building.OtherPlayers.All(p => p.Submitted) : building.OpponentSubmitted;

        /// <summary>Your deck and everyone else's are in, so the game can start.</summary>
        public static bool AllDecksIn(GameState state)
        {
            var building = BlockOf(state)?.DeckBuilding;
            return building != null && state.Game.Status == "waiting" && building.YouSubmitted && OthersHaveSubmitted(building);
        }

        /// <summary>The draft or the deck is waiting on the viewer to choose something.</summary>
        public static bool NeedsAction(GameState state)
        {
            var block = BlockOf(state);
            if (block == null || state.Game.Status != "waiting" || BoardDisplay.Viewer(state) == null)
            {
                return false;
            }

            return block.IsBuildingDeck ? !block.DeckBuilding.YouSubmitted : IsYourPick(state);
        }

        /// <summary>It's the viewer's turn to pick, in a draft the app can play.</summary>
        public static bool IsYourPick(GameState state)
        {
            var block = BlockOf(state);
            if (block == null || !block.IsDrafting || !Implemented.Contains(state.Game.DeckType))
            {
                return false;
            }

            switch (Kind(state.Game.DeckType))
            {
                case "quick_draft":
                    return block.AsQuickDrafting()?.Status == "picking";
                case "winston_draft":
                    return block.AsWinstonDrafting()?.IsYourTurn == true;
                case "grid_draft":
                    return block.AsGridDrafting()?.IsYourTurn == true;
                default:
                    return block.AsRotisserieDrafting()?.IsYourTurn == true;
            }
        }

        /// <summary>The line for the banner while the game is being drafted or built.</summary>
        public static string Banner(GameState state)
        {
            var block = BlockOf(state);
            if (block == null)
            {
                return null;
            }

            if (block.IsBuildingDeck)
            {
                var building = block.DeckBuilding;
                if (!building.YouSubmitted)
                {
                    return "Build your deck";
                }

                return OthersHaveSubmitted(building)
                    ? "Starting the game..."
                    : $"Waiting for {WaitingOn(building)}'s deck";
            }

            if (!block.IsDrafting || !Implemented.Contains(state.Game.DeckType))
            {
                return null;
            }

            if (Kind(state.Game.DeckType) == "quick_draft")
            {
                return IsYourPick(state) ? $"Choose {KeepPerStage} cards to keep" : "Waiting for the others to pick";
            }

            if (IsYourPick(state))
            {
                switch (Kind(state.Game.DeckType))
                {
                    case "winston_draft":
                        return $"Take pile {block.AsWinstonDrafting().CurrentPileNumber}, or pass";
                    case "grid_draft":
                        return "Choose a row or column";
                    default:
                        return "Choose a card";
                }
            }

            return $"Waiting for {TurnHolder(state)}'s pick";
        }

        private static string TurnHolder(GameState state)
        {
            var block = BlockOf(state);
            string name;
            switch (Kind(state.Game.DeckType))
            {
                case "winston_draft":
                    name = block.AsWinstonDrafting()?.CurrentTurnUsername;
                    break;
                case "grid_draft":
                    name = block.AsGridDrafting()?.CurrentTurnUsername;
                    break;
                default:
                    name = block.AsRotisserieDrafting()?.CurrentTurnUsername;
                    break;
            }

            return string.IsNullOrEmpty(name) ? "the others" : name;
        }

        private static string WaitingOn(DraftDeckBuilding building)
        {
            var names = building.OtherPlayers.Where(p => !p.Submitted).Select(p => p.Username).ToList();
            return names.Count == 0 ? "the others" : string.Join(", ", names);
        }

        /// <summary>"Draft round 2 of 4  -  second pick", or "Building decks", for the small line in the header.</summary>
        public static string Line(GameState state)
        {
            var block = BlockOf(state);
            if (block == null)
            {
                return null;
            }

            return block.IsBuildingDeck ? "Building decks" : Title(state);
        }

        /// <summary>What is being drafted and how far along it is ("Grid Draft  -  round 2 of 3 (pick 1 of 2)"); null when not drafting.</summary>
        public static string Title(GameState state)
        {
            var block = BlockOf(state);
            if (block == null || !block.IsDrafting || state.Game.DeckType == null || !Implemented.Contains(state.Game.DeckType))
            {
                return null;
            }

            switch (Kind(state.Game.DeckType))
            {
                case "quick_draft":
                    return QuickTitle(block.AsQuickDrafting());
                case "winston_draft":
                    return "Winston Draft";
                case "grid_draft":
                    var grid = block.AsGridDrafting();
                    return $"Grid Draft  -  round {grid.CurrentRound} of {grid.TotalRounds} (pick {grid.PicksThisRound + 1} of {grid.TotalPicksPerRound})";
                case "rotisserie_draft":
                    var roti = block.AsRotisserieDrafting();
                    return $"Rotisserie Draft  -  pick {roti.PicksMade + 1} of {roti.TotalPicksNeeded}";
                default:
                    var tiered = block.AsRotisserieDrafting();
                    var tier = tiered.Tiers != null && tiered.CurrentTierIndex < tiered.Tiers.Count ? tiered.Tiers[tiered.CurrentTierIndex] : null;
                    return $"Tiered Rotisserie Draft  -  {TierName(tier, tiered.CurrentTierIndex)}  -  pick {tiered.PicksMadeThisTier + 1} of {tiered.TotalPicksNeededThisTier}";
            }
        }

        /// <summary>A tier's label with a capital, or "Tier 2" when it has none.</summary>
        public static string TierName(DraftTier tier, int index) =>
            tier == null || string.IsNullOrEmpty(tier.Label)
                ? "Tier " + (index + 1)
                : char.ToUpperInvariant(tier.Label[0]) + tier.Label.Substring(1);

        /// <summary>"Draft round 2 of 4  -  second pick" (or "stage 2 of 3 (passing left)" with more than two players).</summary>
        public static string QuickTitle(QuickDrafting drafting)
        {
            var stage = drafting.TotalStages > 2
                ? $"stage {drafting.Stage} of {drafting.TotalStages} (passing {drafting.PassDirection})"
                : drafting.Stage == 1 ? "first pick" : "second pick";
            return $"Draft round {drafting.Round} of {drafting.TotalRounds}  -  {stage}";
        }

        /// <summary>What to do on a Quick Draft pick, or what is being waited on.</summary>
        public static string QuickStatus(QuickDrafting drafting) =>
            drafting.Status == "picking"
                ? $"Choose {KeepPerStage} cards to keep from this {drafting.Pack.Count}-card pile -- the rest are passed on."
                : "You've made your pick for this stage -- waiting on the others to finish theirs.";

        /// <summary>"3 cards", "1 card".</summary>
        public static string CardCount(int count) => count == 1 ? "1 card" : count + " cards";

        /// <summary>The cells of a grid row or column (row by row, so index = row * size + column).</summary>
        public static List<int> LineCells(string axis, int index, int gridSize) =>
            Enumerable.Range(0, gridSize).Select(i => axis == "row" ? index * gridSize + i : index + i * gridSize).ToList();

        /// <summary>How many cards are left in a grid row or column.</summary>
        public static int CardsInLine(GridDrafting grid, string axis, int index) =>
            LineCells(axis, index, grid.GridSize).Count(cell => cell < grid.GridCards.Count && grid.GridCards[cell] != null);

        /// <summary>Winston's passing the third pile draws from the deck; with 0 or 1 cards left that gets you nothing.</summary>
        public static bool PassingGivesNothing(WinstonDrafting winston) =>
            winston.CurrentPileNumber == 3 && winston.RemainingDeckCount <= 1;

        /// <summary>A pool in the order players expect: by color (white to green), then rarity (common up), then name.</summary>
        public static List<BoardCard> SortPool(IEnumerable<BoardCard> cards) =>
            cards
                .OrderBy(c => ColorRank.TryGetValue(c.Color ?? string.Empty, out var color) ? color : 99)
                .ThenBy(c => RarityRank.TryGetValue(c.Rarity ?? string.Empty, out var rarity) ? rarity : 99)
                .ThenBy(c => c.Name)
                .ToList();

        /// <summary>"12 to 16 cards", "15 cards", or "12+ cards".</summary>
        public static string DeckSizeText(DraftDeckBuilding building)
        {
            if (building.TeamDraftedCards != null)
            {
                return building.MinDeckSize + "+ cards";
            }

            return building.MinDeckSize == building.MaxDeckSize
                ? building.MinDeckSize + " cards"
                : $"{building.MinDeckSize} to {building.MaxDeckSize} cards";
        }

        /// <summary>What's wrong with this choice of deck -- too few or too many cards, or too many of a capped rarity -- or null.</summary>
        public static string DeckProblem(DraftDeckBuilding building, IReadOnlyCollection<BoardCard> chosen)
        {
            if (chosen.Count < building.MinDeckSize)
            {
                return $"Choose at least {building.MinDeckSize} cards ({chosen.Count} so far).";
            }

            if (chosen.Count > building.MaxDeckSize)
            {
                return $"Choose at most {building.MaxDeckSize} cards ({chosen.Count} chosen).";
            }

            if (building.RarityCaps != null)
            {
                foreach (var cap in building.RarityCaps)
                {
                    var count = chosen.Count(c => c.Rarity == cap.Key);
                    if (count > cap.Value)
                    {
                        return $"At most {cap.Value} {cap.Key} cards ({count} chosen).";
                    }
                }
            }

            return null;
        }
    }
}
