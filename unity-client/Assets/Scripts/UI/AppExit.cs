using System;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Leaving the app, and switching between full screen and a window. The menus offer Quit on desktops (where a
    /// full-screen window has no close button) and on Android, as a convenience; F11 (or Alt+Enter) toggles the window
    /// on desktops only. iOS has no Quit: Apple's guidelines ask apps not to close themselves.
    /// </summary>
    public static class AppExit
    {
        /// <summary>What Quit does; tests swap it, since quitting would end the test run.</summary>
        public static Action Quit = QuitApplication;

        /// <summary>Whether this platform has a Quit (a desktop or Android; in the Editor, where it stops play mode).</summary>
        public static bool IsAvailable => IsAvailableOn(Application.platform);

        /// <summary>Whether the window can be switched between full screen and windowed (desktops).</summary>
        public static bool CanToggleFullscreen => IsAvailable && Application.platform != RuntimePlatform.Android;

        public static bool IsAvailableOn(RuntimePlatform platform)
        {
            switch (platform)
            {
                case RuntimePlatform.WindowsPlayer:
                case RuntimePlatform.WindowsEditor:
                case RuntimePlatform.OSXPlayer:
                case RuntimePlatform.OSXEditor:
                case RuntimePlatform.LinuxPlayer:
                case RuntimePlatform.LinuxEditor:
                case RuntimePlatform.Android:
                    return true;
                default:
                    return false;
            }
        }

        /// <summary>From full screen to a window of a sensible size, or back to full screen at the desktop's size.</summary>
        public static void ToggleFullscreen()
        {
            if (Screen.fullScreen)
            {
                var width = Mathf.Min(1600, Screen.currentResolution.width - 120);
                var height = Mathf.Min(900, Screen.currentResolution.height - 120);
                Screen.SetResolution(width, height, FullScreenMode.Windowed);
            }
            else
            {
                var desktop = Screen.currentResolution;
                Screen.SetResolution(desktop.width, desktop.height, FullScreenMode.FullScreenWindow);
            }
        }

        private static void QuitApplication()
        {
#if UNITY_EDITOR
            UnityEditor.EditorApplication.isPlaying = false;
#else
            Application.Quit();
#endif
        }
    }
}
