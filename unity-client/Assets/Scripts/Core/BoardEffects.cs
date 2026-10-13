using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>How one mood is acting on another.</summary>
    public enum EffectLinkKind
    {
        /// <summary>The source switches the target's ability off.</summary>
        Suppress,

        /// <summary>The source changes which value the target has (its dice value).</summary>
        Boost,

        /// <summary>The target is held by someone else because of the source.</summary>
        Control,
    }

    /// <summary>An ongoing effect that one mood in play is having on another, drawn as a line between them.</summary>
    public readonly struct EffectLink : IEquatable<EffectLink>
    {
        public EffectLink(int sourceCardId, int targetCardId, EffectLinkKind kind)
        {
            SourceCardId = sourceCardId;
            TargetCardId = targetCardId;
            Kind = kind;
        }

        public int SourceCardId { get; }

        public int TargetCardId { get; }

        public EffectLinkKind Kind { get; }

        public bool Equals(EffectLink other) =>
            SourceCardId == other.SourceCardId && TargetCardId == other.TargetCardId && Kind == other.Kind;

        public override bool Equals(object obj) => obj is EffectLink other && Equals(other);

        public override int GetHashCode() => (SourceCardId * 397 ^ TargetCardId) * 31 + (int)Kind;
    }

    /// <summary>
    /// Which moods in play are doing something to the game or to each other right now, from what the server says: the
    /// board shows the first as a glow around the mood and the second as a line from one mood to the other.
    /// </summary>
    public static class BoardEffects
    {
        /// <summary>
        /// Every effect one mood in play has on another that is in play too. Suppression, dice-value boosts and a mood
        /// being held for someone else, once each however many ways the server mentions it.
        /// </summary>
        public static List<EffectLink> Links(GameState state)
        {
            var links = new List<EffectLink>();
            if (state?.InPlay == null)
            {
                return links;
            }

            var inPlay = new HashSet<int>(state.InPlay.Select(c => c.CardId));

            void Add(int? source, int target, EffectLinkKind kind)
            {
                if (!source.HasValue || source.Value == target || !inPlay.Contains(source.Value) || !inPlay.Contains(target))
                {
                    return;
                }

                var link = new EffectLink(source.Value, target, kind);
                if (!links.Contains(link))
                {
                    links.Add(link);
                }
            }

            foreach (var card in state.InPlay)
            {
                foreach (var suppression in card.Suppressions ?? new List<Suppression>())
                {
                    Add(suppression.SuppressedByCardId, card.CardId, EffectLinkKind.Suppress);
                }

                Add(card.BoostedByCardId, card.CardId, EffectLinkKind.Boost);

                if (card.TemporaryOwnership != null)
                {
                    Add(card.TemporaryOwnership.SourceCardId, card.CardId, EffectLinkKind.Control);
                }

                // From the other end: what this card says it is acting on (the same links when the target says so too).
                foreach (var entry in card.Affecting ?? new List<AffectingEntry>())
                {
                    switch (entry.Relationship)
                    {
                        case "suppressed":
                            Add(card.CardId, entry.CardId, EffectLinkKind.Suppress);
                            break;
                        case "dice_value":
                            Add(card.CardId, entry.CardId, EffectLinkKind.Boost);
                            break;
                    }
                }
            }

            return links;
        }

        /// <summary>True for a card whose rules say it does something for as long as it is in play.</summary>
        public static bool HasWhileInPlayEffect(BoardCard card) =>
            !string.IsNullOrEmpty(card.RulesText) && card.RulesText.IndexOf("while in play", StringComparison.OrdinalIgnoreCase) >= 0;

        /// <summary>
        /// A mood whose ability is having an effect on the game right now, which is shown as a glow. Not one that is
        /// switched off, and not one that only waits for a condition that isn't met: its value having moved (Loyalty with
        /// its blue and green moods), the server listing it among the effects changing scoring or the board, or its being
        /// the source of an effect on another mood.
        /// </summary>
        public static bool IsAffectingTheGame(GameState state, BoardCard card, ICollection<EffectLink> links = null)
        {
            if (card.IsSuppressed || !HasWhileInPlayEffect(card))
            {
                return false;
            }

            if (card.ValueIsModified && card.ChaosValueDelta == 0 && card.ChaosValueOverride == null)
            {
                return true;
            }

            if (state.Round != null
                && (state.Round.ScoringEffects.Any(e => e.CardId == card.CardId) || state.Round.BoardEffects.Any(e => e.CardId == card.CardId)))
            {
                return true;
            }

            return (links ?? Links(state)).Any(l => l.SourceCardId == card.CardId);
        }
    }
}
