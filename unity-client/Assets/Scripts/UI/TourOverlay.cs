using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Draws the welcome tour over the screen it is about: the screen dimmed, with a frame around the button a step is
    /// about, and a card with what it says and Back / Next / Skip. Takes every tap while it is up. Back or Escape skips.
    /// A step of the sample game puts the table (<see cref="SampleTable"/>) at the left instead, with frames round the things
    /// it is talking about, and the card at the right.
    /// </summary>
    public sealed class TourOverlay
    {
        private const float Pad = 14f;          // room around the highlighted button
        private const float FrameThickness = 6f;
        private const float SideWidth = 500f;   // the card beside a highlighted button, at most
        private const float MinSideWidth = 380f;
        private const float CenterWidth = 1000f;
        private const float SampleCardWidth = 620f;
        private const float Margin = 40f;
        private const float DiagramWidth = 640f;
        private const float DiagramHeight = 330f;
        private const int MaxFrames = 6;        // boxes at once: the sample game points at up to this many things

        private readonly TourFlow _flow;
        private readonly UiTheme _theme;
        private readonly Func<string, RectTransform> _targets;
        private readonly RectTransform _root;
        private readonly Image[] _dim = new Image[4];
        private readonly Image[] _frame = new Image[MaxFrames * 4];
        private readonly SampleTable _table;
        private readonly RectTransform _card;
        private readonly Text _counter;
        private readonly Text _title;
        private readonly Text _body;
        private readonly Text _notes;
        private readonly GameObject _diagram;
        private readonly RectTransform _progressFill;
        private readonly Button _back;
        private readonly Button _next;
        private readonly Text _nextLabel;
        private readonly Button _skipSection;
        private readonly Text _skipSectionLabel;
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

            // Above the dimming, under the frames and the card.
            _table = new SampleTable(_root, theme);

            for (var i = 0; i < _frame.Length; i++)
            {
                _frame[i] = Piece("Frame " + i, theme.accent);
                _frame[i].gameObject.SetActive(false);
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
            _title = UiFactory.Label(_card, string.Empty, 38, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            // As tall as its words need: a long title wraps in the narrow card beside a row in Settings.
            _title.gameObject.AddComponent<LayoutElement>().minHeight = 52f;

            // In a row of its own, so it keeps its size and sits in the middle rather than stretching to the card's width.
            var diagramRow = UiFactory.Row(_card, "Table diagram row", 0f, TextAnchor.MiddleCenter);
            diagramRow.childForceExpandWidth = false;
            UiFactory.Size(diagramRow.gameObject, height: DiagramHeight);
            BuildTableDiagram(diagramRow.transform, theme);
            _diagram = diagramRow.gameObject;
            _diagram.SetActive(false);
            _body = UiFactory.Label(_card, string.Empty, 28, theme.textPrimary, TextAnchor.UpperLeft);

            // The rules a step brings up, apart from what it says.
            _notes = UiFactory.Label(_card, string.Empty, 26, theme.accent, TextAnchor.UpperLeft, FontStyle.Bold);

            var bar = UiFactory.Create("Progress", _card);
            UiFactory.Size(bar.gameObject, height: 8f);
            var barBack = bar.gameObject.AddComponent<Image>();
            barBack.color = theme.faint;
            barBack.raycastTarget = false;
            _progressFill = UiFactory.Create("Fill", bar);
            _progressFill.anchorMin = Vector2.zero;
            _progressFill.anchorMax = new Vector2(0f, 1f);
            _progressFill.offsetMin = _progressFill.offsetMax = Vector2.zero;
            var fill = _progressFill.gameObject.AddComponent<Image>();
            fill.color = theme.accent;
            fill.raycastTarget = false;

            var buttons = UiFactory.Row(_card, "Buttons", 16f, TextAnchor.MiddleCenter);
            UiFactory.Size(buttons.gameObject, height: UiFactory.ControlHeight);
            _back = UiFactory.Button(buttons.transform, "Back", theme, () => _flow.Back(), primary: false);
            _back.gameObject.name = "Tour back";
            UiFactory.Size(_back.gameObject, 200f);
            _next = UiFactory.Button(buttons.transform, "Next", theme, () => _flow.Next());
            _next.gameObject.name = "Tour next";
            UiFactory.Size(_next.gameObject, 240f);
            _nextLabel = _next.GetComponentInChildren<Text>();

            // A second way on, where a step has one: past the whole sample game.
            _skipSection = UiFactory.Button(_card, "Skip", theme, () => _flow.JumpTo(_flow.Current?.SkipTo), primary: false);
            _skipSection.gameObject.name = "Tour skip section";
            _skipSectionLabel = _skipSection.GetComponentInChildren<Text>();

            _skip = UiFactory.Link(_card, "Skip the tour", theme, () => _flow.Skip());
            _skip.gameObject.name = "Tour skip";
            UiFactory.Size(_skip.gameObject, height: 44f);

            _root.gameObject.SetActive(false);
            _flow.Changed += Refresh;
        }

        public bool IsOpen => _root.gameObject.activeSelf;

        public string TitleText => _title.text;

        public string BodyText => _body.text;

        public string NotesText => _notes.gameObject.activeSelf ? _notes.text : string.Empty;

        public string CounterText => _counter.text;

        public bool ShowsDiagram => _diagram.activeSelf;

        public bool CanGoBack => _back.gameObject.activeSelf;

        public string NextLabel => _nextLabel.text;

        public bool OffersSkipSection => _skipSection.gameObject.activeSelf;

        /// <summary>The table of a sample game step.</summary>
        public SampleTable Table => _table;

        /// <summary>The frames round what a step points at, as one box in world space (null when it points at nothing).</summary>
        public Rect? HighlightRect
        {
            get
            {
                var any = false;
                var min = new Vector2(float.MaxValue, float.MaxValue);
                var max = new Vector2(float.MinValue, float.MinValue);
                var corners = new Vector3[4];
                foreach (var piece in _frame)
                {
                    if (!piece.gameObject.activeSelf)
                    {
                        continue;
                    }

                    any = true;
                    piece.rectTransform.GetWorldCorners(corners);
                    foreach (var corner in corners)
                    {
                        min = Vector2.Min(min, corner);
                        max = Vector2.Max(max, corner);
                    }
                }

                return any ? Rect.MinMaxRect(min.x, min.y, max.x, max.y) : (Rect?)null;
            }
        }

        /// <summary>How many things are framed at the moment.</summary>
        public int FramedCount => _frame.Count(f => f.gameObject.activeSelf) / 4;

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
                _table.Hide();
                return;
            }

            var step = _flow.Current;
            // Over everything, the update box and the menu included.
            _root.SetAsLastSibling();
            _root.gameObject.SetActive(true);

            var sample = step.Sample != null;
            _counter.text = sample
                ? $"Sample game: move {SampleGame.NumberOf(step.Sample)} of {SampleGame.Steps.Count}"
                : $"Step {_flow.Index + 1} of {_flow.Count}";
            _title.text = step.Title;
            _body.text = step.Body;
            _body.fontSize = sample ? 26 : 28;
            _notes.text = string.Join("\n", step.Notes.Select(n => "Rule: " + n));
            _notes.gameObject.SetActive(step.Notes.Count > 0);
            _diagram.SetActive(step.TableDiagram);
            _back.gameObject.SetActive(!_flow.IsFirst);
            _nextLabel.text = step.NextLabel ?? (_flow.IsLast ? "Done" : _flow.IsFirst ? "Start the tour" : "Next");
            UiFactory.Size(_next.gameObject, step.NextLabel != null || _flow.IsFirst ? 330f : 240f);
            _skipSection.gameObject.SetActive(step.SkipTo != null);
            if (step.SkipTo != null)
            {
                _skipSectionLabel.text = step.SkipLabel ?? "Skip";
            }

            _skip.gameObject.SetActive(!_flow.IsLast);
            _progressFill.anchorMax = new Vector2((_flow.Index + 1) / (float)_flow.Count, 1f);

            if (sample)
            {
                _table.Show(step.Sample);
            }
            else
            {
                _table.Hide();
            }

            Place();
        }

        // The dimmed screen with a hole where the button is, a frame around the hole, and the card beside it (or in the
        // middle when the step is about the whole app). A sample game step is the table and the card side by side.
        private void Place()
        {
            var step = _flow.Current;
            if (step == null)
            {
                return;
            }

            var area = _root.rect;
            var half = new Vector2(area.width * 0.5f, area.height * 0.5f);
            if (step.Sample != null)
            {
                PlaceSample(step, area, half);
                return;
            }

            var target = step.Target != null ? _targets?.Invoke(step.Target) : null;
            var hasTarget = target != null && target.gameObject.activeInHierarchy;

            if (!hasTarget)
            {
                Set(_dim[0], -half.x, -half.y, half.x, half.y);
                for (var i = 1; i < _dim.Length; i++)
                {
                    _dim[i].gameObject.SetActive(false);
                }

                HideFramesFrom(0);
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
            SetFrame(0, x0, y0, x1, y1);
            HideFramesFrom(1);

            // The card goes on whichever side of the button has more room, at the edge of the screen, and is as wide as
            // that room allows (a wide row in Settings leaves less at the sides than a button on the main menu does).
            var roomLeft = x0 + half.x;
            var roomRight = half.x - x1;
            var onRight = roomRight >= roomLeft;
            var cardWidth = Mathf.Clamp(Mathf.Max(roomLeft, roomRight) - 60f, MinSideWidth, SideWidth);
            _card.sizeDelta = new Vector2(cardWidth, 0f);
            var cardX = onRight ? half.x - 40f - cardWidth * 0.5f : -(half.x - 40f - cardWidth * 0.5f);
            var limit = Mathf.Max(0f, half.y - 340f);
            _card.anchoredPosition = new Vector2(cardX, Mathf.Clamp((y0 + y1) * 0.5f, -limit, limit));
        }

        // The table at the left, scaled down if the window is narrow; the card at the right; a frame round each thing the step
        // is about.
        private void PlaceSample(TourStep step, Rect area, Vector2 half)
        {
            Set(_dim[0], -half.x, -half.y, half.x, half.y);
            for (var i = 1; i < _dim.Length; i++)
            {
                _dim[i].gameObject.SetActive(false);
            }

            _card.sizeDelta = new Vector2(SampleCardWidth, 0f);
            _card.anchoredPosition = new Vector2(half.x - Margin - SampleCardWidth * 0.5f, 0f);

            var availableWidth = Mathf.Max(400f, area.width - Margin - SampleCardWidth - Margin - Margin);
            var availableHeight = area.height - 2f * Margin;
            var scale = Mathf.Min(1f, availableWidth / SampleTable.Width, availableHeight / SampleTable.Height);
            var table = _table.Root;
            table.localScale = new Vector3(scale, scale, 1f);
            table.anchoredPosition = new Vector2(-half.x + Margin + SampleTable.Width * scale * 0.5f, 0f);

            var boxes = 0;
            var corners = new Vector3[4];
            foreach (var key in step.Sample.Focus)
            {
                if (boxes >= MaxFrames || !_table.Targets.TryGetValue(key, out var target) || target == null)
                {
                    continue;
                }

                target.GetWorldCorners(corners);
                var low = (Vector2)_root.InverseTransformPoint(corners[0]);
                var high = (Vector2)_root.InverseTransformPoint(corners[2]);
                // Not laid out yet (the table was just built): nothing to frame until it is.
                if (high.x - low.x < 4f && high.y - low.y < 4f)
                {
                    continue;
                }

                SetFrame(boxes++, low.x - 6f, low.y - 6f, high.x + 6f, high.y + 6f);
            }

            HideFramesFrom(boxes);
        }

        // Four thin bars round a box, in the overlay's centered coordinates.
        private void SetFrame(int box, float x0, float y0, float x1, float y1)
        {
            var first = box * 4;
            Set(_frame[first], x0 - FrameThickness, y1, x1 + FrameThickness, y1 + FrameThickness);        // top
            Set(_frame[first + 1], x0 - FrameThickness, y0 - FrameThickness, x1 + FrameThickness, y0);    // bottom
            Set(_frame[first + 2], x0 - FrameThickness, y0, x0, y1);                                       // left
            Set(_frame[first + 3], x1, y0, x1 + FrameThickness, y1);                                       // right
        }

        private void HideFramesFrom(int box)
        {
            for (var i = box * 4; i < _frame.Length; i++)
            {
                _frame[i].gameObject.SetActive(false);
            }
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
