using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.Events;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Draws one card. With the converted art present (tools/convert_card_art.py)
    /// it's the real card; without it -- art not generated on this machine, or a
    /// card that has none -- it's a drawn stand-in with the name, value and
    /// rules text, so the board never depends on the art being there. The art
    /// prints the card's main value in its top-right corner, so when an effect
    /// has changed the value a chip is laid over that corner showing the
    /// CURRENT one; an unchanged value gets no chip (the art already says it).
    /// A drawn stand-in prints no value at all, so it always gets the chip.
    /// </summary>
    public static class CardView
    {
        /// <summary>The art is 744x1040.</summary>
        public const float HeightOverWidth = 1040f / 744f;

        public static float HeightFor(float width) => width * HeightOverWidth;

        /// <param name="onItsSide">
        /// The card face is turned a quarter turn clockwise (how a suppressed mood lies on the
        /// table). Only the face turns: the returned rectangle is the space the turned card
        /// takes -- a card's height wide and its width tall -- and the SUPPRESSED label and value
        /// chip are laid out on that, upright, with the chip in its top-right corner.
        /// </param>
        public static RectTransform Create(
            Transform parent, BoardCard card, float width, UiTheme theme, bool showValue = true, UnityAction onClick = null,
            bool onItsSide = false)
        {
            var height = HeightFor(width);
            var boundsWidth = onItsSide ? height : width;
            var boundsHeight = onItsSide ? width : height;
            var rect = UiFactory.Create("Card " + card.Name, parent);
            rect.sizeDelta = new Vector2(boundsWidth, boundsHeight);
            UiFactory.Size(rect.gameObject, boundsWidth, boundsHeight);

            // The face is the card itself; turned, it sits inside the card's space as a child.
            var face = rect;
            Graphic hitArea;
            Image image;
            if (onItsSide)
            {
                var clear = rect.gameObject.AddComponent<Image>();
                clear.color = Color.clear;
                hitArea = clear;

                face = UiFactory.Create("Face", rect);
                face.anchorMin = face.anchorMax = face.pivot = new Vector2(0.5f, 0.5f);
                face.sizeDelta = new Vector2(width, height);
                face.anchoredPosition = Vector2.zero;
                face.localRotation = Quaternion.Euler(0f, 0f, -90f);
                image = face.gameObject.AddComponent<Image>();
            }
            else
            {
                image = rect.gameObject.AddComponent<Image>();
                hitArea = image;
            }

            var art = CardArtLibrary.ForCard(card.CatalogCardId);
            if (art != null)
            {
                image.sprite = art;
                image.preserveAspect = true;
            }
            else
            {
                image.color = Tint(card.Color);
                AddStandInText(face, card, width);
            }

            if (onClick != null)
            {
                var button = rect.gameObject.AddComponent<Button>();
                button.targetGraphic = hitArea;
                button.onClick.AddListener(onClick);
            }

            if (card.IsSuppressed)
            {
                AddSuppressedShade(rect, boundsWidth);
            }

            if (showValue && (card.ValueIsModified || art == null))
            {
                AddValueChip(rect, card, width, theme);
            }

            return rect;
        }

        /// <summary>The Hurt Feelings card -- its art if present, otherwise a plain dark stand-in.</summary>
        public static RectTransform CreateHurtFeelings(Transform parent, float width, UiTheme theme)
        {
            var height = HeightFor(width);
            var rect = UiFactory.Create("Hurt Feelings", parent);
            rect.sizeDelta = new Vector2(width, height);
            UiFactory.Size(rect.gameObject, width, height);

            var image = rect.gameObject.AddComponent<Image>();
            var art = CardArtLibrary.HurtFeelings();
            if (art != null)
            {
                image.sprite = art;
                image.preserveAspect = true;
            }
            else
            {
                image.color = new Color(0.10f, 0.09f, 0.11f);
                var label = UiFactory.Label(rect, "Hurt\nFeelings", Mathf.Max(12, Mathf.RoundToInt(width * 0.2f)), theme.danger,
                    TextAnchor.MiddleCenter, FontStyle.Bold);
                UiFactory.Stretch(label.rectTransform);
            }

            return rect;
        }

        /// <summary>Colors a stand-in card the way its color reads: white, blue, black, red, green.</summary>
        public static Color Tint(string color)
        {
            switch (color)
            {
                case "white": return new Color(0.91f, 0.90f, 0.84f);
                case "blue": return new Color(0.46f, 0.62f, 0.90f);
                case "black": return new Color(0.28f, 0.26f, 0.32f);
                case "red": return new Color(0.88f, 0.42f, 0.38f);
                case "green": return new Color(0.46f, 0.74f, 0.50f);
                default: return new Color(0.55f, 0.56f, 0.60f);
            }
        }

        private static void AddStandInText(RectTransform rect, BoardCard card, float width)
        {
            var dark = card.Color != "black";
            var ink = dark ? new Color(0.08f, 0.08f, 0.10f) : new Color(0.95f, 0.95f, 0.95f);

            var name = UiFactory.Label(rect, card.Name, Mathf.Max(11, Mathf.RoundToInt(width * 0.115f)), ink,
                TextAnchor.UpperCenter, FontStyle.Bold);
            name.rectTransform.anchorMin = new Vector2(0.04f, 0.78f);
            name.rectTransform.anchorMax = new Vector2(0.96f, 0.98f);
            name.rectTransform.offsetMin = name.rectTransform.offsetMax = Vector2.zero;

            // Too small to read at a glance anyway; the detail view carries the full text.
            if (width >= 120f && !string.IsNullOrEmpty(card.RulesText))
            {
                var rules = UiFactory.Label(rect, card.RulesText, Mathf.Max(10, Mathf.RoundToInt(width * 0.07f)), ink,
                    TextAnchor.UpperLeft);
                rules.rectTransform.anchorMin = new Vector2(0.06f, 0.12f);
                rules.rectTransform.anchorMax = new Vector2(0.94f, 0.74f);
                rules.rectTransform.offsetMin = rules.rectTransform.offsetMax = Vector2.zero;
            }
        }

        private static void AddValueChip(RectTransform rect, BoardCard card, float width, UiTheme theme)
        {
            // Sized to cover the printed value's die in the art's top-right corner.
            var chipWidth = Mathf.Max(30f, width * 0.22f);
            var chipHeight = chipWidth * 0.86f;
            var chip = UiFactory.Create("Value", rect);
            chip.anchorMin = chip.anchorMax = chip.pivot = new Vector2(1f, 1f);
            chip.sizeDelta = new Vector2(chipWidth, chipHeight);
            chip.anchoredPosition = new Vector2(-width * 0.08f, -width * 0.04f);

            // Accent when an effect changed it; a plain dark chip on a stand-in that just prints it.
            var modified = card.ValueIsModified;
            chip.gameObject.AddComponent<Image>().color = modified ? theme.accent : new Color(0f, 0f, 0f, 0.80f);

            var label = UiFactory.Label(chip, card.Value.ToString(), Mathf.RoundToInt(chipHeight * 0.72f),
                modified ? theme.background : Color.white, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Stretch(label.rectTransform);
        }

        private static void AddSuppressedShade(RectTransform rect, float width)
        {
            var shade = UiFactory.Create("Suppressed", rect);
            UiFactory.Stretch(shade);
            shade.gameObject.AddComponent<Image>().color = new Color(0f, 0f, 0f, 0.55f);

            var label = UiFactory.Label(shade, "SUPPRESSED", Mathf.Max(11, Mathf.RoundToInt(width * 0.115f)), Color.white,
                TextAnchor.MiddleCenter, FontStyle.Bold);
            label.horizontalOverflow = HorizontalWrapMode.Overflow;
            UiFactory.Stretch(label.rectTransform);
        }
    }
}
