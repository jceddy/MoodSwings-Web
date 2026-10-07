using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>A titled grid of cards over the board, to look through; tapping a card reads it. Back or Close dismisses it.</summary>
    public sealed class CardListOverlay
    {
        private const float CardWidth = 150f;

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly Text _title;
        private readonly Text _note;
        private readonly RectTransform _content;

        /// <summary>A card was tapped.</summary>
        public Action<BoardCard> Chosen;

        public CardListOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("Card list overlay", parent);
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
            _note = UiFactory.Label(panel, string.Empty, 26, theme.textMuted, TextAnchor.MiddleCenter);
            UiFactory.Size(_note.gameObject, height: 40f);

            var scroll = UiFactory.ScrollList(panel, out _content, spacing: 10f);
            UiFactory.Flexible(scroll.gameObject, height: 1f);

            var close = UiFactory.Button(panel, "Close", theme, Close, primary: false);
            close.gameObject.name = "Close card list";
            UiFactory.Size(close.gameObject, 360f);

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string TitleText => _title.text;

        /// <summary>The cards shown, in the order drawn.</summary>
        public IReadOnlyList<string> CardNames => _content.GetComponentsInChildren<Button>(true)
            .Where(b => b.name.StartsWith("Listed "))
            .Select(b => b.name.Substring("Listed ".Length))
            .ToList();

        public void Show(string title, string note, IReadOnlyList<BoardCard> cards)
        {
            _title.text = title;
            _note.text = note ?? string.Empty;
            foreach (Transform child in _content)
            {
                child.gameObject.SetActive(false);
                UnityEngine.Object.Destroy(child.gameObject);
            }

            var grid = UiFactory.Create("Cards", _content);
            var layout = grid.gameObject.AddComponent<GridLayoutGroup>();
            layout.cellSize = new Vector2(CardWidth + 16f, CardView.HeightFor(CardWidth) + 16f);
            layout.spacing = new Vector2(8f, 8f);
            layout.constraint = GridLayoutGroup.Constraint.FixedColumnCount;
            layout.constraintCount = 9;
            layout.childAlignment = TextAnchor.UpperCenter;

            foreach (var entry in cards)
            {
                var card = entry;
                var cell = UiFactory.Create("Cell " + card.Name, grid);
                var view = CardView.Create(cell, card, CardWidth, _theme, showValue: false, onClick: () => Chosen?.Invoke(card));
                view.name = "Listed " + card.Name;
                view.anchorMin = view.anchorMax = view.pivot = new Vector2(0.5f, 0.5f);
                view.anchoredPosition = Vector2.zero;
            }

            _root.SetActive(true);
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
    }
}
