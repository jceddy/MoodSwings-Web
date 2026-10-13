using System.Collections.Generic;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Draws the effects moods in play have on one another: a glowing curve from the mood the effect comes from to the one
    /// it acts on, with soft dots streaming along it in that direction. Laid over the table, takes no clicks, and follows
    /// the cards as the table is rebuilt.
    /// </summary>
    public sealed class EffectLinksLayer : MaskableGraphic
    {
        private const float DotSpeed = 0.32f;     // trips along the line per second
        private const float FadeInSeconds = 0.7f;
        private const int Segments = 28;

        private struct Entry
        {
            public EffectLinkKind Kind;
            public RectTransform Source;
            public RectTransform Target;
            public float Since;
        }

        private readonly List<Entry> _entries = new List<Entry>();
        private readonly Dictionary<EffectLink, float> _firstSeen = new Dictionary<EffectLink, float>();

        public override Texture mainTexture => EffectArt.Dot();


        /// <summary>How many lines are being drawn.</summary>
        public int LineCount => _entries.Count;

        /// <summary>How many vertices the last redraw produced (nothing is drawn while there are none).</summary>
        public int VertexCount { get; private set; }


        public static EffectLinksLayer Create(Transform parent)
        {
            var rect = UiFactory.Create("Effect links", parent);
            UiFactory.Stretch(rect);
            rect.gameObject.AddComponent<CanvasRenderer>();
            var layer = rect.gameObject.AddComponent<EffectLinksLayer>();
            layer.raycastTarget = false;
            return layer;
        }

        /// <summary>
        /// Replaces the lines. A line that was already there keeps its age (so it doesn't fade in again every time the
        /// table is redrawn); one whose cards aren't on the table is left out.
        /// </summary>
        public void SetLinks(IEnumerable<EffectLink> links, IReadOnlyDictionary<int, RectTransform> cards)
        {
            var now = Time.unscaledTime;
            var seen = new HashSet<EffectLink>();
            _entries.Clear();
            foreach (var link in links)
            {
                if (!cards.TryGetValue(link.SourceCardId, out var source) || !cards.TryGetValue(link.TargetCardId, out var target)
                    || source == null || target == null)
                {
                    continue;
                }

                if (!_firstSeen.TryGetValue(link, out var since))
                {
                    since = now;
                    _firstSeen[link] = since;
                }

                seen.Add(link);
                _entries.Add(new Entry { Kind = link.Kind, Source = source, Target = target, Since = since });
            }

            foreach (var gone in new List<EffectLink>(_firstSeen.Keys))
            {
                if (!seen.Contains(gone))
                {
                    _firstSeen.Remove(gone);
                }
            }

            SetVerticesDirty();
        }

        public void Clear()
        {
            _entries.Clear();
            _firstSeen.Clear();
            SetVerticesDirty();
        }

        private void Update()
        {
            if (_entries.Count > 0)
            {
                SetVerticesDirty();
            }
        }

        protected override void OnPopulateMesh(VertexHelper vh)
        {
            vh.Clear();
            var time = Time.unscaledTime;
            foreach (var entry in _entries)
            {
                if (entry.Source == null || entry.Target == null || !entry.Source.gameObject.activeInHierarchy || !entry.Target.gameObject.activeInHierarchy)
                {
                    continue;
                }

                var fade = Mathf.Clamp01((time - entry.Since) / FadeInSeconds);
                if (fade <= 0f)
                {
                    continue;
                }

                DrawLink(vh, entry, EffectArt.LinkColor(entry.Kind), fade, time);
            }

            VertexCount = vh.currentVertCount;
        }

        private void DrawLink(VertexHelper vh, Entry entry, Color color, float fade, float time)
        {
            var from = Local(entry.Source);
            var to = Local(entry.Target);
            var span = to - from;
            if (span.sqrMagnitude < 1f)
            {
                return;
            }

            // The curve bows upward (or away from the table's middle when they sit level), so lines that cross over
            // one another's cards read as separate lines rather than one straight bar.
            var normal = new Vector2(-span.y, span.x).normalized;
            if (normal.y < 0f)
            {
                normal = -normal;
            }

            var control = (from + to) * 0.5f + normal * span.magnitude * 0.22f;

            // From the edge of the source card to the edge of the target, not from middle to middle.
            var start = EdgePoint(entry.Source, from, control - from);
            var end = EdgePoint(entry.Target, to, control - to);

            var points = new Vector2[Segments + 1];
            var length = 0f;
            for (var i = 0; i <= Segments; i++)
            {
                var t = i / (float)Segments;
                points[i] = Bezier(start, control, end, t);
                if (i > 0)
                {
                    length += Vector2.Distance(points[i - 1], points[i]);
                }
            }

            // A wide faint band under a narrower one and a bright thin core: a glow.
            Strip(vh, points, 22f, WithAlpha(color, 0.14f * fade));
            Strip(vh, points, 10f, WithAlpha(color, 0.32f * fade));
            Strip(vh, points, 4f, WithAlpha(color, 1.00f * fade));

            // Dots streaming from the source to the target, each with a short fading tail: a colored halo with a bright
            // white heart, which shows on a light page and on a dark one.
            var dots = Mathf.Clamp(Mathf.RoundToInt(length / 110f), 2, 6);
            for (var i = 0; i < dots; i++)
            {
                var head = Mathf.Repeat(time * DotSpeed + i / (float)dots, 1f);
                for (var tail = 0; tail < 5; tail++)
                {
                    var t = head - tail * 0.016f;
                    if (t < 0f)
                    {
                        continue;
                    }

                    var envelope = Mathf.Sin(Mathf.Clamp01(t) * Mathf.PI); // fades in leaving the source, out arriving
                    var at = Bezier(start, control, end, t);
                    var strength = (tail == 0 ? 1f : 0.5f / tail) * envelope * fade;
                    Quad(vh, at, tail == 0 ? 34f : 24f - tail * 3f, WithAlpha(color, 0.75f * strength));
                    if (tail == 0)
                    {
                        Quad(vh, at, 15f, WithAlpha(Color.white, 0.95f * strength));
                    }
                }
            }
        }

        private Vector2 Local(RectTransform card) =>
            rectTransform.InverseTransformPoint(card.TransformPoint(card.rect.center));

        // Where a ray out of the middle of a card in this direction leaves the card's rectangle.
        private Vector2 EdgePoint(RectTransform card, Vector2 center, Vector2 direction)
        {
            direction.Normalize();
            var corner = rectTransform.InverseTransformPoint(card.TransformPoint(new Vector3(card.rect.xMax, card.rect.yMax)));
            var half = new Vector2(Mathf.Abs(corner.x - center.x), Mathf.Abs(corner.y - center.y));
            var scale = Mathf.Min(
                Mathf.Abs(direction.x) < 0.0001f ? float.MaxValue : half.x / Mathf.Abs(direction.x),
                Mathf.Abs(direction.y) < 0.0001f ? float.MaxValue : half.y / Mathf.Abs(direction.y));
            return center + direction * scale;
        }

        private static Vector2 Bezier(Vector2 a, Vector2 b, Vector2 c, float t)
        {
            var u = 1f - t;
            return u * u * a + 2f * u * t * b + t * t * c;
        }

        private static Color WithAlpha(Color color, float alpha) => new Color(color.r, color.g, color.b, Mathf.Clamp01(alpha));

        // A ribbon along the points; it samples the middle of the dot texture, which is fully opaque.
        private static void Strip(VertexHelper vh, Vector2[] points, float width, Color color)
        {
            var uv = new Vector2(0.5f, 0.5f);
            var first = vh.currentVertCount;
            for (var i = 0; i < points.Length; i++)
            {
                var along = points[Mathf.Min(i + 1, points.Length - 1)] - points[Mathf.Max(i - 1, 0)];
                var side = new Vector2(-along.y, along.x).normalized * (width * 0.5f);
                // Thinner towards the ends so the line seems to grow out of the card and into the next.
                var taper = Mathf.Sin(Mathf.Clamp01(i / (float)(points.Length - 1)) * Mathf.PI);
                side *= Mathf.Lerp(0.35f, 1f, taper);
                vh.AddVert(points[i] + side, color, uv);
                vh.AddVert(points[i] - side, color, uv);
            }

            for (var i = 0; i < points.Length - 1; i++)
            {
                var a = first + i * 2;
                vh.AddTriangle(a, a + 1, a + 2);
                vh.AddTriangle(a + 1, a + 3, a + 2);
            }
        }

        private static void Quad(VertexHelper vh, Vector2 center, float size, Color color)
        {
            var half = size * 0.5f;
            var first = vh.currentVertCount;
            vh.AddVert(center + new Vector2(-half, -half), color, new Vector2(0f, 0f));
            vh.AddVert(center + new Vector2(-half, half), color, new Vector2(0f, 1f));
            vh.AddVert(center + new Vector2(half, half), color, new Vector2(1f, 1f));
            vh.AddVert(center + new Vector2(half, -half), color, new Vector2(1f, 0f));
            vh.AddTriangle(first, first + 1, first + 2);
            vh.AddTriangle(first + 2, first + 3, first);
        }
    }
}
