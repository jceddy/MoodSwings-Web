using UnityEditor;

namespace MoodSwings.Editor
{
    /// <summary>
    /// The converted card art is git-ignored (along with its .meta files),
    /// so import settings can't live in committed metas -- apply them here
    /// every time a texture under Resources/CardArt is imported instead.
    /// </summary>
    public sealed class CardArtImporter : AssetPostprocessor
    {
        private const string Folder = "Assets/Resources/CardArt/";

        // Committed art that isn't a card face: the card back. Small, so none of the card art's size tricks apply.
        private const string UiFolder = "Assets/Resources/UI/";

        // The table backgrounds (tools/make_backgrounds.py).
        private const string BackgroundFolder = "Assets/Resources/Backgrounds/";

        // Bump when the settings below change, so Unity reimports existing
        // textures (git-ignored art has no committed .meta to invalidate).
        public override uint GetVersion() => 5;

        private void OnPreprocessTexture()
        {
            if (assetPath.StartsWith(UiFolder))
            {
                // The card back is under 400 px wide and drawn smaller still; it isn't a multiple of 4, so it can't be
                // GPU-compressed anyway. Mipmaps keep it clean when it is drawn small.
                var ui = (TextureImporter)assetImporter;
                ui.textureType = TextureImporterType.Sprite;
                ui.spriteImportMode = SpriteImportMode.Single;
                ui.mipmapEnabled = true;
                ui.mipmapFilter = TextureImporterMipFilter.KaiserFilter;
                ui.filterMode = UnityEngine.FilterMode.Trilinear;
                ui.maxTextureSize = 2048;
                ui.textureCompression = TextureImporterCompression.Uncompressed;
                return;
            }

            if (assetPath.StartsWith(BackgroundFolder))
            {
                // The wood the screens sit on: 1920 x 1072, drawn full screen and never shrunk much, so no mipmaps.
                var background = (TextureImporter)assetImporter;
                background.textureType = TextureImporterType.Default;
                background.mipmapEnabled = false;
                background.filterMode = UnityEngine.FilterMode.Bilinear;
                background.wrapMode = UnityEngine.TextureWrapMode.Clamp;
                background.maxTextureSize = 2048;
                background.textureCompression = TextureImporterCompression.Compressed;
                background.npotScale = TextureImporterNPOTScale.None;
                return;
            }

            if (!assetPath.StartsWith(Folder))
            {
                return;
            }

            var importer = (TextureImporter)assetImporter;
            importer.textureType = TextureImporterType.Sprite;
            importer.spriteImportMode = SpriteImportMode.Single;
            // Cards are drawn at a fraction of their 744 px width (about 112 px on
            // the table). Without mipmaps the GPU samples scattered texels of the
            // full-size art when shrinking it, which looks harsh and noisy; mipmaps
            // hold pre-averaged smaller copies (Kaiser filtering, blended smoothly
            // by trilinear sampling). Costs about a third more memory per card.
            importer.mipmapEnabled = true;
            importer.mipmapFilter = TextureImporterMipFilter.KaiserFilter;
            importer.filterMode = UnityEngine.FilterMode.Trilinear;
            importer.alphaIsTransparency = true;
            // Never rescale: tools/convert_card_art.py already sizes the art
            // to a multiple of 4 (744x1040), which GPU compression requires.
            // A clamp below the source size (this was 1024) rescales it to a
            // width like 733 and silently leaves the texture uncompressed.
            importer.maxTextureSize = 2048;
            importer.textureCompression = TextureImporterCompression.Compressed;
        }
    }
}
