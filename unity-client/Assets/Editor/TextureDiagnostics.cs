using UnityEditor;
using UnityEngine;
using UnityEngine.Profiling;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Logs how a card texture actually imports for the active build target.
    /// Headless: Unity -batchmode -quit -executeMethod MoodSwings.Editor.TextureDiagnostics.Report
    /// </summary>
    public static class TextureDiagnostics
    {
        [MenuItem("MoodSwings/Log Card Texture Format")]
        public static void Report()
        {
            const string path = "Assets/Resources/CardArt/1.png";
            var importer = (TextureImporter)AssetImporter.GetAtPath(path);
            var texture = AssetDatabase.LoadAssetAtPath<Texture2D>(path);

            Debug.Log($"TextureDiagnostics: target={EditorUserBuildSettings.activeBuildTarget} " +
                      $"size={texture.width}x{texture.height} format={texture.format} " +
                      $"runtimeMemory={Profiler.GetRuntimeMemorySizeLong(texture) / 1024} KB " +
                      $"importerCompression={importer.textureCompression} type={importer.textureType} " +
                      $"mipmaps={importer.mipmapEnabled} maxSize={importer.maxTextureSize} " +
                      $"npotScale={importer.npotScale} readable={importer.isReadable}");

            foreach (var platform in new[] { "DefaultTexturePlatform", "Standalone", "Android" })
            {
                var settings = importer.GetPlatformTextureSettings(platform);
                Debug.Log($"TextureDiagnostics: {platform}: overridden={settings.overridden} format={settings.format} " +
                          $"compression={settings.textureCompression} maxSize={settings.maxTextureSize}");
            }
        }
    }
}
