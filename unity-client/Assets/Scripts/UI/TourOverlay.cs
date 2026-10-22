using System;
using System.Collections.Generic;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Draws the welcome tour over the screen it is about: the screen dimmed, with a frame around the button a step is
    /// about, and a card with what it says and Back / Next / Skip. Takes every tap while it is up. Back or Escape skips.
    /// </summary>
    public sealed class TourOverlay
    {
        private const float Pad = 14f;          // room around the highlighted button
        private const float FrameThickness = 6f;
        private const float SideWidth = 500f;   // the card beside a highlighted button
        private const float CenterWidth = 1000f;
        private const float DiagramWidth = 640f;
        private const float DiagramHeight = 330f;

        private readonly TourFlow _flow;
        private readonly UiTheme _theme;
        private readonly Func<string, RectTransform> _targets;
        private readonly RectTransform _root;
        private readonly Image[] _dim = new Image[4];
        private readonly Image[] _frame = new Image[4];
        private readonly RectTransform _card;
        private readonly Text _counter;
        private readonly Text _title;
        private readonly Text _body;
        private readonly GameObject _diagram;
        private readonly List<Image> _dots = new List<Image>();
        private readonly Button _back;
        private readonly Button _next;
        private readonly Text _nextLabel;
        private readonly Button _skip;

        /// <param name="targets">Finds the button a step points at (by the step's Target), or null when it isn't on screen.</param>
        public TourOverlay(Transform parent, UiTheme theme, TourFlow flow, Func<string, RectTransform> targets)
        {
            _theme = theme;
            _flow = flow;
            _targets = targets;

            _root = UiFactory.Create("Tour overlay", parent);
            UiFactory.Stretch(_root);
            // Nothing behind it can be tapped while the tour is up.
            var catcher = _root.gameObject.AddComponent<Image>();
            catcher.color = new Color(0f, 0f, 0f, 0f);
            catcher.raycastTarget = true;

            for (var i = 0; i < _dim.Length; i++)
            {
                _dim[i] = Piece("Dim " + i, new Color(0f, 0f, 0f, 0.74f));
            }

            for (var i = 0; i < _frame.Length; i++)
            {
                _frame[i] = Piece("Frame " + i, theme.accent);
            }

            _card = UiFactory.Create("Tour card", _root);
            _card.anchorMin = _card.anchorMax = _card.pivot = new Vector2(0.5f, 0.5f);
            _card.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = _card.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.padding = new RectOffset(36, 36, 28, 28);
            layout.spacing = 14f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;
            _card.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;

            _counter = UiFactory.Label(_card, string.Empty, 22, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Size(_counter.gameObject, height: 28f);
            _title = UiFactory.Label(_card, string.Empty, 42, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Size(_title.gameObject, height: 56f);

            // In a row of its own, so it keeps its size and sits in the middle rather than stretching to the card's width.
            var diagramRow = UiFactory.Row(_card, "Table diagram row", 0f, TextAnchor.MiddleCenter);
            diagramRow.childForceExpandWidth = false;
            UiFactory.Size(diagramRow.gameObject, height: DiagramHeight);
            BuildTableDiagram(diagramRow.transform, theme);
            _diagram = diagramRow.gameObject;
            _diagram.SetActive(false);
            _body = UiFactory.Label(_card, string.Empty, 28, theme.textPrimary, TextAnchor.UpperLeft);

            var dots = UiFactory.Row(_card, "Progress", 8f, TextAnchor.MiddleLeft);
            dots.childForceExpandWidth = false;
            UiFactory.Size(dots.gameObject, height: 20f);
            for (var i = 0; i < _flow.Count; i++)
            {
                var dot = UiFactory.Create("Dot " + i, dots.transform);
                UiFactory.Size(dot.gameObject, 14f, 14f);
                var image = dot.gameObject.AddComponent<Image>();
                image.raycastTarget = false;
                _dots.Add(image);
            }

            var buttons = UiFactory.Row(_card, "Buttons", 16f, TextAnchor.MiddleCenter);
            UiFactory.Size(buttons.gameObject, height: UiFactory.ControlHeight);
            _back = UiFactory.Button(buttons.transform, "Back", theme, () => _flow.Back(), primary: false);
            _back.gameObject.name = "Tour back";
            UiFactory.Size(_back.gameObject, 200f);
            _next = UiFactory.Button(buttons.transform, "Next", theme, () => _flow.Next());
            _next.gameObject.name = "Tour next";
            UiFactory.Size(_next.gameObject, 240f);
            _nextLabel = _next.GetComponentInChildren<Text>();

            _skip = UiFactory.Link(_card, "Skip the tour", theme, () => _flow.Skip());
            _skip.gameObject.name = "Tour skip";
            UiFactory.Size(_skip.gameObject, height: 44f);

            _root.gameObject.SetActive(false);
            _flow.Changed += Refresh;
        }

        public bool IsOpen => _root.gameObject.activeSelf;

        public string TitleText => _title.text;

        public string BodyText => _body.text;

        public string CounterText => _counter.text;

        public bool ShowsDiagram => _diagram.activeSelf;

        public bool CanGoBack => _back.gameObject.activeSelf;

        public string NextLabel => _nextLabel.text;

        /// <summary>The frame around the highlighted button, in world space; null when the step points at nothing.</summary>
        public Rect? HighlightRect
        {
            get
            {
                if (!_frame[0].gameObject.activeSelf)
                {
                    return null;
                }

                var corners = new Vector3[4];
                _frame[0].rectTransform.GetWorldCorners(corners);
                var topLeft = corners[1];
                _frame[3].rectTransform.GetWorldCorners(corners);
                var bottomRight = corners[3];
                return Rect.MinMaxRect(topLeft.x, bottomRight.y, bottomRight.x, topLeft.y);
            }
        }

        /// <summary>The card, so a test can see it sits clear of the highlighted button.</summary>
        public RectTransform Card => _card;

        /// <summary>Back / Escape: skips the tour. True if it was up.</summary>
        public bool Dismiss()
        {
            if (!IsOpen)
            {
                return false;
            }

            _flow.Skip();
            return true;
        }

        /// <summary>Called once a frame: the buttons it points at can move (the window is resized).</summary>
        public void Tick()
        {
            if (IsOpen)
            {
                Place();
            }
        }

        private Image Piece(string name, Color color)
        {
            var rect = UiFactory.Create(name, _root);
            rect.anchorMin = rect.anchorMax = rect.pivot = new Vector2(0.5f, 0.5f);
            var image = rect.gameObject.AddComponent<Image>();
            image.color = color;
            image.raycastTarget = false;
            return image;
        }

        private void Refresh()
        {
            if (!_flow.IsActive)
            {
                _root.gameObject.SetActive(false);
                return;
            }

            var step = _flow.Current;
            // Over everything, the update box and the menu included.
            _root.SetAsLastSibling();
            _root.gameObject.SetActive(true);

            _counter.text = $"Step {_flow.Index + 1} of {_flow.Count}";
            _title.text = step.Title;
            _body.text = step.Body;
            _diagram.SetActive(step.TableDiagram);
            _back.gameObject.SetActive(!_flow.IsFirst);
            _nextLabel.text = _flow.IsLast ? "Done" : _flow.IsFirst ? "Start the tour" : "Next";
            UiFactory.Size(_next.gameObject, _flow.IsFirst ? 300f : 240f);
            _skip.gameObject.SetActive(!_flow.IsLast);
            for (var i = 0; i < _dots.Count; i++)
            {
                _dots[i].color = i == _flow.Index ? _theme.accent : _theme.faint;
            }

            Place();
        }

        // The dimmed screen with a hole where the button is, a frame around the hole, and the card beside it (or in the
        // middle when the step is about the whole app).
        private void Place()
        {
            var step = _flow.Current;
            if (step == null)
            {
                return;
            }

            var area = _root.rect;
            var half = new Vector2(area.width * 0.5f, area.height * 0.5f);
            var target = step.Target != null ? _targets?.Invoke(step.Target) : null;
            var hasTarget = target != null && target.gameObject.activeInHierarchy;

            if (!hasTarget)
            {
                Set(_dim[0], -half.x, -half.y, half.x, half.y);
                for (var i = 1; i < _dim.Length; i++)
                {
                    _dim[i].gameObject.SetActive(false);
                }

                foreach (var frame in _frame)
                {
                    frame.gameObject.SetActive(false);
                }

                var width = step.TableDiagram ? CenterWidth + 100f : CenterWidth;
                _card.sizeDelta = new Vector2(width, 0f);
                _card.anchoredPosition = Vector2.zero;
                return;
            }

            // The button's box in the overlay's own coordinates.
            var corners = new Vector3[4];
            target.GetWorldCorners(corners);
            var low = (Vector2)_root.InverseTransformPoint(corners[0]);
            var high = (Vector2)_root.InverseTransformPoint(corners[2]);
            var x0 = Mathf.Max(-half.x, low.x - Pad);
            var y0 = Mathf.Max(-half.y, low.y - Pad);
            var x1 = Mathf.Min(half.x, high.x + Pad);
            var y1 = Mathf.Min(half.y, high.y + Pad);

            Set(_dim[0], -half.x, -half.y, half.x, y0);          // below
            Set(_dim[1], -half.x, y1, half.x, half.y);           // above
            Set(_dim[2], -half.x, y0, x0, y1);                   // left
            Set(_dim[3], x1, y0, half.x, y1);                    // right
            Set(_frame[0], x0 - FrameThickness, y1, x1 + FrameThickness, y1 + FrameThickness);   // top
            Set(_frame[1], x0 - FrameThickness, y0 - FrameThickness, x1 + FrameThickness, y0);   // bottom
            Set(_frame[2], x0 - FrameThickness, y0, x0, y1);                                      // left
            Set(_frame[3], x1, y0, x1 + FrameThickness, y1);                                      // right

            // The card goes on whichever side of the screen the button is not on.
            _card.sizeDelta = new Vector2(SideWidth, 0f);
            var middleX = (x0 + x1) * 0.5f;
            var cardX = middleX <= 0f ? half.x - 40f - SideWidth * 0.5f : -(half.x - 40f - SideWidth * 0.5f);
            var limit = Mathf.Max(0f, half.y - 330f);
            _card.anchoredPosition = new Vector2(cardX, Mathf.Clamp((y0 + y1) * 0.5f, -limit, limit));
        }

        // A rectangle between two corners, in the overlay's centered coordinates.
        private static void Set(Image image, float xMin, float yMin, float xMax, float yMax)
        {
            image.gameObject.SetActive(true);
            var rect = image.rectTransform;
            rect.sizeDelta = new Vector2(Mathf.Max(0f, xMax - xMin), Mathf.Max(0f, yMax - yMin));
            rect.anchoredPosition = new Vector2((xMin + xMax) * 0.5f, (yMin + yMax) * 0.5f);
        }

        // --- the picture of the table --------------------------------------------------------------

        // A small drawing of the board, with its parts named: not the real thing, just enough to know where to look.
        private static RectTransform BuildTableDiagram(Transform parent, UiTheme theme)
        {
            var holder = UiFactory.Create("Table diagram", parent);
            UiFactory.Size(holder.gameObject, DiagramWidth, DiagramHeight);

            var table = UiFactory.Create("Table", holder);
            UiFactory.Stretch(table);
            table.gameObject.AddComponent<Image>().color = theme.background;

            // The menu, top left.
            Box(table, 16f, 290f, 40f, 30f, theme.panel);
            for (var i = 0; i < 3; i++)
            {
                Box(table, 22f, 296f + i * 9f, 28f, 4f, theme.textPrimary);
            }

            Tag(table, "Menu", 62f, 290f, 100f, 30f, theme, TextAnchor.MiddleLeft);

            // An opponent across the table: their hand along the top, and their scoreboard.
            for (var i = 0; i < 5; i++)
            {
                Box(table, 250f + i * 34f, 290f, 28f, 40f, theme.accent);
            }

            Box(table, 450f, 282f, 160f, 40f, theme.panel);
            Tag(table, "Opponent  3", 450f, 282f, 160f, 40f, theme, TextAnchor.MiddleCenter);
            Tag(table, "Their hand", 250f, 252f, 170f, 26f, theme, TextAnchor.MiddleLeft);

            // The table, and what is on it.
            Tag(table, "The table: moods in play", 180f, 196f, 280f, 28f, theme, TextAnchor.MiddleCenter);
            for (var i = 0; i < 3; i++)
            {
                Box(table, 258f + i * 46f, 128f, 38f, 56f, theme.raised);
            }

            Box(table, 520f, 138f, 100f, 36f, theme.accent);
            var pass = Tag(table, "Pass", 520f, 138f, 100f, 36f, theme, TextAnchor.MiddleCenter);
            pass.color = theme.onAccent;

            // You, along the bottom: discard, your scoreboard, your hand, the deck.
            Box(table, 12f, 18f, 40f, 56f, theme.raised);
            Tag(table, "Discard", 4f, 78f, 70f, 24f, theme, TextAnchor.MiddleCenter);
            Box(table, 62f, 18f, 130f, 44f, theme.panel);
            Tag(table, "You  2", 62f, 18f, 130f, 44f, theme, TextAnchor.MiddleCenter);
            for (var i = 0; i < 4; i++)
            {
                Box(table, 240f + i * 48f, 0f, 42f, 60f, theme.raised);
            }

            Tag(table, "Your hand: tap a card to play it", 200f, 66f, 300f, 26f, theme, TextAnchor.MiddleLeft);
            Box(table, 588f, 18f, 40f, 56f, theme.accent);
            Tag(table, "Deck", 570f, 78f, 70f, 24f, theme, TextAnchor.MiddleCenter);

            return holder;
        }

        // Boxes and labels are placed from the lower left of the picture.
        private static Image Box(RectTransform parent, float x, float y, float width, float height, Color color)
        {
            var rect = UiFactory.Create("Box", parent);
            rect.anchorMin = rect.anchorMax = rect.pivot = Vector2.zero;
            rect.sizeDelta = new Vector2(width, height);
            rect.anchoredPosition = new Vector2(x, y);
            var image = rect.gameObject.AddComponent<Image>();
            image.color = color;
            image.raycastTarget = false;
            return image;
        }

        private static Text Tag(RectTransform parent, string text, float x, float y, float width, float height, UiTheme theme, TextAnchor alignment)
        {
            var label = UiFactory.Label(parent, text, 20, theme.textPrimary, alignment);
            label.raycastTarget = false;
            label.horizontalOverflow = HorizontalWrapMode.Overflow;
            var rect = label.rectTransform;
            rect.anchorMin = rect.anchorMax = rect.pivot = Vector2.zero;
            rect.sizeDelta = new Vector2(width, height);
            rect.anchoredPosition = new Vector2(x, y);
            return label;
        }
    }
}
