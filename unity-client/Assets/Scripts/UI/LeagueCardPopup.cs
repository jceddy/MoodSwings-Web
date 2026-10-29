using System;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// A card from a League pool shown large, with its rules and the controls to put copies of it in the deck (and, on
    /// game 1, in the sideboard). It edits the <see cref="LeagueDeckSelection"/> it was shown with and says so through
    /// <see cref="Changed"/>; whether each change is allowed is the selection's call, and a control that can't be used
    /// right now is greyed.
    /// </summary>
    public sealed class LeagueCardPopup
    {
        private const float CardWidth = 400f;

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly RectTransform _cardHolder;
        private readonly Text _title;
        private readonly Text _facts;
        private readonly Text _rules;
        private readonly Text _deckCount;
        private readonly Text _sideboardCount;
        private readonly Button _deckMore;
        private readonly Button _deckLess;
        private readonly Button _sideboardMore;
        private readonly Button _sideboardLess;
        private readonly GameObject _sideboardRow;
        private readonly Text _fixedNote;

        private LeagueDeckSelection _selection;
        private BoardCard _card;

        /// <summary>Called after the deck or sideboard changed, so what shows it can catch up.</summary>
        public Action Changed;

        public LeagueCardPopup(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("League card popup", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.85f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = panel.anchorMax = panel.pivot = new Vector2(0.5f, 0.5f);
            panel.sizeDelta = new Vector2(1500f, 700f);
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var split = panel.gameObject.AddComponent<HorizontalLayoutGroup>();
            split.padding = new RectOffset(36, 36, 30, 30);
            split.spacing = 40f;
            split.childControlWidth = true;
            split.childControlHeight = true;
            split.childForceExpandWidth = false;
            split.childForceExpandHeight = true;

            _cardHolder = UiFactory.Create("Card", panel);
            var cardSize = UiFactory.Size(_cardHolder.gameObject, CardWidth, CardView.HeightFor(CardWidth));
            // Pinned, so the rules text beside it wraps rather than squeezing the card.
            cardSize.minWidth = CardWidth;
            cardSize.minHeight = CardView.HeightFor(CardWidth);
            cardSize.flexibleWidth = 0f;
            cardSize.flexibleHeight = 0f;

            var right = UiFactory.Create("Controls", panel);
            UiFactory.Flexible(right.gameObject, width: 1f).preferredWidth = 0f;
            var layout = right.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 14f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _title = UiFactory.Label(right, string.Empty, 44, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 60f);
            _facts = UiFactory.Label(right, string.Empty, 26, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(_facts.gameObject, height: 36f);
            _rules = UiFactory.Label(right, string.Empty, 28, theme.textPrimary, TextAnchor.UpperLeft);
            UiFactory.Flexible(_rules.gameObject, height: 1f);

            _deckCount = AddRow(right, "Deck", "In your deck", out _deckLess, out _deckMore, out _);
            _sideboardCount = AddRow(right, "Sideboard", "In your sideboard", out _sideboardLess, out _sideboardMore, out _sideboardRow);
            _fixedNote = UiFactory.Label(right, string.Empty, 26, theme.textMuted, TextAnchor.MiddleLeft);
            _fixedNote.gameObject.name = "Sideboard note";
            UiFactory.Size(_fixedNote.gameObject, height: 40f);

            var done = UiFactory.Button(right, "Done", theme, Close);
            done.gameObject.name = "Done";
            UiFactory.Size(done.gameObject, height: UiFactory.ControlHeight);

            _deckLess.onClick.AddListener(() => Apply(_selection.RemoveFromDeck));
            _deckMore.onClick.AddListener(() => Apply(_selection.AddToDeck));
            _sideboardLess.onClick.AddListener(() => Apply(_selection.RemoveFromSideboard));
            _sideboardMore.onClick.AddListener(() => Apply(_selection.AddToSideboard));

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string DeckText => _deckCount.text;

        public string SideboardText => _sideboardCount.text;

        public BoardCard Card => _card;

        public void Show(BoardCard card, LeagueDeckSelection selection)
        {
            _card = card;
            _selection = selection;

            foreach (Transform child in _cardHolder)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }

            var view = CardView.Create(_cardHolder, card, CardWidth, _theme, showValue: false);
            UiFactory.Stretch(view);

            _title.text = card.Name;
            _facts.text = $"{card.Copies} owned" + (selection.FixedPool ? " for this match" : string.Empty);
            _rules.text = card.RulesText ?? string.Empty;
            _root.transform.SetAsLastSibling();
            _root.SetActive(true);
            Refresh();
        }

        public void Close() => _root.SetActive(false);

        /// <summary>Back closes it. True if it was open.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            Close();
            return true;
        }

        private void Apply(Func<BoardCard, bool> change)
        {
            if (_selection != null && change(_card))
            {
                Refresh();
                Changed?.Invoke();
            }
        }

        private void Refresh()
        {
            _deckCount.text = _selection.InDeck(_card).ToString();
            _deckMore.interactable = _selection.CanAddToDeck(_card);
            _deckLess.interactable = _selection.CanRemoveFromDeck(_card);

            // On a fixed pool there's no sideboard to edit: whatever the deck leaves over is it.
            _sideboardRow.SetActive(!_selection.FixedPool);
            _sideboardCount.text = _selection.InSideboard(_card).ToString();
            _sideboardMore.interactable = _selection.CanAddToSideboard(_card);
            _sideboardLess.interactable = _selection.CanRemoveFromSideboard(_card);
            _fixedNote.gameObject.SetActive(_selection.FixedPool);
            _fixedNote.text = _selection.FixedPool ? $"{_selection.InSideboard(_card)} in your sideboard (what your deck leaves over)." : string.Empty;
        }

        // One line: what it counts, how many there are now, and the minus and plus beside it.
        private Text AddRow(RectTransform parent, string name, string label, out Button less, out Button more, out GameObject row)
        {
            var line = UiFactory.Row(parent, name + " row", 16f, TextAnchor.MiddleLeft);
            UiFactory.Size(line.gameObject, height: 84f);
            row = line.gameObject;
            var caption = UiFactory.Label(line.transform, label, 30, _theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Flexible(caption.gameObject, width: 1f);

            less = UiFactory.Button(line.transform, "-", _theme, () => { }, primary: false);
            less.gameObject.name = name + " minus";
            UiFactory.Size(less.gameObject, 96f, 72f);
            var count = UiFactory.Label(line.transform, "0", 44, _theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            count.gameObject.name = name + " count";
            UiFactory.Size(count.gameObject, 90f, 72f);
            more = UiFactory.Button(line.transform, "+", _theme, () => { }, primary: false);
            more.gameObject.name = name + " plus";
            UiFactory.Size(more.gameObject, 96f, 72f);
            return count;
        }
    }
}
