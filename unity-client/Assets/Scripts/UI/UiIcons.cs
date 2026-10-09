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

        /// <summary>An eye: an almond outline around an iris with a pupil. For "look at this".</summary>
        public static Sprite Eye()
        {
            if (_eye == null)
            {
                _eye = Create(Draw);
            }

            return _eye;
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
