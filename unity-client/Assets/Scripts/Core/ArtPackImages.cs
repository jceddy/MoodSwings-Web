using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>Turns the image files of an art pack into sprites the cards can draw.</summary>
    public static class ArtPackImages
    {
        /// <summary>The tallest an image is kept: the size of the card art that ships with the app.</summary>
        public const int MaxHeight = 1040;

        /// <summary>
        /// A sprite from the bytes of a PNG or JPG; null if they aren't an image Unity can read. Like the shipped art it is
        /// shrunk to at most <see cref="MaxHeight"/> tall, with both sides a multiple of 4, so it can be GPU-compressed
        /// (which keeps a phone's memory use about the same as for the shipped art), and has mipmaps so it stays clean when
        /// drawn small.
        /// </summary>
        public static Sprite ToSprite(byte[] bytes)
        {
            if (bytes == null || bytes.Length == 0)
            {
                return null;
            }

            var loaded = new Texture2D(2, 2, TextureFormat.RGBA32, mipChain: false);
            if (!ImageConversion.LoadImage(loaded, bytes, markNonReadable: false) || loaded.width < 8 || loaded.height < 8)
            {
                Object.Destroy(loaded);
                return null;
            }

            // With no graphics device (a headless run) there is nothing to resample or compress with: use it as it is.
            if (SystemInfo.graphicsDeviceType == UnityEngine.Rendering.GraphicsDeviceType.Null)
            {
                loaded.filterMode = FilterMode.Bilinear;
                return Sprite.Create(loaded, new Rect(0, 0, loaded.width, loaded.height), new Vector2(0.5f, 0.5f), 100f);
            }

            var scale = loaded.height > MaxHeight ? (float)MaxHeight / loaded.height : 1f;
            var width = RoundUpToMultipleOf4(Mathf.RoundToInt(loaded.width * scale));
            var height = RoundUpToMultipleOf4(Mathf.RoundToInt(loaded.height * scale));

            var texture = new Texture2D(width, height, TextureFormat.RGBA32, mipChain: true);
            var target = RenderTexture.GetTemporary(width, height, 0, RenderTextureFormat.ARGB32, RenderTextureReadWrite.sRGB);
            var previous = RenderTexture.active;
            loaded.filterMode = FilterMode.Bilinear;
            Graphics.Blit(loaded, target);
            RenderTexture.active = target;
            texture.ReadPixels(new Rect(0, 0, width, height), 0, 0);
            texture.Apply(updateMipmaps: true);
            RenderTexture.active = previous;
            RenderTexture.ReleaseTemporary(target);
            Object.Destroy(loaded);

            texture.name = "Art pack image";
            texture.wrapMode = TextureWrapMode.Clamp;
            texture.filterMode = FilterMode.Trilinear;
            texture.Compress(highQuality: false);
            texture.Apply(updateMipmaps: false, makeNoLongerReadable: true);
            return Sprite.Create(texture, new Rect(0, 0, width, height), new Vector2(0.5f, 0.5f), 100f);
        }

        private static int RoundUpToMultipleOf4(int value) => (value + 3) / 4 * 4;
    }
}
