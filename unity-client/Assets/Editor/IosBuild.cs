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
    /// Makes the Xcode project for the iOS client. It has to run in a Unity Editor that has the "iOS Build Support"
    /// module installed (on a Mac, or on Windows, which can make the project but not build it); Xcode, on a Mac, turns
    /// the project into the app. Menu: MoodSwings > Build iOS Xcode Project, or headless:
    /// Unity -batchmode -quit -buildTarget iOS -executeMethod MoodSwings.Editor.IosBuild.Build
    /// Output: Build/iOS/ (git-ignored); open Build/iOS/Unity-iPhone.xcodeproj. See docs/building-for-ios.md.
    ///
    /// Environment variables (all optional):
    ///   MOOD_IOS_TEAM_ID       the Apple Developer Team ID (10 characters) Xcode signs with, so it needn't be chosen there
    ///   MOOD_IOS_BUNDLE_ID     a different bundle identifier, if com.jceddy.moodswings is taken on the Apple account in use
    ///                          (a free account can't use an identifier another account has claimed)
    ///   MOOD_IOS_BUILD_NUMBER  CFBundleVersion, which counts up with every build uploaded to the same app (default 1)
    /// The version people see is the project's Version (Player Settings), the same as on every other platform.
    /// </summary>
    public static class IosBuild
    {
        public const string OutputFolder = "Build/iOS";
        public const string DefaultBundleId = "com.jceddy.moodswings";
        public const string MinimumOsVersion = "15.0";

        [MenuItem("MoodSwings/Build iOS Xcode Project")]
        public static void Build()
        {
            var bundleId = Variable("MOOD_IOS_BUNDLE_ID") ?? DefaultBundleId;
            Configure(bundleId, Variable("MOOD_IOS_TEAM_ID"), Variable("MOOD_IOS_BUILD_NUMBER") ?? "1");

            if (Directory.Exists(OutputFolder))
            {
                Directory.Delete(OutputFolder, recursive: true);
            }

            Directory.CreateDirectory(OutputFolder);

            var report = BuildPipeline.BuildPlayer(new BuildPlayerOptions
            {
                scenes = EditorBuildSettings.scenes.Where(s => s.enabled).Select(s => s.path).ToArray(),
                locationPathName = OutputFolder,
                target = BuildTarget.iOS,
                options = BuildOptions.None,
            });

            var summary = report.summary;
            Debug.Log($"IosBuild: {summary.result}, {summary.totalErrors} errors -> {summary.outputPath} (bundle id {bundleId})");

            if (Application.isBatchMode)
            {
                EditorApplication.Exit(summary.result == BuildResult.Succeeded ? 0 : 1);
            }
        }

        /// <summary>The player settings an iPhone and iPad build needs, on top of the ones every platform shares.</summary>
        public static void Configure(string bundleId, string teamId, string buildNumber)
        {
            PlayerSettings.productName = "MOOD";
            PlayerSettings.companyName = "MoodSwings";
            PlayerSettings.SetApplicationIdentifier(NamedBuildTarget.iOS, bundleId);

            // IL2CPP is the only scripting backend iOS has; ARM64 is the only architecture.
            PlayerSettings.SetScriptingBackend(NamedBuildTarget.iOS, ScriptingImplementation.IL2CPP);
            PlayerSettings.SetArchitecture(NamedBuildTarget.iOS, 1);

            PlayerSettings.iOS.targetOSVersionString = MinimumOsVersion;
            PlayerSettings.iOS.targetDevice = iOSTargetDevice.iPhoneAndiPad;
            PlayerSettings.iOS.sdkVersion = iOSSdkVersion.DeviceSDK;
            PlayerSettings.iOS.buildNumber = buildNumber;

            // The game is landscape and uses the whole screen: no split-screen or slide-over on an iPad.
            PlayerSettings.iOS.requiresFullScreen = true;

            // Xcode signs for the device by itself, with whichever team it is given.
            PlayerSettings.iOS.appleEnableAutomaticSigning = true;
            if (!string.IsNullOrWhiteSpace(teamId))
            {
                PlayerSettings.iOS.appleDeveloperTeamID = teamId.Trim();
            }

            AppIcons.Apply();
        }

        private static string Variable(string name)
        {
            var value = Environment.GetEnvironmentVariable(name);
            return string.IsNullOrWhiteSpace(value) ? null : value.Trim();
        }
    }
}
