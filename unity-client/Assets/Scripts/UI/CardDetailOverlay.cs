using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>A card shown large with its rules over a screen; a tap anywhere (or Back) closes it.</summary>
    public sealed class CardDetailOverlay
    {
        // As large as the screen allows (a 1080-high canvas less the padding): the card keeps this size, and the text wraps.
        private const float CardWidth = 600f;

        private readonly UiTheme _theme;
        private readonly GameObject _root;
        private readonly RectTransform _cardHolder;
        private readonly Text _text;

        public CardDetailOverlay(Transform parent, UiTheme theme)
        {
            _theme = theme;
            var root = UiFactory.Create("Card detail overlay", parent);
            _root = root.gameObject;
            UiFactory.Stretch(root);
            var shade = root.gameObject.AddComponent<Image>();
            shade.color = new Color(0f, 0f, 0f, 0.85f);
            root.gameObject.AddComponent<Button>().onClick.AddListener(Close);

            var row = UiFactory.Row(root, "Content", 60f, TextAnchor.MiddleCenter);
            UiFactory.Stretch((RectTransform)row.transform);
            row.padding = new RectOffset(120, 120, 80, 80);

            _cardHolder = UiFactory.Create("Card", row.transform);
            var cardSize = UiFactory.Size(_cardHolder.gameObject, CardWidth, CardView.HeightFor(CardWidth));
            // A layout group squeezes a child toward its minimum when a neighbor wants more room, and a minimum of
            // nothing lets long rules text shrink the card; pinning the minimum makes the text wrap instead.
            cardSize.minWidth = CardWidth;
            cardSize.minHeight = CardView.HeightFor(CardWidth);
            cardSize.flexibleWidth = 0f;
            cardSize.flexibleHeight = 0f;

            _text = UiFactory.Label(row.transform, string.Empty, 32, theme.textPrimary, TextAnchor.MiddleLeft);
            _text.supportRichText = true;
            UiFactory.Flexible(_text.gameObject, width: 1f);
            _text.GetComponent<LayoutElement>().preferredWidth = 0f; // take the leftover width and wrap within it

            _root.SetActive(false);
        }

        public bool IsOpen => _root.activeSelf;

        public string BodyText => _text.text;

        public void Show(BoardCard card, string where = null)
        {
            foreach (Transform child in _cardHolder)
            {
                child.gameObject.SetActive(false);
                Object.Destroy(child.gameObject);
            }

            var view = CardView.Create(_cardHolder, card, CardWidth, _theme, showValue: false);
            UiFactory.Stretch(view);

            var muted = ColorUtility.ToHtmlStringRGB(_theme.textMuted);
            var lines = new System.Collections.Generic.List<string> { "<size=46><b>" + card.Name + "</b></size>" };
            var facts = string.Join("  -  ", new[] { Capitalize(card.Color), Capitalize(card.Rarity), "Value " + card.Value }.Where(s => !string.IsNullOrEmpty(s)));
            lines.Add($"<color=#{muted}>{facts}</color>");
            if (!string.IsNullOrEmpty(where))
            {
                lines.Add($"<color=#{muted}>{where}</color>");
            }

            lines.Add(string.Empty);
            lines.Add(string.IsNullOrWhiteSpace(card.RulesText) ? string.Empty : card.RulesText);
            lines.Add(string.Empty);
            lines.Add($"<color=#{muted}>Tap anywhere to close.</color>");
            _text.text = string.Join("\n", lines);
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

        private static string Capitalize(string text) =>
            string.IsNullOrEmpty(text) ? null : char.ToUpperInvariant(text[0]) + text.Substring(1);
    }
}
