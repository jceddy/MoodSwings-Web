using System.Collections;
using System.IO;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.SceneManagement;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    public class CardArtSampleSceneTests
    {
        [UnityTest]
        public IEnumerator Scene_ShowsConvertedCardArt()
        {
            // The art is git-ignored (see tools/convert_card_art.py), so a
            // fresh clone without it can't run this meaningfully.
            if (Resources.Load<Sprite>("CardArt/1") == null)
            {
                Assert.Ignore("Card art not generated -- run tools/convert_card_art.py.");
            }

            yield return SceneManager.LoadSceneAsync("CardArtSample");
            yield return null;
            yield return null;

            var screen = Object.FindFirstObjectByType<CardArtSampleScreen>();
            Assert.IsNotNull(screen, "CardArtSampleScreen not in scene");
            Assert.IsTrue(screen.gameObject.activeInHierarchy, "ScreenRouter didn't show the initial screen");
            Assert.AreEqual(11, screen.LoadedCount, "10 cards + Hurt Feelings should load");

            // Leave a picture behind for eyeballing. Unity wipes Temp/ on
            // exit, so set MOODSWINGS_SCREENSHOT_PATH to keep it somewhere.
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

            var path = System.Environment.GetEnvironmentVariable("MOODSWINGS_SCREENSHOT_PATH");
            if (string.IsNullOrEmpty(path))
            {
                path = Path.GetFullPath(Path.Combine(Application.dataPath, "..", "Temp", "CardArtSample.png"));
            }

            File.WriteAllBytes(path, texture.EncodeToPNG());
            Debug.Log("CardArtSample screenshot: " + path);
        }
    }
}
