using System;
using System.IO;
using UnityEngine;

namespace MoodSwings.Tests
{
    public static class ScreenshotHelper
    {
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
            Debug.Log("Screenshot: " + path);
            return path;
        }
    }
}
