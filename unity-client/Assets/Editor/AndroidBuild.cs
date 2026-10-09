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
    /// Headless-friendly Android build:
    /// Unity -batchmode -quit -buildTarget Android -executeMethod MoodSwings.Editor.AndroidBuild.Build
    /// Output: Build/Android/MOOD.apk (git-ignored).
    ///
    /// IL2CPP is required for 64-bit (Mono only does ARMv7, and Google Play
    /// requires 64-bit). ARM64 only: Unity 6.6 dropped x86/x86_64 Android, so
    /// the standard x86_64 emulator can no longer run these builds -- test on
    /// a physical ARM64 device.
    /// </summary>
    public static class AndroidBuild
    {
        private const string OutputPath = "Build/Android/MOOD.apk";

        [MenuItem("MoodSwings/Build Android APK")]
        public static void Build()
        {
            PlayerSettings.SetScriptingBackend(NamedBuildTarget.Android, ScriptingImplementation.IL2CPP);
            PlayerSettings.Android.targetArchitectures = AndroidArchitecture.ARM64;
            EditorUserBuildSettings.buildAppBundle = false;

            Directory.CreateDirectory(Path.GetDirectoryName(OutputPath));

            var report = BuildPipeline.BuildPlayer(new BuildPlayerOptions
            {
                scenes = EditorBuildSettings.scenes.Where(s => s.enabled).Select(s => s.path).ToArray(),
                locationPathName = OutputPath,
                target = BuildTarget.Android,
                options = BuildOptions.Development,
            });

            var summary = report.summary;
            Debug.Log($"AndroidBuild: {summary.result}, {summary.totalErrors} errors, {summary.totalSize / (1024 * 1024)} MB -> {summary.outputPath}");

            if (Application.isBatchMode)
            {
                EditorApplication.Exit(summary.result == BuildResult.Succeeded ? 0 : 1);
            }
        }
    }
}
