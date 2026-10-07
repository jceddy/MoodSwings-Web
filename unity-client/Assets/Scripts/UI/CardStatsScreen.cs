using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Server-wide figures for every card: how often it made a deck and how those games went, how often it was
    /// played, and how early it gets taken in each draft format. Searchable, filtered by set, ordered by any
    /// column, a page at a time; tapping a card shows it.
    /// </summary>
    public sealed class CardStatsScreen : ListScreen
    {
        private readonly CardStatsQuery _query = new CardStatsQuery();
        private InputField _search;
        private Text _setLabel;
        private Text _sortLabel;
        private Text _directionLabel;
        private Text _pageLabel;
        private Button _previous;
        private Button _next;
        private CardDetailOverlay _detail;

        protected override string Title => "Card stats";

        protected override bool StatusBelowList => true;

        public CardStatsQuery Query => _query;

        public string PageText { get; private set; } = string.Empty;

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            var filters = UiFactory.Row(column, "Filters", 10f, TextAnchor.MiddleLeft);
            UiFactory.Size(filters.gameObject, height: 56f);
            _search = UiFactory.Input(filters.transform, "Search by name", theme);
            _search.gameObject.name = "Search";
            UiFactory.Flexible(_search.gameObject, width: 1f);
            UiFactory.Size(_search.gameObject, height: 56f).minWidth = 280f;
            _search.onValueChanged.AddListener(text =>
            {
                _query.Search = text;
                Rebuild();
            });
            _setLabel = SmallButton(filters.transform, theme, "All sets", 230f, "Set filter", () =>
            {
                _query.SetCode = _query.NextSetCode(AppServices.Stats.CardStats);
                Rebuild();
            });

            var order = UiFactory.Row(column, "Order", 10f, TextAnchor.MiddleLeft);
            UiFactory.Size(order.gameObject, height: 56f);
            var by = UiFactory.Label(order.transform, "Order by", 24, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(by.gameObject, 120f);
            _sortLabel = SmallButton(order.transform, theme, _query.Sort.Label, 360f, "Sort", () =>
            {
                _query.NextSort();
                Rebuild();
            });
            _directionLabel = SmallButton(order.transform, theme, "▲ Low to high", 240f, "Direction", () =>
            {
                _query.FlipDirection();
                Rebuild();
            });
        }

        protected override void BuildBelow(RectTransform column, UiTheme theme)
        {
            var pager = UiFactory.Row(column, "Pager", 10f, TextAnchor.MiddleCenter);
            UiFactory.Size(pager.gameObject, height: 56f);
            var previous = SmallButton(pager.transform, theme, "< Previous", 200f, "Previous page", () =>
            {
                _query.PreviousPage();
                Rebuild();
            });
            _previous = previous.GetComponentInParent<Button>();
            _pageLabel = UiFactory.Label(pager.transform, string.Empty, 24, theme.textMuted);
            _pageLabel.gameObject.name = "Page";
            UiFactory.Flexible(_pageLabel.gameObject, width: 1f);
            var next = SmallButton(pager.transform, theme, "Next >", 200f, "Next page", () =>
            {
                _query.NextPage();
                Rebuild();
            });
            _next = next.GetComponentInParent<Button>();
        }

        private static Text SmallButton(Transform parent, UiTheme theme, string text, float width, string name, UnityEngine.Events.UnityAction onClick)
        {
            var button = UiFactory.Button(parent, text, theme, onClick, primary: false);
            button.gameObject.name = name;
            UiFactory.Size(button.gameObject, width, 56f).minWidth = width;
            var label = button.GetComponentInChildren<Text>();
            label.fontSize = 24;
            return label;
        }

        public override void OnShown(object args)
        {
            EnsureBuilt();
            if (_detail == null)
            {
                _detail = new CardDetailOverlay(transform, AppServices.Theme);
            }

            _detail.Close();
            SetStatus(string.Empty);
            AppServices.Stats.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Stats.Changed -= Rebuild;
            _detail?.Close();
        }

        public override bool HandleBack() => _detail != null && _detail.Dismiss();

        private async Task Refresh()
        {
            var result = await AppServices.Stats.RefreshCardStatsAsync();
            if (this == null)
            {
                return;
            }

            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            // The card list is only for showing a tapped card; stats work without it.
            await AppServices.Decklists.LoadCatalogAsync();
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var stats = AppServices.Stats;

            _setLabel.text = _query.SetCode.Length == 0 ? "All sets" : "Set " + _query.SetCode;
            _sortLabel.text = _query.Sort.Label;
            _directionLabel.text = _query.Ascending ? "▲ Low to high" : "▼ High to low";

            if (!stats.CardStatsLoaded)
            {
                var loading = UiFactory.Label(List, "Loading the card stats...", 26, theme.textMuted);
                UiFactory.Size(loading.gameObject, height: 60f);
                PageText = string.Empty;
                _pageLabel.text = string.Empty;
                _previous.interactable = _next.interactable = false;
                return;
            }

            var filtered = _query.Filtered(stats.CardStats);
            var page = _query.OnPage(filtered);
            PageText = _query.PageText(filtered.Count);
            _pageLabel.text = PageText;
            _previous.interactable = _query.HasPrevious;
            _next.interactable = _query.HasNext(filtered.Count);

            if (page.Count == 0)
            {
                var none = UiFactory.Label(List, "No cards match.", 26, theme.textMuted);
                UiFactory.Size(none.gameObject, height: 60f);
                return;
            }

            foreach (var card in page)
            {
                AddRow(theme, card);
            }
        }

        private void AddRow(UiTheme theme, CardStat card)
        {
            var row = UiFactory.Create("Card " + card.Name, List);
            var image = row.gameObject.AddComponent<Image>();
            image.color = theme.panel;
            var button = row.gameObject.AddComponent<Button>();
            button.targetGraphic = image;
            button.onClick.AddListener(() => ShowCard(card));

            var layout = row.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.padding = new RectOffset(20, 18, 12, 12);
            layout.spacing = 18f;
            layout.childAlignment = TextAnchor.MiddleLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var swatch = UiFactory.Create("Color", row);
            swatch.gameObject.AddComponent<Image>().color = CardView.IndicatorColor(card.Color);
            UiFactory.Size(swatch.gameObject, 10f, 70f).minWidth = 10f;

            var title = UiFactory.TwoLineText(row, theme, card.Name, CardStatsDisplay.Where(card));
            UiFactory.Flexible(title.gameObject, width: 1f);

            var figures = UiFactory.Create("Figures", row);
            var column = figures.gameObject.AddComponent<VerticalLayoutGroup>();
            column.spacing = 2f;
            column.childControlWidth = true;
            column.childControlHeight = true;
            column.childForceExpandWidth = true;
            column.childForceExpandHeight = false;
            UiFactory.Size(figures.gameObject, 470f).minWidth = 470f;

            UiFactory.Label(figures, $"In {card.TimesInDeck} decks  ·  won {CardStatsDisplay.Rate(card.DeckWinRate)}", 22, theme.textPrimary, TextAnchor.MiddleRight);
            UiFactory.Label(figures, $"Played {card.TimesPlayed}×  ·  won {CardStatsDisplay.Rate(card.PlayWinRate)}", 22, theme.textPrimary, TextAnchor.MiddleRight);
            UiFactory.Label(figures, "Picked at: " + PickLine(card), 20, theme.textMuted, TextAnchor.MiddleRight);
        }

        /// <summary>Where it goes in each format that has seen it, e.g. "Quick 4.20, Grid 2.00" ("not drafted yet" when none has).</summary>
        public static string PickLine(CardStat card)
        {
            var parts = new[]
            {
                ("Quick", card.QuickDraft), ("Winston", card.WinstonDraft), ("Grid", card.GridDraft), ("Rotisserie", card.RotisserieDraft),
            }.Where(p => p.Item2 != null && p.Item2.Count > 0 && p.Item2.Average.HasValue)
             .Select(p => $"{p.Item1} {p.Item2.Average.Value.ToString("0.0", System.Globalization.CultureInfo.InvariantCulture)}")
             .ToList();
            return parts.Count == 0 ? "not drafted yet" : string.Join(", ", parts);
        }

        private void ShowCard(CardStat stat)
        {
            var card = AppServices.Decklists.Catalog.FirstOrDefault(c => c.CardId == stat.CatalogCardId);
            if (card != null)
            {
                _detail.Show(card);
            }
        }
    }
}
