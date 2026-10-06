using System;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Leaving the app and switching between full screen and a window, for the desktop builds: a full-screen window
    /// has no close button, so the menus offer Quit and F11 (or Alt+Enter) toggles the window. Phones leave the app
    /// with the system's own gestures, so none of this is offered there.
    /// </summary>
    public static class AppExit
    {
        /// <summary>What Quit does; tests swap it, since quitting would end the test run.</summary>
        public static Action Quit = QuitApplication;

        /// <summary>Whether this platform has a Quit (a desktop; also the Editor, where it stops play mode).</summary>
        public static bool IsAvailable => IsAvailableOn(Application.platform);

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
