using UnityEditor;
using UnityEngine;

namespace MoodSwings.Editor
{
    /// <summary>
    /// Keeps project-wide settings the plan has decided on from drifting,
    /// without hand-editing ProjectSettings/*.asset (the Editor owns those).
    /// Runs on every editor load and is a no-op once the values match.
    /// </summary>
    [InitializeOnLoad]
    public static class ProjectSettingsEnforcer
    {
        static ProjectSettingsEnforcer()
        {
            // Mobile is landscape-only (see PLAN.md decisions).
            var changed = false;
            changed |= Set(PlayerSettings.defaultInterfaceOrientation, UIOrientation.AutoRotation,
                v => PlayerSettings.defaultInterfaceOrientation = v);
            changed |= Set(PlayerSettings.allowedAutorotateToPortrait, false,
                v => PlayerSettings.allowedAutorotateToPortrait = v);
            changed |= Set(PlayerSettings.allowedAutorotateToPortraitUpsideDown, false,
                v => PlayerSettings.allowedAutorotateToPortraitUpsideDown = v);
            changed |= Set(PlayerSettings.allowedAutorotateToLandscapeLeft, true,
                v => PlayerSettings.allowedAutorotateToLandscapeLeft = v);
            changed |= Set(PlayerSettings.allowedAutorotateToLandscapeRight, true,
                v => PlayerSettings.allowedAutorotateToLandscapeRight = v);

            // The desktop window can be resized by its edges when it is not full screen (Windows and macOS).
            changed |= Set(PlayerSettings.resizableWindow, true, v => PlayerSettings.resizableWindow = v);

            if (changed)
            {
                Debug.Log("ProjectSettingsEnforcer: applied the project's player settings.");
                AssetDatabase.SaveAssets();
            }
        }

        private static bool Set<T>(T current, T desired, System.Action<T> apply)
        {
            if (System.Collections.Generic.EqualityComparer<T>.Default.Equals(current, desired))
            {
                return false;
            }

            apply(desired);
            return true;
        }
    }
}
