using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Small icons drawn in code, so they are crisp at any size and don't depend on the font having the glyph (the
    /// built-in font has no eye). White on transparent: tint them with the Image's color.
    /// </summary>
    public static class UiIcons
    {
        private const int Size = 96;

        private static Sprite _eye;
        private static Sprite _menu;
        private static Sprite _trophy;

        /// <summary>An eye: an almond outline around an iris with a pupil. For "look at this".</summary>
        public static Sprite Eye()
        {
            if (_eye == null)
            {
                _eye = Create(Draw);
            }

            return _eye;
        }

        /// <summary>Three bars: the menu ("hamburger") icon.</summary>
        public static Sprite Menu()
        {
            if (_menu == null)
            {
                _menu = Create(DrawMenu);
            }

            return _menu;
        }

        /// <summary>A cup with handles on a stem and base: a round won.</summary>
        public static Sprite Trophy()
        {
            if (_trophy == null)
            {
                _trophy = Create(DrawTrophy);
            }

            return _trophy;
        }

        private static float DrawMenu(float x, float y)
        {
            // Three rounded bars, each a rounded-end capsule of half-length 0.78 and half-thickness 0.13.
            var best = 0f;
            foreach (var center in new[] { 0.55f, 0f, -0.55f })
            {
                var dx = Mathf.Max(0f, Mathf.Abs(x) - 0.65f);
                var distance = Mathf.Sqrt(dx * dx + (y - center) * (y - center));
                best = Mathf.Max(best, Coverage(0.13f - distance));
            }

            return best;
        }

        private static float DrawTrophy(float x, float y)
        {
            // The bowl: flat across the top, rounded below.
            var top = 0.78f;
            var rim = 0.58f;
            float halfWidth;
            if (y >= 0.34f)
            {
                halfWidth = rim;
            }
            else
            {
                var t = (0.34f - y) / 0.5f;
                halfWidth = t >= 1f ? 0f : rim * Mathf.Sqrt(1f - t * t);
            }

            var bowl = Coverage(Mathf.Min(halfWidth - Mathf.Abs(x), top - y));

            // Handles: rings on either side, hollow in the middle.
            var handles = 0f;
            foreach (var side in new[] { -1f, 1f })
            {
                var dx = x - side * 0.66f;
                var dy = y - 0.42f;
                var radius = Mathf.Sqrt(dx * dx + dy * dy);
                var ring = Coverage(0.27f - radius) * (1f - Coverage(0.14f - radius));
                // Only the part outside the bowl, so the rings read as handles.
                handles = Mathf.Max(handles, ring * (Mathf.Abs(x) > 0.5f ? 1f : 0f));
            }

            // The stem and the base.
            var stem = Coverage(Mathf.Min(0.11f - Mathf.Abs(x), Mathf.Min(0.08f - y, y + 0.46f)));
            var foot = Coverage(Mathf.Min(0.42f - Mathf.Abs(x), Mathf.Min(-0.44f - y, y + 0.78f)));

            return Mathf.Max(Mathf.Max(bowl, handles), Mathf.Max(stem, foot));
        }

        private static float Draw(float x, float y)
        {
            // The almond is the space between two arcs: |y| < Height(x). The outline is a band along that edge.
            var height = 0.52f * (1f - x * x);
            var inside = Mathf.Abs(x) <= 1f ? height - Mathf.Abs(y) : -1f;
            var outline = Coverage(0.075f - Mathf.Abs(inside)) * (Mathf.Abs(x) <= 1.02f ? 1f : 0f);

            // The iris, a disc with the pupil cut out of it, kept inside the almond.
            var radius = Mathf.Sqrt(x * x + y * y);
            var iris = Coverage(0.34f - radius) * (1f - Coverage(0.14f - radius)) * Coverage(inside);

            return Mathf.Max(outline, iris);
        }

        // One pixel is 2/Size of a unit, so this softens an edge over about a pixel.
        private static float Coverage(float distance) => Mathf.Clamp01(distance * (Size / 2f) + 0.5f);

        private static Sprite Create(System.Func<float, float, float> alphaAt)
        {
            var texture = new Texture2D(Size, Size, TextureFormat.RGBA32, false) { filterMode = FilterMode.Bilinear, wrapMode = TextureWrapMode.Clamp };
            for (var py = 0; py < Size; py++)
            {
                for (var px = 0; px < Size; px++)
                {
                    var x = (px + 0.5f) / Size * 2f - 1f;
                    var y = (py + 0.5f) / Size * 2f - 1f;
                    texture.SetPixel(px, py, new Color(1f, 1f, 1f, alphaAt(x, y)));
                }
            }

            texture.Apply();
            return Sprite.Create(texture, new Rect(0, 0, Size, Size), new Vector2(0.5f, 0.5f), 100f);
        }
    }
}
