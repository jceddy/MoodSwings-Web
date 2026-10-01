using System.IO;
using UnityEditor;
using UnityEngine;
using UnityEngine.Rendering;
using UnityEngine.Rendering.Universal;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Creates the project's URP asset + Universal Renderer under
    /// Assets/Settings and makes them the default and every quality level's
    /// pipeline (the Built-In Render Pipeline is deprecated since Unity 6.5).
    /// Idempotent. Headless:
    /// Unity -batchmode -quit -projectPath . -executeMethod MoodSwings.Editor.UrpSetup.Apply
    ///
    /// Mobile-minded settings, since this is a uGUI-only app with no lights:
    /// no HDR buffer, no MSAA, no shadows. Color space stays Gamma so the UI
    /// colors don't shift.
    /// </summary>
    public static class UrpSetup
    {
        private const string Folder = "Assets/Settings";
        private const string AssetPath = Folder + "/URP.asset";
        private const string RendererPath = Folder + "/URP-Renderer.asset";

        [MenuItem("MoodSwings/Set Up URP")]
        public static void Apply()
        {
            Directory.CreateDirectory(Folder);

            var asset = AssetDatabase.LoadAssetAtPath<UniversalRenderPipelineAsset>(AssetPath);
            if (asset == null)
            {
                var renderer = ScriptableObject.CreateInstance<UniversalRendererData>();
                AssetDatabase.CreateAsset(renderer, RendererPath);

                asset = UniversalRenderPipelineAsset.Create(renderer);
                AssetDatabase.CreateAsset(asset, AssetPath);
            }

            asset.supportsHDR = false;
            asset.msaaSampleCount = 1;
            DisableShadows(asset);
            EditorUtility.SetDirty(asset);

            GraphicsSettings.defaultRenderPipeline = asset;

            var original = QualitySettings.GetQualityLevel();
            for (var level = 0; level < QualitySettings.names.Length; level++)
            {
                QualitySettings.SetQualityLevel(level, applyExpensiveChanges: false);
                QualitySettings.renderPipeline = asset;
            }

            QualitySettings.SetQualityLevel(original, applyExpensiveChanges: false);

            AssetDatabase.SaveAssets();
            LogShadowState(asset);
            Debug.Log($"UrpSetup: default pipeline = {GraphicsSettings.defaultRenderPipeline.name}, " +
                      $"{QualitySettings.names.Length} quality levels assigned.");
        }

        // The shadow switches are read-only properties over serialized
        // fields, so go through those. Warn rather than fail if a future URP
        // renames them -- shadows being on is a cost, not a correctness issue.
        private static void DisableShadows(UniversalRenderPipelineAsset asset)
        {
            var serialized = new SerializedObject(asset);
            foreach (var field in new[] { "m_MainLightShadowsSupported", "m_AdditionalLightShadowsSupported" })
            {
                var property = serialized.FindProperty(field);
                if (property == null)
                {
                    Debug.LogWarning($"UrpSetup: URP asset has no '{field}' field; leaving that shadow setting alone.");
                    continue;
                }

                property.boolValue = false;
            }

            serialized.ApplyModifiedPropertiesWithoutUndo();
        }

        private static void LogShadowState(UniversalRenderPipelineAsset asset)
        {
            Debug.Log($"UrpSetup: hdr={asset.supportsHDR} msaa={asset.msaaSampleCount} " +
                      $"mainShadows={asset.supportsMainLightShadows} additionalShadows={asset.supportsAdditionalLightShadows}");
        }
    }
}
