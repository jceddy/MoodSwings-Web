using UnityEditor;
using UnityEditor.Android;
using UnityEditor.Build;
using UnityEngine;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Gives the player builds their icon instead of Unity's default, from the PNGs in Assets/Icons (made by
    /// tools/make_icons.py, or replaced with real art). Called by both build scripts; harmless to repeat.
    /// </summary>
    public static class AppIcons
    {
        private const string Folder = "Assets/Icons/";

        [MenuItem("MoodSwings/Apply App Icons")]
        public static void Apply()
        {
            var full = Load("icon-1024.png");
            if (full != null)
            {
                // The Windows .exe (and any other standalone target) takes one big image and scales it down.
                var slots = PlayerSettings.GetIcons(NamedBuildTarget.Standalone, IconKind.Application).Length;
                PlayerSettings.SetIcons(NamedBuildTarget.Standalone, System.Linq.Enumerable.ToArray(System.Linq.Enumerable.Repeat(full, System.Math.Max(1, slots))), IconKind.Application);
            }

            // Unity 6 makes the round and legacy icons from the adaptive layers.
            SetAndroid(AndroidPlatformIconKind.Adaptive, Load("android-background.png"), Load("android-foreground.png"));
            AssetDatabase.SaveAssets();
        }

        private static void SetAndroid(PlatformIconKind kind, params Texture2D[] layers)
        {
            foreach (var layer in layers)
            {
                if (layer == null)
                {
                    return;
                }
            }

            var icons = PlayerSettings.GetPlatformIcons(BuildTargetGroup.Android, kind);
            foreach (var icon in icons)
            {
                // Every size of a kind is made from the same art; the layers are big enough for all of them.
                icon.SetTextures(layers);
            }

            PlayerSettings.SetPlatformIcons(BuildTargetGroup.Android, kind, icons);
        }

        private static Texture2D Load(string name) => AssetDatabase.LoadAssetAtPath<Texture2D>(Folder + name);
    }

    /// <summary>Icons are used as-is: no compression, mipmaps or power-of-two stretching to blur them.</summary>
    public sealed class IconImporter : AssetPostprocessor
    {
        private void OnPreprocessTexture()
        {
            if (!assetPath.StartsWith("Assets/Icons/"))
            {
                return;
            }

            var importer = (TextureImporter)assetImporter;
            importer.textureType = TextureImporterType.Default;
            importer.mipmapEnabled = false;
            importer.npotScale = TextureImporterNPOTScale.None;
            importer.textureCompression = TextureImporterCompression.Uncompressed;
            importer.isReadable = false;
            importer.alphaIsTransparency = true;
        }
    }
}
