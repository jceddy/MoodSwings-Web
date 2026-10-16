using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>The generated sprites and colors behind the glow around a mood and the lines between moods.</summary>
    public static class EffectArt
    {
        /// <summary>How far past a card's edge its glow reaches, in canvas units (the sprite's slice border).</summary>
        public const float GlowReach = 28f;

        private const int Size = 64;
        private const float Border = 28f;

        private static Sprite _halo;
        private static Sprite _ring;
        private static Texture2D _dot;

        /// <summary>
        /// A soft halo around a rounded rectangle, brightest at the edge and fading away from it, and clear inside it so it
        /// can sit under a card without tinting the card. Sliced: stretches to any card size with the same reach.
        /// </summary>
        public static Sprite Halo() => _halo != null ? _halo : _halo = Slice(distance => distance <= 0f ? 0f : Mathf.Exp(-distance / 9f) * Mathf.Clamp01(distance * 2f));

        /// <summary>A thin bright outline just past the edge, for a ripple that spreads outwards.</summary>
        public static Sprite Ring() => _ring != null ? _ring : _ring = Slice(distance => Mathf.Clamp01(1f - Mathf.Abs(distance - 3f) / 2.5f));

        /// <summary>A soft round dot: opaque in the middle, fading to nothing at the rim.</summary>
        public static Texture2D Dot()
        {
            if (_dot != null)
            {
                return _dot;
            }

            const int size = 32;
            _dot = new Texture2D(size, size, TextureFormat.RGBA32, false) { filterMode = FilterMode.Bilinear, wrapMode = TextureWrapMode.Clamp };
            for (var y = 0; y < size; y++)
            {
                for (var x = 0; x < size; x++)
                {
                    var dx = (x + 0.5f) / size * 2f - 1f;
                    var dy = (y + 0.5f) / size * 2f - 1f;
                    var radius = Mathf.Sqrt(dx * dx + dy * dy);
                    var alpha = radius >= 1f ? 0f : Mathf.Clamp01((1f - radius) / 0.65f);
                    _dot.SetPixel(x, y, new Color(1f, 1f, 1f, alpha * alpha * (3f - 2f * alpha)));
                }
            }

            _dot.Apply();
            return _dot;
        }

        /// <summary>The glow for a mood of this color: a vivid version of it that shows up on a light page as well as a dark one.</summary>
        public static Color GlowColor(string color)
        {
            switch (color)
            {
                case "white": return new Color(0.98f, 0.78f, 0.22f);
                case "blue": return new Color(0.25f, 0.56f, 1.00f);
                case "black": return new Color(0.58f, 0.36f, 0.95f);
                case "red": return new Color(0.96f, 0.28f, 0.26f);
                case "green": return new Color(0.22f, 0.82f, 0.44f);
                default: return new Color(0.98f, 0.78f, 0.22f);
            }
        }

        /// <summary>The color of the line for one kind of effect.</summary>
        public static Color LinkColor(EffectLinkKind kind)
        {
            switch (kind)
            {
                case EffectLinkKind.Suppress: return new Color(0.92f, 0.12f, 0.22f);
                case EffectLinkKind.Boost: return new Color(0.08f, 0.52f, 0.98f);
                default: return new Color(0.98f, 0.55f, 0.05f);
            }
        }

        // The distance is from the edge of the rounded rectangle in the middle of the texture, in texels: negative inside.
        private static Sprite Slice(System.Func<float, float> alphaAt)
        {
            var texture = new Texture2D(Size, Size, TextureFormat.RGBA32, false) { filterMode = FilterMode.Bilinear, wrapMode = TextureWrapMode.Clamp };
            var center = Size / 2f;
            // The card's edge falls exactly on the slice border, so a halo laid over a card with that much room around it
            // starts at the card's edge.
            var half = Size / 2f - Border;
            const float corner = 3f;
            for (var y = 0; y < Size; y++)
            {
                for (var x = 0; x < Size; x++)
                {
                    var dx = Mathf.Abs(x + 0.5f - center) - (half - corner);
                    var dy = Mathf.Abs(y + 0.5f - center) - (half - corner);
                    var outside = Mathf.Sqrt(Mathf.Max(dx, 0f) * Mathf.Max(dx, 0f) + Mathf.Max(dy, 0f) * Mathf.Max(dy, 0f));
                    var distance = outside + Mathf.Min(Mathf.Max(dx, dy), 0f) - corner;
                    texture.SetPixel(x, y, new Color(1f, 1f, 1f, Mathf.Clamp01(alphaAt(distance))));
                }
            }

            texture.Apply();
            // The border is what keeps its size when the sprite is stretched.
            return Sprite.Create(texture, new Rect(0, 0, Size, Size), new Vector2(0.5f, 0.5f), 100f, 0, SpriteMeshType.FullRect,
                new Vector4(Border, Border, Border, Border));
        }
    }

    /// <summary>
    /// The glow around a mood whose ability is having an effect on the game: a halo that breathes and a ring that spreads
    /// outwards from the card and fades. Sits under the card's own art; takes no clicks.
    /// </summary>
    public sealed class CardAura : MonoBehaviour
    {
        private const float BreathSeconds = 2.4f;
        private const float RippleSeconds = 2.4f;
        private const float RippleReach = 22f;

        private Image _halo;
        private Image _ring;
        private RectTransform _haloRect;
        private RectTransform _ringRect;
        private Color _color;
        private float _phase;

        /// <summary>The (hidden) pieces of the aura of one card, for tests.</summary>
        public Image Halo => _halo;

        public Image Ring => _ring;

        public static CardAura Attach(RectTransform card, string color, float phase)
        {
            var root = UiFactory.Create("Aura", card);
            root.anchorMin = Vector2.zero;
            root.anchorMax = Vector2.one;
            root.offsetMin = root.offsetMax = Vector2.zero;
            root.gameObject.AddComponent<LayoutElement>().ignoreLayout = true;
            root.SetAsFirstSibling();

            var aura = root.gameObject.AddComponent<CardAura>();
            aura._color = EffectArt.GlowColor(color);
            aura._phase = phase;
            aura._haloRect = Piece(root, "Halo", EffectArt.Halo(), out aura._halo);
            aura._ringRect = Piece(root, "Ripple", EffectArt.Ring(), out aura._ring);
            aura.Tick(Time.unscaledTime);
            return aura;
        }

        private static RectTransform Piece(RectTransform parent, string name, Sprite sprite, out Image image)
        {
            var rect = UiFactory.Create(name, parent);
            rect.anchorMin = Vector2.zero;
            rect.anchorMax = Vector2.one;
            image = rect.gameObject.AddComponent<Image>();
            image.sprite = sprite;
            image.type = Image.Type.Sliced;
            image.raycastTarget = false;
            return rect;
        }

        private void Update() => Tick(Time.unscaledTime);

        private void Tick(float time)
        {
            var breath = 0.5f + 0.5f * Mathf.Sin((time / BreathSeconds + _phase) * Mathf.PI * 2f);
            _halo.color = new Color(_color.r, _color.g, _color.b, 0.45f + 0.40f * breath);
            var reach = EffectArt.GlowReach + 4f * breath;
            _haloRect.offsetMin = new Vector2(-reach, -reach);
            _haloRect.offsetMax = new Vector2(reach, reach);

            // The ripple starts at the card's edge, spreads outwards and fades as it goes.
            var t = Mathf.Repeat(time / RippleSeconds + _phase * 0.5f, 1f);
            var spread = EffectArt.GlowReach + RippleReach * t;
            _ringRect.offsetMin = new Vector2(-spread, -spread);
            _ringRect.offsetMax = new Vector2(spread, spread);
            _ring.color = new Color(_color.r, _color.g, _color.b, 0.85f * (1f - t) * (1f - t));
        }
    }
}
