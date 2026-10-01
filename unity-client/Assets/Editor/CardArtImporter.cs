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

        // Bump when the settings below change, so Unity reimports existing
        // textures (git-ignored art has no committed .meta to invalidate).
        public override uint GetVersion() => 2;

        private void OnPreprocessTexture()
        {
            if (!assetPath.StartsWith(Folder))
            {
                return;
            }

            var importer = (TextureImporter)assetImporter;
            importer.textureType = TextureImporterType.Sprite;
            importer.spriteImportMode = SpriteImportMode.Single;
            importer.mipmapEnabled = false;
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
