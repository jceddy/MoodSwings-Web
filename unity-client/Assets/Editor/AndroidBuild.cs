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
    /// A release build (not a development one) signed with the release keystore, found through the MOOD_KEYSTORE,
    /// MOOD_KEYSTORE_PASS and MOOD_KEY_ALIAS environment variables, or a signing.properties file in ~/.mood
    /// (keystore=, alias=, storepass=, keypass=). The keystore is never in the repo: keep a backup of it, because
    /// an update only installs over an app signed with the same key. Without one, it builds a development
    /// (debug-signed) APK and says so.
    ///
    /// IL2CPP is required for 64-bit (Mono only does ARMv7, and Google Play
    /// requires 64-bit). ARM64 only: Unity 6.6 dropped x86/x86_64 Android, so
    /// the standard x86_64 emulator can no longer run these builds -- test on
    /// a physical ARM64 device.
    /// </summary>
    public static class AndroidBuild
    {
        public const string ApplicationId = "com.MoodSwings.MOOD";

        private const string OutputPath = "Build/Android/MOOD.apk";

        [MenuItem("MoodSwings/Build Android APK")]
        public static void Build()
        {
            PlayerSettings.SetScriptingBackend(NamedBuildTarget.Android, ScriptingImplementation.IL2CPP);
            PlayerSettings.Android.targetArchitectures = AndroidArchitecture.ARM64;
            EditorUserBuildSettings.buildAppBundle = false;
            AppIcons.Apply();
            // Pinned, because Firebase (push) and every update are tied to it; it's what Unity derives from the
            // company and product names, written out so renaming either can't change it.
            PlayerSettings.SetApplicationIdentifier(NamedBuildTarget.Android, ApplicationId);

            var signed = TryUseReleaseKey();
            Directory.CreateDirectory(Path.GetDirectoryName(OutputPath));

            var report = BuildPipeline.BuildPlayer(new BuildPlayerOptions
            {
                scenes = EditorBuildSettings.scenes.Where(s => s.enabled).Select(s => s.path).ToArray(),
                locationPathName = OutputPath,
                target = BuildTarget.Android,
                options = signed ? BuildOptions.None : BuildOptions.Development,
            });

            var summary = report.summary;
            Debug.Log($"AndroidBuild ({(signed ? "release, signed" : "development, debug-signed")}): {summary.result}, {summary.totalErrors} errors, {summary.totalSize / (1024 * 1024)} MB -> {summary.outputPath}");

            if (Application.isBatchMode)
            {
                EditorApplication.Exit(summary.result == BuildResult.Succeeded ? 0 : 1);
            }
        }

        /// <summary>Points the build at the release keystore when there is one; false (with a warning) when there isn't.</summary>
        private static bool TryUseReleaseKey()
        {
            var keystore = Environment.GetEnvironmentVariable("MOOD_KEYSTORE");
            var storePass = Environment.GetEnvironmentVariable("MOOD_KEYSTORE_PASS");
            var alias = Environment.GetEnvironmentVariable("MOOD_KEY_ALIAS") ?? "mood";
            var keyPass = Environment.GetEnvironmentVariable("MOOD_KEY_PASS") ?? storePass;

            var properties = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), ".mood", "signing.properties");
            if (string.IsNullOrEmpty(keystore) && File.Exists(properties))
            {
                var values = File.ReadAllLines(properties)
                    .Select(l => l.Split(new[] { '=' }, 2))
                    .Where(p => p.Length == 2)
                    .ToDictionary(p => p[0].Trim(), p => p[1].Trim());
                values.TryGetValue("keystore", out keystore);
                values.TryGetValue("storepass", out storePass);
                values.TryGetValue("keypass", out keyPass);
                if (values.TryGetValue("alias", out var fileAlias))
                {
                    alias = fileAlias;
                }
            }

            if (string.IsNullOrEmpty(keystore) || !File.Exists(keystore) || string.IsNullOrEmpty(storePass))
            {
                PlayerSettings.Android.useCustomKeystore = false;
                Debug.LogWarning("AndroidBuild: no release keystore found (MOOD_KEYSTORE env vars or ~/.mood/signing.properties); building a development APK.");
                return false;
            }

            PlayerSettings.Android.useCustomKeystore = true;
            PlayerSettings.Android.keystoreName = keystore;
            PlayerSettings.Android.keystorePass = storePass;
            PlayerSettings.Android.keyaliasName = alias;
            PlayerSettings.Android.keyaliasPass = keyPass ?? storePass;
            return true;
        }
    }
}
