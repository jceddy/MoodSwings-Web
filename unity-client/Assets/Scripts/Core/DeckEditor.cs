using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>One line of a deck in the builder: a card and how many copies.</summary>
    public sealed class DeckEntry
    {
        public BoardCard Card { get; set; }

        public int Count { get; set; }
    }

    /// <summary>
    /// A deck being built: a name, who may see it, and cards with copy counts, drawn from the catalog. UI-free.
    /// A saved deck can also carry a sideboard, which the builder keeps as it was and sends back unchanged.
    /// </summary>
    public sealed class DeckEditor
    {
        public const string Private = "private";

        public const string Friends = "friends";

        /// <summary>A game with a custom deck needs at least this many cards (15, plus 15 per player beyond two).</summary>
        public const int UsualMinimum = 15;

        private readonly Dictionary<int, BoardCard> _catalog;
        private readonly Dictionary<int, int> _counts = new Dictionary<int, int>();
        private List<int> _sideboard = new List<int>();
        private string _savedFingerprint = string.Empty;

        public DeckEditor(IEnumerable<BoardCard> catalog)
        {
            Catalog = catalog.OrderBy(c => c.CardId).ToList();
            _catalog = Catalog.ToDictionary(c => c.CardId);
            MarkSaved();
        }

        public IReadOnlyList<BoardCard> Catalog { get; }

        public string Name { get; set; } = string.Empty;

        public string Visibility { get; set; } = Private;

        /// <summary>The saved deck this is (for Save to update), or null for a new one.</summary>
        public int? DecklistId { get; set; }

        public int Count => _counts.Values.Sum();

        public int SideboardCount => _sideboard.Count;

        public int CountOf(int cardId) => _counts.TryGetValue(cardId, out var count) ? count : 0;

        public void Add(int cardId)
        {
            if (_catalog.ContainsKey(cardId))
            {
                _counts[cardId] = CountOf(cardId) + 1;
            }
        }

        public void Remove(int cardId)
        {
            var count = CountOf(cardId);
            if (count <= 1)
            {
                _counts.Remove(cardId);
            }
            else
            {
                _counts[cardId] = count - 1;
            }
        }

        public void Clear() => _counts.Clear();

        /// <summary>The deck's lines in the order a player reads them: by color, then rarity, then name.</summary>
        public List<DeckEntry> Entries() =>
            DraftDisplay.SortPool(_counts.Keys.Select(id => _catalog[id]))
                .Select(card => new DeckEntry { Card = card, Count = _counts[card.CardId] })
                .ToList();

        /// <summary>Every copy's card id, as the server wants it.</summary>
        public List<int> ToCardIds() =>
            Entries().SelectMany(e => Enumerable.Repeat(e.Card.CardId, e.Count)).ToList();

        public List<int> SideboardCardIds() => new List<int>(_sideboard);

        /// <summary>Starts from a saved deck (or a copy of one: pass <paramref name="asCopy"/> to make a new deck of it).</summary>
        public void Load(DecklistDetail deck, bool asCopy = false)
        {
            _counts.Clear();
            foreach (var card in deck.Cards)
            {
                var id = card.CardId;
                if (_catalog.ContainsKey(id))
                {
                    _counts[id] = CountOf(id) + 1;
                }
            }

            _sideboard = asCopy ? new List<int>() : deck.SideboardCards.Select(c => c.CardId).ToList();
            Name = asCopy ? deck.Name + " (copy)" : deck.Name;
            Visibility = asCopy ? Private : deck.Visibility ?? Private;
            DecklistId = asCopy ? (int?)null : deck.Id;
            MarkSaved();
        }

        /// <summary>What stops this from being saved, in words; null when it can be.</summary>
        public string Problem()
        {
            if (string.IsNullOrWhiteSpace(Name))
            {
                return "Give the deck a name.";
            }

            return Count == 0 ? "Add some cards." : null;
        }

        /// <summary>A reminder (not a rule): a game's custom deck needs more cards than this one has.</summary>
        public string SizeHint() =>
            Count < UsualMinimum ? $"Custom-deck games need at least {UsualMinimum} cards." : null;

        /// <summary>True when something has changed since it was loaded or last saved.</summary>
        public bool HasUnsavedChanges => Fingerprint() != _savedFingerprint;

        public void MarkSaved() => _savedFingerprint = Fingerprint();

        private string Fingerprint() =>
            Name + "|" + Visibility + "|" + string.Join(",", _counts.OrderBy(p => p.Key).Select(p => p.Key + "x" + p.Value));
    }

    /// <summary>Picking cards from the catalog: by color, rarity and a bit of text.</summary>
    public static class CatalogFilter
    {
        public static readonly string[] Colors = { "white", "blue", "black", "red", "green" };

        public static readonly string[] Rarities = { "common", "uncommon", "rare", "mythic" };

        /// <summary>
        /// The cards that match: any of the chosen colors and rarities (none chosen means all), and whose name or rules
        /// contain the text. In the order a pool reads.
        /// </summary>
        public static List<BoardCard> Apply(IEnumerable<BoardCard> cards, ICollection<string> colors, ICollection<string> rarities, string text)
        {
            var needle = (text ?? string.Empty).Trim();
            return DraftDisplay.SortPool(cards.Where(c =>
                (colors == null || colors.Count == 0 || colors.Contains(c.Color))
                && (rarities == null || rarities.Count == 0 || rarities.Contains(c.Rarity))
                && (needle.Length == 0
                    || (c.Name ?? string.Empty).IndexOf(needle, System.StringComparison.OrdinalIgnoreCase) >= 0
                    || (c.RulesText ?? string.Empty).IndexOf(needle, System.StringComparison.OrdinalIgnoreCase) >= 0)));
        }
    }
}
