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
            importer.maxTextureSize = 1024;
            importer.textureCompression = TextureImporterCompression.Compressed;
        }
    }
}
