using UnityEngine;
using UnityEngine.Events;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Builds themed uGUI controls in code, so screens are plain C# that
    /// reviews as a diff rather than hand-assembled scene YAML. Sizes are in
    /// reference-resolution units (1920x1080, see <see cref="CanvasSetup"/>).
    /// Uses the legacy Text/InputField components (no TMP font assets to
    /// import yet); revisit when the Arena-style look is designed.
    /// </summary>
    public static class UiFactory
    {
        public const float ControlHeight = 64f;

        private static Font _font;

        public static Font Font
        {
            get
            {
                if (_font == null)
                {
                    _font = Resources.GetBuiltinResource<Font>("LegacyRuntime.ttf");
                }

                return _font;
            }
        }

        public static RectTransform Create(string name, Transform parent)
        {
            var go = new GameObject(name, typeof(RectTransform));
            go.transform.SetParent(parent, false);
            return (RectTransform)go.transform;
        }

        public static void Stretch(RectTransform rect)
        {
            rect.anchorMin = Vector2.zero;
            rect.anchorMax = Vector2.one;
            rect.offsetMin = Vector2.zero;
            rect.offsetMax = Vector2.zero;
        }

        public static RectTransform Background(Transform parent, Color color)
        {
            var rect = Create("Background", parent);
            Stretch(rect);
            rect.gameObject.AddComponent<Image>().color = color;
            return rect;
        }

        /// <summary>A fixed-width column, centered, growing to fit its children vertically.</summary>
        public static RectTransform CenteredColumn(Transform parent, float width, float spacing)
        {
            var rect = Create("Column", parent);
            rect.anchorMin = rect.anchorMax = rect.pivot = new Vector2(0.5f, 0.5f);
            rect.sizeDelta = new Vector2(width, 0f);

            var layout = rect.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = spacing;
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            rect.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;
            return rect;
        }

        public static HorizontalLayoutGroup Row(Transform parent, string name, float spacing, TextAnchor alignment)
        {
            var rect = Create(name, parent);
            var layout = rect.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.spacing = spacing;
            layout.childAlignment = alignment;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;
            return layout;
        }

        public static LayoutElement Size(GameObject go, float? width = null, float? height = null)
        {
            var element = go.GetComponent<LayoutElement>();
            if (element == null)
            {
                element = go.AddComponent<LayoutElement>();
            }

            if (width.HasValue)
            {
                element.preferredWidth = width.Value;
            }

            if (height.HasValue)
            {
                element.preferredHeight = height.Value;
            }

            return element;
        }

        public static Text Label(
            Transform parent, string text, int size, Color color,
            TextAnchor alignment = TextAnchor.MiddleCenter, FontStyle style = FontStyle.Normal)
        {
            var rect = Create("Label", parent);
            var label = rect.gameObject.AddComponent<Text>();
            label.font = Font;
            label.text = text;
            label.fontSize = size;
            label.fontStyle = style;
            label.color = color;
            label.alignment = alignment;
            label.horizontalOverflow = HorizontalWrapMode.Wrap;
            label.verticalOverflow = VerticalWrapMode.Overflow;
            return label;
        }

        public static Button Button(Transform parent, string text, UiTheme theme, UnityAction onClick, bool primary = true)
        {
            var rect = Create("Button", parent);
            var image = rect.gameObject.AddComponent<Image>();
            image.color = primary ? theme.accent : Raised(theme);

            var button = rect.gameObject.AddComponent<Button>();
            button.targetGraphic = image;
            button.colors = Tints();
            button.onClick.AddListener(onClick);

            var label = Label(rect, text, 30, primary ? theme.background : theme.textPrimary, TextAnchor.MiddleCenter, FontStyle.Bold);
            Stretch(label.rectTransform);

            Size(rect.gameObject, height: ControlHeight);
            return button;
        }

        /// <summary>A text-only button, for secondary actions like "Forgot password?".</summary>
        public static Button Link(Transform parent, string text, UiTheme theme, UnityAction onClick)
        {
            var rect = Create("Link", parent);
            var label = Label(rect, text, 24, theme.accent, TextAnchor.MiddleCenter);
            Stretch(label.rectTransform);

            var button = rect.gameObject.AddComponent<Button>();
            button.targetGraphic = label;
            button.colors = Tints();
            button.onClick.AddListener(onClick);

            Size(rect.gameObject, height: 40f);
            return button;
        }

        public static InputField Input(Transform parent, string placeholder, UiTheme theme, bool password = false)
        {
            var rect = Create("Input", parent);
            var image = rect.gameObject.AddComponent<Image>();
            image.color = Raised(theme);

            var text = Label(rect, string.Empty, 30, theme.textPrimary, TextAnchor.MiddleLeft);
            text.supportRichText = false;
            Inset(text.rectTransform, 16f, 8f);

            var hint = Label(rect, placeholder, 30, theme.textMuted, TextAnchor.MiddleLeft, FontStyle.Italic);
            Inset(hint.rectTransform, 16f, 8f);

            var field = rect.gameObject.AddComponent<InputField>();
            field.targetGraphic = image;
            field.textComponent = text;
            field.placeholder = hint;
            field.lineType = InputField.LineType.SingleLine;
            field.contentType = password ? InputField.ContentType.Password : InputField.ContentType.Standard;
            field.caretColor = theme.textPrimary;
            field.selectionColor = new Color(theme.accent.r, theme.accent.g, theme.accent.b, 0.4f);

            Size(rect.gameObject, height: ControlHeight);
            return field;
        }

        public static Toggle Toggle(Transform parent, string label, UiTheme theme, bool isOn)
        {
            var row = Row(parent, "Toggle", 16f, TextAnchor.MiddleLeft);

            var box = Create("Box", row.transform);
            var boxImage = box.gameObject.AddComponent<Image>();
            boxImage.color = Raised(theme);
            Size(box.gameObject, 40f, 40f);

            var check = Create("Check", box);
            Stretch(check);
            check.offsetMin = new Vector2(8f, 8f);
            check.offsetMax = new Vector2(-8f, -8f);
            var checkImage = check.gameObject.AddComponent<Image>();
            checkImage.color = theme.accent;

            var text = Label(row.transform, label, 26, theme.textPrimary, TextAnchor.MiddleLeft);
            Size(text.gameObject, height: 40f);

            var toggle = row.gameObject.AddComponent<Toggle>();
            toggle.targetGraphic = boxImage;
            toggle.graphic = checkImage;
            toggle.isOn = isOn;

            Size(row.gameObject, height: 40f);
            return toggle;
        }

        private static void Inset(RectTransform rect, float horizontal, float vertical)
        {
            Stretch(rect);
            rect.offsetMin = new Vector2(horizontal, vertical);
            rect.offsetMax = new Vector2(-horizontal, -vertical);
        }

        private static Color Raised(UiTheme theme) => Color.Lerp(theme.panel, Color.white, 0.10f);

        private static ColorBlock Tints()
        {
            var colors = ColorBlock.defaultColorBlock;
            colors.normalColor = Color.white;
            colors.highlightedColor = new Color(0.92f, 0.92f, 0.92f);
            colors.pressedColor = new Color(0.75f, 0.75f, 0.75f);
            colors.selectedColor = Color.white;
            colors.disabledColor = new Color(1f, 1f, 1f, 0.45f);
            return colors;
        }
    }
}
