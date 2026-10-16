using System;
using System.IO;
using System.Linq;
using UnityEditor;
using UnityEditor.Build;
using UnityEditor.Build.Reporting;
using UnityEngine;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Headless-friendly macOS build, which works from Windows too (it needs the Editor's "Mac Build Support (Mono)"
    /// module):
    /// Unity -batchmode -quit -buildTarget StandaloneOSX -executeMethod MoodSwings.Editor.MacBuild.Build
    /// Output: Build/macOS/MOOD.app (git-ignored). tools/package_macos.py zips it, with the permissions a Mac needs.
    ///
    /// The architecture is Intel (x64) unless the environment variable MOOD_MAC_ARCH is "universal": an Apple-silicon
    /// build must be code-signed to run at all, and an Intel one runs on every Mac (through Rosetta on Apple silicon)
    /// without being signed, which is what can be made from Windows with no Apple account.
    /// </summary>
    public static class MacBuild
    {
        public const string OutputFolder = "Build/macOS";
        public const string AppName = "MOOD.app";
        public const string BundleId = "com.jceddy.moodswings";

        [MenuItem("MoodSwings/Build macOS Player")]
        public static void Build()
        {
            PlayerSettings.productName = "MOOD";
            PlayerSettings.companyName = "MoodSwings";
            PlayerSettings.SetApplicationIdentifier(NamedBuildTarget.Standalone, BundleId);
            PlayerSettings.macOS.applicationCategoryType = "public.app-category.card-games";
            PlayerSettings.macOS.buildNumber = "1";
            AppIcons.Apply();
            PlayerSettings.SetScriptingBackend(NamedBuildTarget.Standalone, ScriptingImplementation.Mono2x);

            var architecture = string.Equals(Environment.GetEnvironmentVariable("MOOD_MAC_ARCH"), "universal", StringComparison.OrdinalIgnoreCase)
                ? "x64arm64"
                : "x64";
            EditorUserBuildSettings.SetPlatformSettings("OSXUniversal", "Architecture", architecture);

            if (Directory.Exists(OutputFolder))
            {
                Directory.Delete(OutputFolder, recursive: true);
            }

            Directory.CreateDirectory(OutputFolder);

            var report = BuildPipeline.BuildPlayer(new BuildPlayerOptions
            {
                scenes = EditorBuildSettings.scenes.Where(s => s.enabled).Select(s => s.path).ToArray(),
                locationPathName = Path.Combine(OutputFolder, AppName),
                target = BuildTarget.StandaloneOSX,
                options = BuildOptions.None,
            });

            var summary = report.summary;
            Debug.Log($"MacBuild: {summary.result}, {summary.totalErrors} errors, {summary.totalSize / (1024 * 1024)} MB ({architecture}) -> {summary.outputPath}");

            if (Application.isBatchMode)
            {
                EditorApplication.Exit(summary.result == BuildResult.Succeeded ? 0 : 1);
            }
        }
    }
}
