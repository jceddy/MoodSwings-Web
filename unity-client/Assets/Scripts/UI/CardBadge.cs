using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>A small labeled box on a card's cell: how many copies there are, how many are in the deck, how many in the sideboard.</summary>
    public static class CardBadge
    {
        public enum Place
        {
            BottomRight,
            BottomLeft,

            /// <summary>Stacked on <see cref="BottomLeft"/>, for a second count that can show at the same time.</summary>
            AboveBottomLeft,
        }

        private const float Inset = 8f;

        /// <summary>Adds a badge to <paramref name="cell"/> and returns its text; the box itself is its parent, so hide that to hide it.</summary>
        public static Text Add(RectTransform cell, string name, string text, Place place, Color fill, Color textColor, float width = 72f, float height = 44f, int fontSize = 28)
        {
            var box = UiFactory.Create(name, cell);
            var right = place == Place.BottomRight;
            box.anchorMin = box.anchorMax = box.pivot = new Vector2(right ? 1f : 0f, 0f);
            box.sizeDelta = new Vector2(width, height);
            box.anchoredPosition = new Vector2(right ? -Inset : Inset, Inset + (place == Place.AboveBottomLeft ? height + 4f : 0f));
            var image = box.gameObject.AddComponent<Image>();
            image.color = fill;
            image.raycastTarget = false;

            var label = UiFactory.Label(box, text, fontSize, textColor, TextAnchor.MiddleCenter, FontStyle.Bold);
            label.raycastTarget = false;
            UiFactory.Stretch(label.rectTransform);
            box.gameObject.SetActive(!string.IsNullOrEmpty(text));
            return label;
        }

        /// <summary>Changes a badge's text, hiding it when there is none.</summary>
        public static void Set(Text badge, string text)
        {
            badge.text = text;
            badge.transform.parent.gameObject.SetActive(!string.IsNullOrEmpty(text));
        }
    }
}
