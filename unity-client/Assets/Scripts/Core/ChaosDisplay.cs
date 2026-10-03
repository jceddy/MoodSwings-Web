using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>What a Chaos Draft effect looks like to the player, in words. UI-free.</summary>
    public static class ChaosDisplay
    {
        /// <summary>"Rare - Draw a card." for choosing between two effects.</summary>
        public static string EffectSummary(ChaosEffect effect) =>
            Capitalize(effect.Rarity) + "  -  " + effect.RulesText;

        /// <summary>"Rare Chaos effect: Draw a card." for the effect sitting on a card.</summary>
        public static string EffectOnCard(ChaosEffect effect) =>
            Capitalize(effect.Rarity) + " Chaos effect: " + effect.RulesText;

        /// <summary>What attached effects have done to a card's value ("(chaos: +2)"), or null when they haven't.</summary>
        public static string ValueNote(BoardCard card)
        {
            if (card.ChaosValueOverride.HasValue)
            {
                return $"(chaos: value fixed at {card.ChaosValueOverride})";
            }

            return card.ChaosValueDelta != 0
                ? $"(chaos: {(card.ChaosValueDelta > 0 ? "+" : string.Empty)}{card.ChaosValueDelta} to the value)"
                : null;
        }

        /// <summary>Who an offer's team partner is, when it is a team's (Open Team Play) to settle.</summary>
        public static string PartnerName(GameState state)
        {
            var partner = state.You.TeammateGamePlayerId.HasValue
                ? BoardDisplay.PlayerById(state, state.You.TeammateGamePlayerId.Value)
                : null;
            return partner?.Username ?? "your partner";
        }

        /// <summary>
        /// The cards an effect can go on: your hand and, for a team's offer in Open Team Play, your partner's too
        /// (the card, and whose it is).
        /// </summary>
        public static List<KeyValuePair<BoardCard, string>> AttachableCards(GameState state)
        {
            var cards = state.You.Hand.Select(c => new KeyValuePair<BoardCard, string>(c, null)).ToList();
            if (state.ChaosOffer?.Offer != null && state.ChaosOffer.Offer.IsTeamOffer && state.You.TeammateHand != null)
            {
                var partner = PartnerName(state);
                cards.AddRange(state.You.TeammateHand.Select(c => new KeyValuePair<BoardCard, string>(c, partner)));
            }

            return cards;
        }

        private static string Capitalize(string text) =>
            string.IsNullOrEmpty(text) ? string.Empty : char.ToUpperInvariant(text[0]) + text.Substring(1);
    }
}
