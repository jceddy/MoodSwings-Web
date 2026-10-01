using System.Collections;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.SceneManagement;
using UnityEngine.TestTools;

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

            ScreenshotHelper.Capture("CardArtSample");
        }
    }
}
