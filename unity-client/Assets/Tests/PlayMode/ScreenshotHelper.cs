using System;
using System.IO;
using UnityEngine;

namespace MoodSwings.Tests
{
    public static class ScreenshotHelper
    {
        /// <summary>
        /// Batch-mode play mode runs in a 640x480 game view, and text is
        /// rasterized for the screen size -- so a 1920x1080 capture of it
        /// comes out soft. Ask the editor for a real Full HD game view
        /// before the scene loads. Best effort: ignored outside the editor.
        /// </summary>
        public static void UseFullHdScreen()
        {
#if UNITY_EDITOR
            try
            {
                UnityEditor.PlayModeWindow.SetCustomRenderingResolution(1920, 1080, "MoodSwings tests");
            }
            catch (Exception e)
            {
                Debug.LogWarning("Couldn't set the game view size: " + e.Message);
            }
#endif
        }

        /// <summary>
        /// Renders the main camera (and the camera-space canvas under it) to
        /// a PNG for eyeballing. Goes to MOODSWINGS_SCREENSHOT_DIR if set;
        /// otherwise Temp/, which Unity wipes on exit.
        /// </summary>
        public static string Capture(string name)
        {
            var camera = Camera.main;
            var target = new RenderTexture(1920, 1080, 24);
            camera.targetTexture = target;
            Canvas.ForceUpdateCanvases();
            camera.Render();

            var previous = RenderTexture.active;
            RenderTexture.active = target;
            var texture = new Texture2D(target.width, target.height, TextureFormat.RGB24, false);
            texture.ReadPixels(new Rect(0, 0, target.width, target.height), 0, 0);
            texture.Apply();
            RenderTexture.active = previous;
            camera.targetTexture = null;

            var directory = Environment.GetEnvironmentVariable("MOODSWINGS_SCREENSHOT_DIR");
            if (string.IsNullOrEmpty(directory))
            {
                directory = Path.Combine(Application.dataPath, "..", "Temp");
            }

            Directory.CreateDirectory(directory);
            var path = Path.GetFullPath(Path.Combine(directory, name + ".png"));
            File.WriteAllBytes(path, texture.EncodeToPNG());
            UnityEngine.Object.Destroy(texture);
            target.Release();
            var canvas = UnityEngine.Object.FindAnyObjectByType<Canvas>();
            Debug.Log($"Screenshot: {path} (screen {Screen.width}x{Screen.height}, canvas scale {(canvas != null ? canvas.scaleFactor : 0f):0.###})");
            return path;
        }
    }
}
