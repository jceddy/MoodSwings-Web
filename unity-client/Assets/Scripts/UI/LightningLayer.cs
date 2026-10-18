using System.Collections.Generic;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Red lightning: a jagged, flickering bolt from one point on the table to another (a mood that takes others out of
    /// play, striking each one), with a glow around it, a few short forks and a flash where it lands. Laid over the table,
    /// takes no clicks, and removes each bolt when it has played out.
    /// </summary>
    public sealed class LightningLayer : MaskableGraphic
    {
        private const int Segments = 16;
        private const float FlickerSeconds = 0.045f; // the bolt takes a new shape this often

        private static readonly Color Glow = new Color(1f, 0.12f, 0.08f);
        private static readonly Color Core = new Color(1f, 0.86f, 0.82f);

        private struct Bolt
        {
            public Vector3 From;
            public Vector3 To;
            public float Start;
            public float Seconds;
            public int Seed;
        }

        private readonly List<Bolt> _bolts = new List<Bolt>();
        private int _strikes;

        public override Texture mainTexture => EffectArt.Dot();

        /// <summary>How many bolts are being drawn (or waiting to start).</summary>
        public int BoltCount => _bolts.Count;

        /// <summary>How many vertices the last redraw produced (nothing is drawn while there are none).</summary>
        public int VertexCount { get; private set; }

        public static LightningLayer Create(Transform parent)
        {
            var rect = UiFactory.Create("Lightning", parent);
            UiFactory.Stretch(rect);
            rect.gameObject.AddComponent<CanvasRenderer>();
            var layer = rect.gameObject.AddComponent<LightningLayer>();
            layer.raycastTarget = false;
            return layer;
        }

        /// <summary>A bolt between two world positions, lasting <paramref name="seconds"/>.</summary>
        public void Strike(Vector3 fromWorld, Vector3 toWorld, float seconds = 0.6f)
        {
            _bolts.Add(new Bolt { From = fromWorld, To = toWorld, Start = Time.unscaledTime, Seconds = seconds, Seed = ++_strikes * 7919 });
            SetVerticesDirty();
        }

        public void Clear()
        {
            _bolts.Clear();
            SetVerticesDirty();
        }

        private void Update()
        {
            if (_bolts.Count == 0)
            {
                return;
            }

            var now = Time.unscaledTime;
            _bolts.RemoveAll(b => now - b.Start >= b.Seconds);
            SetVerticesDirty();
        }

        protected override void OnPopulateMesh(VertexHelper vh)
        {
            vh.Clear();
            var now = Time.unscaledTime;
            foreach (var bolt in _bolts)
            {
                var progress = (now - bolt.Start) / bolt.Seconds;
                if (progress < 0f || progress >= 1f)
                {
                    continue;
                }

                // It snaps on, holds, and dies away; and it never burns steadily, it flickers.
                var envelope = Mathf.Min(1f, progress / 0.08f) * (progress > 0.65f ? 1f - (progress - 0.65f) / 0.35f : 1f);
                var frame = Mathf.FloorToInt((now - bolt.Start) / FlickerSeconds);
                var random = new System.Random(bolt.Seed + frame * 131);
                envelope *= 0.8f + 0.2f * (float)random.NextDouble();
                Draw(vh, bolt, envelope, random);
            }

            VertexCount = vh.currentVertCount;
        }

        private void Draw(VertexHelper vh, Bolt bolt, float envelope, System.Random random)
        {
            var from = (Vector2)rectTransform.InverseTransformPoint(bolt.From);
            var to = (Vector2)rectTransform.InverseTransformPoint(bolt.To);
            var span = to - from;
            var length = span.magnitude;
            if (length < 1f)
            {
                return;
            }

            var path = Path(from, to, Segments, Mathf.Min(38f, length * 0.12f), random);

            // A wide faint band, a narrower one, and a bright thin core: a glow around the bolt.
            Strip(vh, path, 46f, WithAlpha(Glow, 0.18f * envelope));
            Strip(vh, path, 22f, WithAlpha(Glow, 0.45f * envelope));
            Strip(vh, path, 9f, WithAlpha(Glow, 0.95f * envelope));
            Strip(vh, path, 3.5f, WithAlpha(Core, envelope));

            // A couple of short forks off the main bolt.
            for (var fork = 0; fork < 2; fork++)
            {
                var at = path[random.Next(3, Segments - 3)];
                var angle = (random.Next(2) == 0 ? 1f : -1f) * Mathf.Lerp(0.4f, 0.9f, (float)random.NextDouble());
                var direction = (Quaternion.Euler(0f, 0f, angle * Mathf.Rad2Deg) * (Vector3)span.normalized);
                var branch = Path(at, at + (Vector2)direction * length * 0.2f, 5, 10f, random);
                Strip(vh, branch, 10f, WithAlpha(Glow, 0.35f * envelope));
                Strip(vh, branch, 3.5f, WithAlpha(Core, 0.9f * envelope));
            }

            // A flash where it lands, and a spark where it starts.
            Quad(vh, to, 190f * envelope, WithAlpha(Glow, 0.35f * envelope));
            Quad(vh, to, 80f * envelope, WithAlpha(Core, 0.85f * envelope));
            Quad(vh, from, 70f * envelope, WithAlpha(Glow, 0.4f * envelope));
        }

        // A jagged line from a to b: each point between them thrown sideways by up to `jag`, none at the ends.
        private static Vector2[] Path(Vector2 a, Vector2 b, int segments, float jag, System.Random random)
        {
            var points = new Vector2[segments + 1];
            var along = b - a;
            var side = new Vector2(-along.y, along.x).normalized;
            for (var i = 0; i <= segments; i++)
            {
                var t = i / (float)segments;
                var offset = i == 0 || i == segments ? 0f : ((float)random.NextDouble() * 2f - 1f) * jag;
                points[i] = a + along * t + side * offset;
            }

            return points;
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
