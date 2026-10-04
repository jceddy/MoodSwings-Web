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
    /// Headless-friendly Windows build:
    /// Unity -batchmode -quit -buildTarget StandaloneWindows64 -executeMethod MoodSwings.Editor.WindowsBuild.Build
    /// Output: Build/Windows/MoodSwings/MoodSwings.exe (git-ignored). tools/package_windows.ps1 turns that
    /// folder into a zip, and into an installer when Inno Setup is installed.
    /// </summary>
    public static class WindowsBuild
    {
        public const string OutputFolder = "Build/Windows/MoodSwings";

        private const string ExeName = "MoodSwings.exe";

        [MenuItem("MoodSwings/Build Windows Player")]
        public static void Build()
        {
            // What the window and the folder under %AppData% are called, instead of the project's folder name.
            PlayerSettings.productName = "MoodSwings";
            PlayerSettings.companyName = "MoodSwings";
            PlayerSettings.SetScriptingBackend(NamedBuildTarget.Standalone, ScriptingImplementation.Mono2x);

            if (Directory.Exists(OutputFolder))
            {
                Directory.Delete(OutputFolder, recursive: true);
            }

            Directory.CreateDirectory(OutputFolder);

            var report = BuildPipeline.BuildPlayer(new BuildPlayerOptions
            {
                scenes = EditorBuildSettings.scenes.Where(s => s.enabled).Select(s => s.path).ToArray(),
                locationPathName = Path.Combine(OutputFolder, ExeName),
                target = BuildTarget.StandaloneWindows64,
                options = BuildOptions.None,
            });

            var summary = report.summary;
            Debug.Log($"WindowsBuild: {summary.result}, {summary.totalErrors} errors, {summary.totalSize / (1024 * 1024)} MB -> {summary.outputPath}");

            if (Application.isBatchMode)
            {
                EditorApplication.Exit(summary.result == BuildResult.Succeeded ? 0 : 1);
            }
        }
    }
}
