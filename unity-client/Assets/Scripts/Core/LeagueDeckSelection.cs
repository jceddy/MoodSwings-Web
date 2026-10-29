using System.Collections.Generic;
using System.Linq;
using System.Text;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// The deck a League player is building in their game, and (on game 1) the sideboard they are declaring: how many
    /// copies of each card of their pool go in each, kept to what they own, to the League's mythic and rare caps and to the
    /// sideboard's size. UI-free. On game 1 the pool is the whole collection and the sideboard is its own list of up to
    /// <see cref="LeagueDeckBuilder.SideboardMax"/> cards; from game 2 the pool is fixed at what game 1 declared and
    /// whatever the deck leaves over simply is the sideboard, so swapping is only ever between the two. The server checks
    /// all of it again when the deck is sent, so these limits are guidance.
    /// </summary>
    public sealed class LeagueDeckSelection
    {
        private readonly LeagueDeckBuilder _builder;
        private readonly Dictionary<int, int> _deck = new Dictionary<int, int>();
        private readonly Dictionary<int, int> _sideboard = new Dictionary<int, int>();

        public LeagueDeckSelection(LeagueDeckBuilder builder)
        {
            _builder = builder;
            Pool = DraftDisplay.SortPool(builder.Pool);
            Reset();
        }

        /// <summary>The cards to choose from, one entry per distinct card, in color, rarity, name order.</summary>
        public IReadOnlyList<BoardCard> Pool { get; }

        public bool FixedPool => _builder.FixedPool;

        public int SideboardMax => _builder.SideboardMax;

        public int MinCards => _builder.MinCards;

        /// <summary>The caps on a deck's rares and mythics.</summary>
        public IReadOnlyDictionary<string, int> RarityLimits => _builder.RarityLimits;

        /// <summary>
        /// Starts again from what the server suggests: last game's deck; on game 1 the deck and sideboard of your last match, or
        /// (for a first-ever match) the starter deck you were granted.
        /// </summary>
        public void Reset()
        {
            _deck.Clear();
            _sideboard.Clear();
            var owned = Pool.ToDictionary(c => c.CardId, c => c.Copies);
            foreach (var id in _builder.DefaultDeckCardIds)
            {
                var have = InDeck(id);
                if (have < (owned.TryGetValue(id, out var copies) ? copies : 0))
                {
                    _deck[id] = have + 1;
                }
            }

            // The carried-over sideboard, kept to the copies the deck left free and to the sideboard's size.
            var sideboardTotal = 0;
            foreach (var id in _builder.DefaultSideboardCardIds ?? new List<int>())
            {
                var have = _sideboard.TryGetValue(id, out var inSideboard) ? inSideboard : 0;
                if (sideboardTotal < _builder.SideboardMax && InDeck(id) + have < (owned.TryGetValue(id, out var copies) ? copies : 0))
                {
                    _sideboard[id] = have + 1;
                    sideboardTotal++;
                }
            }
        }

        /// <summary>Takes everything out of the deck and the sideboard.</summary>
        public void Clear()
        {
            _deck.Clear();
            _sideboard.Clear();
        }

        public int InDeck(BoardCard card) => InDeck(card.CardId);

        private int InDeck(int cardId) => _deck.TryGetValue(cardId, out var count) ? count : 0;

        /// <summary>Copies in the sideboard: the declared ones on game 1, and on a fixed pool whatever the deck leaves over.</summary>
        public int InSideboard(BoardCard card) =>
            _builder.FixedPool ? card.Copies - InDeck(card) : _sideboard.TryGetValue(card.CardId, out var count) ? count : 0;

        public int DeckCount => _deck.Values.Sum();

        public int SideboardCount => Pool.Sum(c => InSideboard(c));

        /// <summary>Cards of a rarity in the deck.</summary>
        public int RarityCount(string rarity) => Pool.Where(c => c.Rarity == rarity).Sum(c => InDeck(c));

        public bool CanAddToDeck(BoardCard card)
        {
            var used = InDeck(card) + (_builder.FixedPool ? 0 : InSideboard(card));
            return used < card.Copies
                && (!_builder.RarityLimits.TryGetValue(card.Rarity ?? string.Empty, out var limit) || RarityCount(card.Rarity) < limit);
        }

        public bool CanRemoveFromDeck(BoardCard card) => InDeck(card) > 0;

        public bool CanAddToSideboard(BoardCard card) =>
            !_builder.FixedPool && SideboardCount < _builder.SideboardMax && InDeck(card) + InSideboard(card) < card.Copies;

        public bool CanRemoveFromSideboard(BoardCard card) => !_builder.FixedPool && InSideboard(card) > 0;

        public bool AddToDeck(BoardCard card) => Change(_deck, card, 1, CanAddToDeck(card));

        public bool RemoveFromDeck(BoardCard card) => Change(_deck, card, -1, CanRemoveFromDeck(card));

        public bool AddToSideboard(BoardCard card) => Change(_sideboard, card, 1, CanAddToSideboard(card));

        public bool RemoveFromSideboard(BoardCard card) => Change(_sideboard, card, -1, CanRemoveFromSideboard(card));

        private static bool Change(Dictionary<int, int> zone, BoardCard card, int by, bool allowed)
        {
            if (!allowed)
            {
                return false;
            }

            var next = (zone.TryGetValue(card.CardId, out var count) ? count : 0) + by;
            if (next > 0)
            {
                zone[card.CardId] = next;
            }
            else
            {
                zone.Remove(card.CardId);
            }

            return true;
        }

        /// <summary>What is wrong with the deck as it stands (too few cards, too many rares or mythics), or null when it can be sent.</summary>
        public string Problem()
        {
            if (DeckCount < _builder.MinCards)
            {
                return $"Choose at least {_builder.MinCards} cards ({DeckCount} so far).";
            }

            foreach (var limit in _builder.RarityLimits)
            {
                var count = RarityCount(limit.Key);
                if (count > limit.Value)
                {
                    return $"At most {limit.Value} {limit.Key} cards ({count} chosen).";
                }
            }

            return null;
        }

        /// <summary>"8 card(s) in your deck (at least 12 needed; 1/2 mythic, 3/6 rare)".</summary>
        public string DeckLine()
        {
            var limits = string.Join(", ", _builder.RarityLimits.Select(l => $"{RarityCount(l.Key)}/{l.Value} {l.Key}"));
            return $"{DeckCount} card(s) in your deck (at least {_builder.MinCards} needed{(limits.Length > 0 ? "; " + limits : string.Empty)})";
        }

        /// <summary>A sentence on what to do next, which differs on game 1 (build, and declare a sideboard) and on a fixed pool (swap).</summary>
        public string Instructions() => _builder.FixedPool
            ? $"Sideboarding: swap cards between your deck and your sideboard -- {DeckLine()}, {SideboardCount} in your sideboard. "
              + "Only the cards you declared for game 1 can be used. Tap a card to add or remove copies."
            : $"Build your deck from your collection: {DeckLine()}. Sideboard: {SideboardCount}/{_builder.SideboardMax} card(s) -- "
              + "between games you can swap only your sideboard cards into your deck. Tap a card to add or remove copies.";

        /// <summary>
        /// The deck as the server reads decklist text (the same endpoint as any custom duel's deck): a "count name" line per
        /// card and, on game 1, a Sideboard section.
        /// </summary>
        public string DecklistText()
        {
            var text = new StringBuilder("About\nName League deck\n\n");
            text.Append(Lines(_deck));
            var sideboard = _builder.FixedPool ? string.Empty : Lines(_sideboard);
            if (sideboard.Length > 0)
            {
                text.Append("\n\nSideboard\n").Append(sideboard);
            }

            return text.ToString();
        }

        private string Lines(Dictionary<int, int> zone) =>
            string.Join("\n", Pool.Where(c => zone.ContainsKey(c.CardId)).Select(c => $"{zone[c.CardId]} {c.Name}"));
    }
}
