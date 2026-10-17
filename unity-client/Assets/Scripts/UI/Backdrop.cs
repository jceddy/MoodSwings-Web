using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The table every screen sits on: a wood background, light or dark to suit the palette, covering the whole display
    /// (the parts a phone's notch or rounded corners take up too). It is cropped, not stretched, to fill any shape of
    /// screen, and a thin wash of the palette's page color over it keeps text readable.
    /// </summary>
    public sealed class Backdrop : MonoBehaviour
    {
        private const float WashAlpha = 0.18f;

        private static Backdrop _current;

        private RawImage _image;
        private RectTransform _rect;
        private Vector2 _shapeSeen;

        /// <summary>Whether a backdrop with its picture is behind the screens.</summary>
        public static bool IsShowing => _current != null && _current.Texture != null;

        /// <summary>The texture drawn, for tests.</summary>
        public Texture Texture => _image != null ? _image.texture : null;

        /// <summary>
        /// Puts the backdrop behind everything else under <paramref name="parent"/> (the canvas). Without the art (it is
        /// a loose file under Resources) it draws the palette's plain page color instead.
        /// </summary>
        public static Backdrop Create(Transform parent)
        {
            var theme = AppServices.Theme;
            var rect = UiFactory.Create("Backdrop", parent);
            UiFactory.Stretch(rect);
            rect.SetAsFirstSibling();

            var image = rect.gameObject.AddComponent<RawImage>();
            image.raycastTarget = false;
            var texture = Resources.Load<Texture2D>("Backgrounds/" + (theme.IsDark ? "dark-background" : "light-background"));
            if (texture != null)
            {
                image.texture = texture;
            }
            else
            {
                image.color = theme.background;
            }

            var wash = UiFactory.Create("Wash", rect);
            UiFactory.Stretch(wash);
            var washImage = wash.gameObject.AddComponent<Image>();
            washImage.color = new Color(theme.background.r, theme.background.g, theme.background.b, texture != null ? WashAlpha : 0f);
            washImage.raycastTarget = false;

            var backdrop = rect.gameObject.AddComponent<Backdrop>();
            backdrop._image = image;
            backdrop._rect = rect;
            backdrop.Fit();
            _current = backdrop;
            return backdrop;
        }

        private void Update()
        {
            var shape = _rect.rect.size;
            if (shape != _shapeSeen)
            {
                Fit();
            }
        }

        // Shows the middle of the picture that fits the screen's shape, at the picture's own proportions.
        private void Fit()
        {
            var shape = _rect.rect.size;
            _shapeSeen = shape;
            var texture = _image.texture;
            if (texture == null || shape.x <= 0f || shape.y <= 0f)
            {
                _image.uvRect = new Rect(0f, 0f, 1f, 1f);
                return;
            }

            var pictureAspect = (float)texture.width / texture.height;
            var screenAspect = shape.x / shape.y;
            _image.uvRect = screenAspect > pictureAspect
                ? new Rect(0f, (1f - pictureAspect / screenAspect) / 2f, 1f, pictureAspect / screenAspect)   // wider: crop the top and bottom
                : new Rect((1f - screenAspect / pictureAspect) / 2f, 0f, screenAspect / pictureAspect, 1f); // taller: crop the sides
        }
    }
}
