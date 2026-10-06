using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Every card in the discard pile, newest first. Most are just to look at, but an effect (Harmony, Grief, Angst,
    /// Melancholy...) can make some of them playable this turn: those are framed, and tapping one starts playing it.
    /// </summary>
    public sealed class DiscardPileOverlay
    {
        private const float CardWidth = 150f;

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _note;
        private readonly RectTransform _content;
        private string _signature;

        /// <summary>A card was tapped; the second argument says whether it can be played right now.</summary>
        public Action<BoardCard, bool> Chosen;

        public DiscardPileOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("Discard pile overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            root.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.85f);

            var panel = UiFactory.Create("Panel", root);
            panel.anchorMin = new Vector2(0.05f, 0.05f);
            panel.anchorMax = new Vector2(0.95f, 0.95f);
            panel.offsetMin = panel.offsetMax = Vector2.zero;
            panel.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = panel.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(36, 36, 24, 24);
            layout.spacing = 12f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            _title = UiFactory.Label(panel, string.Empty, 42, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 56f);
            _note = UiFactory.Label(panel, string.Empty, 26, theme.textPrimary, TextAnchor.MiddleCenter);
            UiFactory.Size(_note.gameObject, height: 40f);

            var scroll = UiFactory.ScrollList(panel, out _content, spacing: 10f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            var close = UiFactory.Button(panel, "Close", theme, Close, primary: false);
            close.gameObject.name = "Close discard pile";
            UiFactory.Size(close.gameObject, 360f);

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string NoteText => _note.text;

        /// <summary>Draws the pile. Redrawing is skipped when nothing about it has changed, so a refresh doesn't scroll it back to the top.</summary>
        public void Show(IReadOnlyList<BoardCard> pile, bool canAct)
        {
            var signature = canAct + "|" + string.Join(",", pile.Select(c => c.CardId + (c.IsPlayable ? "p" : string.Empty)));
            var wasOpen = IsOpen;
            _root.SetActive(true);
            if (wasOpen && signature == _signature)
            {
                return;
            }

            _signature = signature;
            foreach (Transform child in _content)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }

            var playable = canAct ? pile.Count(c => c.IsPlayable) : 0;
            _title.text = $"Discard pile ({pile.Count})";
            _note.text = playable > 0
                ? "Something lets you play from here: tap a framed card."
                : pile.Count == 0 ? "Nothing has been discarded yet." : "Newest first. Tap a card to read it.";
            _note.color = playable > 0 ? _theme.accent : _theme.textMuted;

            var grid = UiFactory.Create("Cards", _content);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(CardWidth + 16f, CardView.HeightFor(CardWidth) + 16f);
            layout.spacing = new Vector2(8f, 8f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = 9;
            layout.childAlignment = TextAnchor.UpperCenter;

            foreach (var entry in pile.Reverse())
            {
                var card = entry;
                var canPlay = canAct && card.IsPlayable;
                var cell = UiFactory.Create("Cell " + card.Name, grid);
                var frame = cell.gameObject.AddComponent<Image>();
                frame.color = canPlay ? _theme.accent : Color.clear;
                frame.raycastTarget = false;
                var view = CardView.Create(cell, card, CardWidth, _theme, showValue: false, onClick: () => Chosen?.Invoke(card, canPlay));
                view.name = "Discarded " + card.Name;
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
            }
        }

        public void Close()
        {
            _root.SetActive(false);
            _signature = null;
        }

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
    }
}
