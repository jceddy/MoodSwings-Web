using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The deck builder: every card on the left, filtered by color, rarity and a word; the deck on the right with its
    /// name, who may see it, and a line per card with a copy count. Tap a card to add a copy, the minus beside a line
    /// to take one out, the eye on a card to read it. Saving makes a new deck or updates the one opened.
    /// </summary>
    public sealed class DeckBuilderScreen : UiScreen
    {
        private const float CardWidth = 150f;
        private const int Columns = 7;

        private bool _built;
        private DeckEditor _deck;
        private CardDetailOverlay _detail;
        private ConfirmOverlay _confirm;
        private Text _loading;
        private GameObject _body;
        private RectTransform _catalogContent;
        private RectTransform _deckContent;
        private InputField _name;
        private InputField _search;
        private Toggle _shared;
        private Text _count;
        private Text _hint;
        private Text _status;
        private Button _save;
        private bool _busy;
        private readonly HashSet<string> _colors = new HashSet<string>();
        private readonly HashSet<string> _rarities = new HashSet<string>();
        private readonly Dictionary<int, Text> _badges = new Dictionary<int, Text>();
        private readonly Dictionary<int, Text> _sideBadges = new Dictionary<int, Text>();
        private Button _deckTab;
        private Button _sideboardTab;
        private bool _sideboardMode;
        private readonly Dictionary<string, Image> _filterButtons = new Dictionary<string, Image>();

        /// <summary>The deck being built; tests read it.</summary>
        public DeckEditor Deck => _deck;

        public string StatusText => _status != null ? _status.text : null;

        public string CountText => _count != null ? _count.text : null;

        public CardDetailOverlay Detail => _detail;

        /// <summary>Cards tapped in the catalog go to the sideboard rather than the deck.</summary>
        public bool EditingSideboard => _sideboardMode;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _confirm.Dismiss();
            _detail.Close();
            _busy = false;
            _deck = null;
            _body.SetActive(false);
            _loading.gameObject.SetActive(true);
            _loading.text = "Loading the cards...";
            _colors.Clear();
            _rarities.Clear();
            _sideboardMode = false;
            _search.SetTextWithoutNotify(string.Empty);
            SetStatus(string.Empty);
            Run(() => Load(args as DeckBuilderArgs ?? new DeckBuilderArgs()));
        }

        public override bool HandleBack()
        {
            if (_confirm.Dismiss() || _detail.Dismiss())
            {
                return true;
            }

            if (_deck != null && _deck.HasUnsavedChanges)
            {
                Run(Leave);
                return true;
            }

            return false;
        }

        private async Task Load(DeckBuilderArgs args)
        {
            var catalog = await AppServices.Decklists.LoadCatalogAsync();
            if (this == null)
            {
                return;
            }

            if (!catalog.Ok)
            {
                _loading.text = catalog.Message;
                return;
            }

            var deck = new DeckEditor(AppServices.Decklists.Catalog);
            if (args.DecklistId.HasValue)
            {
                var view = await AppServices.Decklists.ViewAsync(args.DecklistId.Value);
                if (this == null)
                {
                    return;
                }

                if (!view.Ok)
                {
                    _loading.text = view.Message;
                    return;
                }

                deck.Load(view.Decklist, args.Copy);
            }

            _deck = deck;
            _loading.gameObject.SetActive(false);
            _body.SetActive(true);
            _name.SetTextWithoutNotify(_deck.Name);
            _shared.SetIsOnWithoutNotify(_deck.Visibility == DeckEditor.Friends);
            RebuildCatalog();
            RebuildDeck();
        }

        // --- structure -----------------------------------------------------------------------

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, "Deck builder", () => Run(Leave));

            _loading = UiFactory.Label(transform, string.Empty, 36, theme.textMuted);
            UiFactory.Stretch(_loading.rectTransform);

            _body = UiFactory.Create("Body", transform).gameObject;
            var body = (RectTransform)_body.transform;
            UiFactory.Stretch(body);
            body.offsetMax = new Vector2(0f, -120f);
            BuildCatalogPanel(body, theme);
            BuildDeckPanel(body, theme);

            _detail = new CardDetailOverlay(transform, theme);
            _confirm = new ConfirmOverlay(transform, theme);
        }

        private void BuildCatalogPanel(RectTransform body, UiTheme theme)
        {
            var panel = UiFactory.Create("Catalog", body);
            panel.anchorMin = new Vector2(0f, 0f);
            panel.anchorMax = new Vector2(0.64f, 1f);
            panel.offsetMin = new Vector2(24f, 16f);
            panel.offsetMax = new Vector2(-8f, -8f);
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var colors = UiFactory.Row(panel, "Colors", 8f, TextAnchor.MiddleLeft);
            UiFactory.Size(colors.gameObject, height: 56f);
            foreach (var color in CatalogFilter.Colors)
            {
                AddFilterButton(colors.transform, theme, color, Capitalize(color), _colors, tint: CardView.IndicatorColor(color));
            }

            var rarities = UiFactory.Row(panel, "Rarities", 8f, TextAnchor.MiddleLeft);
            UiFactory.Size(rarities.gameObject, height: 56f);
            foreach (var rarity in CatalogFilter.Rarities)
            {
                AddFilterButton(rarities.transform, theme, rarity, Capitalize(rarity), _rarities, tint: theme.accent);
            }

            _search = UiFactory.Input(rarities.transform, "Search name or text", theme);
            _search.gameObject.name = "Search";
            UiFactory.Flexible(_search.gameObject, width: 1f);
            UiFactory.Size(_search.gameObject, height: 56f).minWidth = 240f;
            _search.onValueChanged.AddListener(_ => RebuildCatalog());

            var scroll = UiFactory.ScrollList(panel, out _catalogContent, spacing: 8f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);
        }

        private void AddFilterButton(Transform parent, UiTheme theme, string key, string label, HashSet<string> set, Color tint)
        {
            var button = UiFactory.Button(parent, label, theme, () =>
            {
                if (!set.Remove(key))
                {
                    set.Add(key);
                }

                ShowFilters(theme);
                RebuildCatalog();
            }, primary: false);
            button.gameObject.name = "Filter " + key;
            UiFactory.Size(button.gameObject, 150f, 56f);
            button.GetComponentInChildren<Text>().fontSize = 24;
            _filterButtons[key] = button.GetComponent<Image>();
        }

        private void ShowFilters(UiTheme theme)
        {
            foreach (var pair in _filterButtons)
            {
                var on = _colors.Contains(pair.Key) || _rarities.Contains(pair.Key);
                pair.Value.color = on ? theme.accent : Color.Lerp(theme.panel, Color.white, 0.12f);
                pair.Value.GetComponentInChildren<Text>().color = on ? theme.background : theme.textPrimary;
            }
        }

        private void BuildDeckPanel(RectTransform body, UiTheme theme)
        {
            var panel = UiFactory.Create("Deck", body);
            panel.anchorMin = new Vector2(0.64f, 0f);
            panel.anchorMax = new Vector2(1f, 1f);
            panel.offsetMin = new Vector2(8f, 16f);
            panel.offsetMax = new Vector2(-24f, -8f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(20, 20, 16, 16);
            layout.spacing = 10f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _name = UiFactory.Input(panel, "Deck name", theme);
            _name.gameObject.name = "Deck name";
            _name.onValueChanged.AddListener(text =>
            {
                if (_deck != null)
                {
                    _deck.Name = text;
                    RefreshSummary();
                }
            });

            _shared = UiFactory.Toggle(panel, "Share with friends", theme, false);
            _shared.gameObject.name = "Share toggle";
            _shared.onValueChanged.AddListener(on =>
            {
                if (_deck != null)
                {
                    _deck.Visibility = on ? DeckEditor.Friends : DeckEditor.Private;
                }
            });

            // Which of the two lists the catalog adds to and the list below shows.
            var tabs = UiFactory.Row(panel, "Tabs", 8f, TextAnchor.MiddleLeft);
            UiFactory.Size(tabs.gameObject, height: 56f);
            _deckTab = UiFactory.Button(tabs.transform, "Deck", theme, () => ShowSideboard(false), primary: false);
            _deckTab.gameObject.name = "Deck tab";
            UiFactory.Flexible(_deckTab.gameObject, width: 1f);
            _sideboardTab = UiFactory.Button(tabs.transform, "Sideboard", theme, () => ShowSideboard(true), primary: false);
            _sideboardTab.gameObject.name = "Sideboard tab";
            UiFactory.Flexible(_sideboardTab.gameObject, width: 1f);

            _count = UiFactory.Label(panel, string.Empty, 30, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(_count.gameObject, height: 40f);
            _hint = UiFactory.Label(panel, string.Empty, 22, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(_hint.gameObject, height: 32f);

            var scroll = UiFactory.ScrollList(panel, out _deckContent, spacing: 6f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            _status = UiFactory.Label(panel, string.Empty, 24, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(_status.gameObject, height: 36f);
            _save = UiFactory.Button(panel, "Save deck", theme, () => Run(Save));
            _save.gameObject.name = "Save deck";
        }

        // --- the catalog ---------------------------------------------------------------------

        private void RebuildCatalog()
        {
            if (_deck == null)
            {
                return;
            }

            ShowFilters(AppServices.Theme);
            Clear(_catalogContent);
            _badges.Clear();
            _sideBadges.Clear();

            var cards = CatalogFilter.Apply(_deck.Catalog, _colors, _rarities, _search.text);
            var grid = UiFactory.Create("Cards", _catalogContent);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            var cellWidth = CardWidth + 12f;
            layout.cellSize = new Vector2(cellWidth, CardView.HeightFor(CardWidth) + 12f);
            layout.spacing = new Vector2(6f, 6f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = Columns;
            layout.childAlignment = TextAnchor.UpperLeft;

            if (cards.Count == 0)
            {
                var none = UiFactory.Label(_catalogContent, "No cards match.", 28, AppServices.Theme.textMuted);
                UiFactory.Size(none.gameObject, height: 60f);
            }

            foreach (var card in cards)
            {
                AddCatalogCell(grid, card);
            }
        }

        private void AddCatalogCell(RectTransform grid, BoardCard card)
        {
            var theme = AppServices.Theme;
            var cell = UiFactory.Create("Cell " + card.Name, grid);
            var view = CardView.Create(cell, card, CardWidth, theme, showValue: false, onClick: () => AddCard(card));
            view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
            view.anchoredPosition = Vector2.zero;

            // The card sits 6 px inside its cell.
            CardView.AddEyeButton(cell, CardWidth, 6f, "Inspect " + card.Name, theme, () => _detail.Show(card));

            // The number of copies in the deck, in a box in the card's corner (hidden when there are none).
            var box = UiFactory.Create("Count " + card.Name, cell);
            box.anchorMin = box.anchorMax = box.pivot = new Vector2(1f, 0f);
            box.sizeDelta = new Vector2(64f, 44f);
            box.anchoredPosition = new Vector2(-8f, 8f);
            var boxImage = box.gameObject.AddComponent<Image>();
            boxImage.color = theme.accent;
            boxImage.raycastTarget = false;
            var badge = UiFactory.Label(box, string.Empty, 30, theme.background, TextAnchor.MiddleCenter, FontStyle.Bold);
            badge.raycastTarget = false;
            UiFactory.Stretch(badge.rectTransform);
            _badges[card.CardId] = badge;

            // Copies in the sideboard, in a bluer box at the other corner.
            var sideBox = UiFactory.Create("Sideboard count " + card.Name, cell);
            sideBox.anchorMin = sideBox.anchorMax = sideBox.pivot = new Vector2(0f, 0f);
            sideBox.sizeDelta = new Vector2(96f, 44f);
            sideBox.anchoredPosition = new Vector2(8f, 8f);
            var sideImage = sideBox.gameObject.AddComponent<Image>();
            sideImage.color = new Color(0.30f, 0.58f, 1.00f);
            sideImage.raycastTarget = false;
            var sideBadge = UiFactory.Label(sideBox, string.Empty, 26, theme.background, TextAnchor.MiddleCenter, FontStyle.Bold);
            sideBadge.raycastTarget = false;
            UiFactory.Stretch(sideBadge.rectTransform);
            _sideBadges[card.CardId] = sideBadge;
            ShowBadge(card.CardId);
        }

        private void ShowBadge(int cardId)
        {
            if (!_badges.TryGetValue(cardId, out var badge))
            {
                return;
            }

            var count = _deck.CountOf(cardId);
            badge.text = count > 0 ? "x" + count : string.Empty;
            badge.transform.parent.gameObject.SetActive(count > 0);

            if (_sideBadges.TryGetValue(cardId, out var side))
            {
                var inSideboard = _deck.CountOf(cardId, sideboard: true);
                side.text = inSideboard > 0 ? "SB x" + inSideboard : string.Empty;
                side.transform.parent.gameObject.SetActive(inSideboard > 0);
            }
        }

        private void ShowSideboard(bool sideboard)
        {
            _sideboardMode = sideboard;
            RebuildDeck();
        }

        // --- the deck ------------------------------------------------------------------------

        private void AddCard(BoardCard card) => AddCopy(card.CardId);

        private void AddCopy(int cardId)
        {
            _deck.Add(cardId, _sideboardMode);
            ShowBadge(cardId);
            RebuildDeck();
        }

        private void RemoveCard(int cardId)
        {
            _deck.Remove(cardId, _sideboardMode);
            ShowBadge(cardId);
            RebuildDeck();
        }

        private void RebuildDeck()
        {
            if (_deck == null)
            {
                return;
            }

            Clear(_deckContent);
            var theme = AppServices.Theme;
            foreach (var entry in _deck.Entries(_sideboardMode))
            {
                var id = entry.Card.CardId;
                var row = UiFactory.Row(_deckContent, "Line " + entry.Card.Name, 10f, TextAnchor.MiddleLeft);
                UiFactory.Size(row.gameObject, height: 56f);

                var count = UiFactory.Label(row.transform, "x" + entry.Count, 28, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
                UiFactory.Size(count.gameObject, 70f, 56f);
                var name = UiFactory.Label(row.transform, entry.Card.Name, 28, CardView.IndicatorColor(entry.Card.Color), TextAnchor.MiddleLeft);
                UiFactory.Flexible(name.gameObject, width: 1f);
                var less = UiFactory.Button(row.transform, "-", theme, () => RemoveCard(id), primary: false);
                less.gameObject.name = "Less " + entry.Card.Name;
                UiFactory.Size(less.gameObject, 64f, 52f);
                var more = UiFactory.Button(row.transform, "+", theme, () => AddCopy(id), primary: false);
                more.gameObject.name = "More " + entry.Card.Name;
                UiFactory.Size(more.gameObject, 64f, 52f);
            }

            RefreshSummary();
        }

        private void RefreshSummary()
        {
            var theme = AppServices.Theme;
            ShowTab(_deckTab, "Deck (" + _deck.Count + ")", !_sideboardMode, theme);
            ShowTab(_sideboardTab, "Sideboard (" + _deck.SideboardCount + ")", _sideboardMode, theme);
            var shown = _sideboardMode ? _deck.SideboardCount : _deck.Count;
            _count.text = (_sideboardMode ? "Sideboard: " : string.Empty) + (shown == 1 ? "1 card" : shown + " cards");
            _hint.text = _sideboardMode
                ? "Cards kept aside, to swap in between games of a Power Duel match."
                : _deck.SizeHint() ?? string.Empty;
            _save.interactable = !_busy && _deck.Problem() == null;
        }

        private static void ShowTab(Button tab, string label, bool active, UiTheme theme)
        {
            var text = tab.GetComponentInChildren<Text>();
            text.text = label;
            text.fontSize = 26;
            text.color = active ? theme.background : theme.textPrimary;
            tab.GetComponent<Image>().color = active ? theme.accent : Color.Lerp(theme.panel, Color.white, 0.12f);
        }

        // --- saving and leaving --------------------------------------------------------------

        private async Task Save()
        {
            if (_busy || _deck == null)
            {
                return;
            }

            _busy = true;
            RefreshSummary();
            SetStatus("Saving...");
            var result = await AppServices.Decklists.SaveAsync(_deck);
            if (this == null)
            {
                return;
            }

            _busy = false;
            SetStatus(result.Ok ? "Saved." : result.Message, isError: !result.Ok);
            RefreshSummary();
        }

        private async Task Leave()
        {
            if (_deck != null && _deck.HasUnsavedChanges
                && !await _confirm.AskAsync("This deck has changes you haven't saved. Leave anyway?", "Leave", "Keep editing"))
            {
                return;
            }

            if (this != null)
            {
                _deck?.MarkSaved();
                Router.Back();
            }
        }

        private void SetStatus(string message, bool isError = false)
        {
            _status.text = message ?? string.Empty;
            _status.color = isError ? AppServices.Theme.danger : AppServices.Theme.textMuted;
        }

        private static string Capitalize(string text) => char.ToUpperInvariant(text[0]) + text.Substring(1);

        private static void Clear(RectTransform parent)
        {
            foreach (Transform child in parent)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }
        }
    }
}
