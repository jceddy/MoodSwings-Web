using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// The waiting room of a custom duel: each player chooses a deck of their own, built to the creator's rules, before
    /// the game starts. UI-free. (Game 2 and 3 of a sideboarded Power Duel are the same, choosing from a fixed pool.)
    /// </summary>
    public static class DuelDeckDisplay
    {
        /// <summary>A custom duel that hasn't started, and the viewer is one of its players: the board shows deck choosing.</summary>
        public static bool InStage(GameState state) =>
            state.Game.Status == "waiting"
            && state.Game.DeckType == GameSetup.CustomDuel
            && BoardDisplay.Viewer(state) != null
            && DraftDisplay.IsUnderway(state);

        /// <summary>The viewer still has to choose their deck.</summary>
        public static bool NeedsAction(GameState state) =>
            InStage(state) && !BoardDisplay.Viewer(state).DeckSubmitted;

        public static bool AllSubmitted(GameState state) =>
            state.Players.Count > 0 && state.Players.All(p => p.DeckSubmitted);

        public static string Banner(GameState state)
        {
            if (!InStage(state))
            {
                return null;
            }

            if (!BoardDisplay.Viewer(state).DeckSubmitted)
            {
                return "Choose your deck";
            }

            var waiting = state.Players.Where(p => !p.DeckSubmitted).Select(p => p.Username).ToList();
            return waiting.Count == 0 ? "Starting the game..." : $"Waiting for {string.Join(", ", waiting)}'s deck";
        }

        public static string Line(GameState state) => InStage(state) ? "Choosing decks" : null;

        /// <summary>The rules in a sentence: "At least 15 cards. At most 1 mythic. At most 1 copy of any rare card."</summary>
        public static string RulesSummary(DuelDeckRules rules)
        {
            if (rules == null)
            {
                return string.Empty;
            }

            var parts = new List<string> { $"At least {rules.MinCards} cards." };
            var rarityLimits = Rarities.Where(r => rules.RarityLimits.ContainsKey(r)).Select(r => $"{rules.RarityLimits[r]} {r}").ToList();
            if (rarityLimits.Count > 0)
            {
                parts.Add("At most " + string.Join(", ", rarityLimits) + ".");
            }

            var duplicates = Rarities.Where(r => rules.DuplicateLimits.ContainsKey(r))
                .Select(r => $"{rules.DuplicateLimits[r]} {(rules.DuplicateLimits[r] == 1 ? "copy" : "copies")} of any {r} card").ToList();
            if (duplicates.Count > 0)
            {
                parts.Add("At most " + string.Join(", ", duplicates) + ".");
            }

            if (rules.EvenColorDistributionRarities.Count > 0)
            {
                parts.Add(string.Join(", ", rules.EvenColorDistributionRarities.Select(r => r + " cards")) + " split evenly across all 5 colors.");
            }

            return string.Join(" ", parts);
        }

        private static readonly string[] Rarities = { "common", "uncommon", "rare", "mythic" };

        /// <summary>
        /// What's wrong with this deck against the rules (size, rarity limits, copies), or null. The color split is left to
        /// the server, which says so if a deck doesn't meet it.
        /// </summary>
        public static string Problem(DuelDeckRules rules, IReadOnlyCollection<BoardCard> deck)
        {
            if (rules == null)
            {
                return null;
            }

            if (deck.Count < rules.MinCards)
            {
                return $"Choose at least {rules.MinCards} cards ({deck.Count} so far).";
            }

            foreach (var limit in rules.RarityLimits)
            {
                var count = deck.Count(c => c.Rarity == limit.Key);
                if (count > limit.Value)
                {
                    return $"At most {limit.Value} {limit.Key} cards ({count} chosen).";
                }
            }

            foreach (var group in deck.GroupBy(c => c.CardId))
            {
                var first = group.First();
                if (first.Rarity != null && rules.DuplicateLimits.TryGetValue(first.Rarity, out var most) && group.Count() > most)
                {
                    return $"At most {most} {(most == 1 ? "copy" : "copies")} of {first.Name} ({group.Count()} chosen).";
                }
            }

            return null;
        }

        /// <summary>A deck as the server reads decklist text: one "count name" line per card.</summary>
        public static string DeckText(IEnumerable<BoardCard> deck) =>
            string.Join("\n", deck.GroupBy(c => c.CardId).Select(g => $"{g.Count()} {g.First().Name}"));
    }
}
