using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>One way to order the card stats list.</summary>
    public sealed class CardStatSort
    {
        public CardStatSort(string key, string label, Func<CardStat, object> value)
        {
            Key = key;
            Label = label;
            Value = value;
        }

        public string Key { get; }

        public string Label { get; }

        /// <summary>What a card is ordered by: a string, a number, or null for "no data" (which sorts last either way).</summary>
        public Func<CardStat, object> Value { get; }
    }

    /// <summary>
    /// The card statistics list as the web page has it: filter by set and by text, order by any column, show a page
    /// at a time. UI-free; holds the choices and applies them to the fetched figures.
    /// </summary>
    public sealed class CardStatsQuery
    {
        public const int PageSize = 40;

        // Rarity and color have an order of their own that alphabetical gets wrong.
        private static readonly string[] RarityOrder = { "common", "uncommon", "rare", "mythic" };
        private static readonly string[] ColorOrder = { "white", "blue", "black", "red", "green" };

        public static readonly IReadOnlyList<CardStatSort> Sorts = new[]
        {
            new CardStatSort("name", "Name", c => c.Name),
            new CardStatSort("set", "Set", c => c.SetCode == null ? null : (object)(c.SetCode + "/" + (c.CollectorNumber ?? 0).ToString("D5", CultureInfo.InvariantCulture))),
            new CardStatSort("rarity", "Rarity", c => (object)Array.IndexOf(RarityOrder, c.Rarity)),
            new CardStatSort("color", "Color", c => (object)Array.IndexOf(ColorOrder, c.Color)),
            new CardStatSort("times_in_deck", "In decks", c => (object)c.TimesInDeck),
            new CardStatSort("deck_win_rate", "Deck win rate", c => c.DeckWinRate),
            new CardStatSort("times_played", "Times played", c => (object)c.TimesPlayed),
            new CardStatSort("play_win_rate", "Play win rate", c => c.PlayWinRate),
            new CardStatSort("quick_draft", "Quick Draft pick", c => c.QuickDraft?.Average),
            new CardStatSort("winston_draft", "Winston pick", c => c.WinstonDraft?.Average),
            new CardStatSort("grid_draft", "Grid pick", c => c.GridDraft?.Average),
            new CardStatSort("rotisserie_draft", "Rotisserie pick", c => c.RotisserieDraft?.Average),
        };

        private string _search = string.Empty;
        private string _setCode = string.Empty;
        private int _page = 1;

        public string Search
        {
            get => _search;
            set
            {
                _search = (value ?? string.Empty).Trim();
                _page = 1;
            }
        }

        /// <summary>Only this set's cards; empty for all sets.</summary>
        public string SetCode
        {
            get => _setCode;
            set
            {
                _setCode = value ?? string.Empty;
                _page = 1;
            }
        }

        public CardStatSort Sort { get; private set; } = Sorts[0];

        public bool Ascending { get; private set; } = true;

        public int Page => _page;

        /// <summary>Orders by a column: choosing the current one again flips the direction.</summary>
        public void SortBy(string key)
        {
            var sort = Sorts.First(s => s.Key == key);
            Ascending = Sort.Key == key ? !Ascending : true;
            Sort = sort;
            _page = 1;
        }

        /// <summary>The next column in the list (the direction starts ascending again).</summary>
        public void NextSort()
        {
            var next = Sorts[(Sorts.ToList().IndexOf(Sort) + 1) % Sorts.Count];
            Sort = next;
            Ascending = true;
            _page = 1;
        }

        public void FlipDirection()
        {
            Ascending = !Ascending;
            _page = 1;
        }

        public void NextPage() => _page++;

        public void PreviousPage() => _page = Math.Max(1, _page - 1);

        /// <summary>Every set code any card carries, in order.</summary>
        public static List<string> SetCodes(IEnumerable<CardStat> cards) =>
            cards.Select(c => c.SetCode).Where(code => !string.IsNullOrEmpty(code)).Distinct().OrderBy(c => c, StringComparer.Ordinal).ToList();

        /// <summary>The next set filter after the current one: each set in turn, then back to all sets.</summary>
        public string NextSetCode(IEnumerable<CardStat> cards)
        {
            var codes = SetCodes(cards);
            if (SetCode.Length == 0)
            {
                return codes.FirstOrDefault() ?? string.Empty;
            }

            var index = codes.IndexOf(SetCode);
            return index < 0 || index + 1 >= codes.Count ? string.Empty : codes[index + 1];
        }

        /// <summary>The cards that pass the filters, in the chosen order.</summary>
        public List<CardStat> Filtered(IEnumerable<CardStat> cards)
        {
            var matches = cards.Where(c =>
                (SetCode.Length == 0 || c.SetCode == SetCode)
                && (Search.Length == 0 || (c.Name ?? string.Empty).IndexOf(Search, StringComparison.OrdinalIgnoreCase) >= 0)).ToList();

            // Cards with no value for the column always go last, whichever way it runs.
            var withValue = matches.Where(c => Value(c) != null).ToList();
            var without = matches.Where(c => Value(c) == null).ToList();
            withValue.Sort((a, b) => (Ascending ? 1 : -1) * Compare(Value(a), Value(b)));
            withValue.AddRange(without);
            return withValue;
        }

        public int PageCount(int total) => Math.Max(1, (int)Math.Ceiling(total / (double)PageSize));

        /// <summary>The page being looked at (the page number is held within range).</summary>
        public List<CardStat> OnPage(IReadOnlyList<CardStat> filtered)
        {
            _page = Math.Min(Math.Max(1, _page), PageCount(filtered.Count));
            return filtered.Skip((_page - 1) * PageSize).Take(PageSize).ToList();
        }

        public string PageText(int total) => $"Page {_page} of {PageCount(total)} ({total} card{(total == 1 ? string.Empty : "s")})";

        public bool HasPrevious => _page > 1;

        public bool HasNext(int total) => _page < PageCount(total);

        private object Value(CardStat card) => Sort.Value(card);

        private static int Compare(object a, object b)
        {
            if (a is string text)
            {
                return string.Compare(text, (string)b, StringComparison.OrdinalIgnoreCase);
            }

            return Convert.ToDouble(a, CultureInfo.InvariantCulture).CompareTo(Convert.ToDouble(b, CultureInfo.InvariantCulture));
        }
    }

    /// <summary>How a card's figures read.</summary>
    public static class CardStatsDisplay
    {
        /// <summary>"61%", or an em dash when there are no games to measure.</summary>
        public static string Rate(double? rate) => rate.HasValue ? Math.Round(rate.Value * 100).ToString("0", CultureInfo.InvariantCulture) + "%" : "—";

        /// <summary>"4.20 (12 picks)", or an em dash when it has never been drafted that way.</summary>
        public static string Pick(PickStat pick) =>
            pick == null || pick.Count == 0 || !pick.Average.HasValue
                ? "—"
                : $"{pick.Average.Value.ToString("0.00", CultureInfo.InvariantCulture)} ({pick.Count} pick{(pick.Count == 1 ? string.Empty : "s")})";

        public static string Where(CardStat card) =>
            (string.IsNullOrEmpty(card.SetCode) ? "No set" : card.SetCode + (card.CollectorNumber.HasValue ? " #" + card.CollectorNumber.Value : string.Empty))
            + "  ·  " + Capitalize(card.Rarity) + "  ·  " + Capitalize(card.Color);

        private static string Capitalize(string text) =>
            string.IsNullOrEmpty(text) ? string.Empty : char.ToUpperInvariant(text[0]) + text.Substring(1);
    }
}
