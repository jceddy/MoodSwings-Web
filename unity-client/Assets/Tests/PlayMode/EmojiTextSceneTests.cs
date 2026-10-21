using System.Collections;
using System.Linq;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>Emoji drawn as images over a text that can't draw them.</summary>
    public class EmojiTextSceneTests
    {
        private GameObject _canvasObject;
        private GameObject _cameraObject;

        [TearDown]
        public void TearDown()
        {
            Object.Destroy(_canvasObject);
            Object.Destroy(_cameraObject);
        }

        private EmojiText Build(float width, string text, out Text label)
        {
            _cameraObject = new GameObject("Probe camera") { tag = "MainCamera" };
            var camera = _cameraObject.AddComponent<Camera>();
            camera.clearFlags = CameraClearFlags.SolidColor;
            camera.backgroundColor = Color.white;
            _canvasObject = new GameObject("Probe canvas");
            var canvas = _canvasObject.AddComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceCamera;
            canvas.worldCamera = camera;
            canvas.planeDistance = 1f;
            var scaler = _canvasObject.AddComponent<CanvasScaler>();
            scaler.uiScaleMode = CanvasScaler.ScaleMode.ScaleWithScreenSize;
            scaler.referenceResolution = new Vector2(1920f, 1080f);

            var holder = UiFactory.Create("Chat body", _canvasObject.transform);
            holder.anchorMin = holder.anchorMax = holder.pivot = new Vector2(0f, 1f);
            holder.anchoredPosition = new Vector2(60f, -60f);
            holder.sizeDelta = new Vector2(width, 0f);
            label = holder.gameObject.AddComponent<Text>();
            label.font = UiFactory.Font;
            label.fontSize = 26;
            label.color = new Color(0.1f, 0.1f, 0.1f);
            label.alignment = TextAnchor.UpperLeft;
            label.horizontalOverflow = HorizontalWrapMode.Wrap;
            label.verticalOverflow = VerticalWrapMode.Overflow;
            holder.gameObject.AddComponent<ContentSizeFitter>().verticalFit = ContentSizeFitter.FitMode.PreferredSize;
            var emoji = holder.gameObject.AddComponent<EmojiText>();
            emoji.SetText(text);
            return emoji;
        }

        private static IEnumerator Settle()
        {
            for (var i = 0; i < 4; i++)
            {
                yield return null;
            }
        }

        // The middle of an image, in the text's own space (pixels from its upper left corner).
        private static Vector2 CenterWithin(Text label, Image image)
        {
            var local = (Vector2)label.rectTransform.InverseTransformPoint(image.rectTransform.TransformPoint(image.rectTransform.rect.center));
            var pivot = label.rectTransform.pivot;
            var rect = label.rectTransform.rect;
            return new Vector2(local.x - rect.xMin, rect.yMax - local.y);
        }

        [UnityTest]
        public IEnumerator EachEmoji_IsAnImageOverItsPlaceInTheText()
        {
            ScreenshotHelper.UseFullHdScreen();
            yield return null;
            var emoji = Build(700f,
                "bshaftoe: GG \U0001F480\U0001F5FF\U0001F921 nice \U0001F62D play \U0001F451 and then a longer sentence so that it wraps onto a second line \U0001F525 and a third line of words to read",
                out var label);
            yield return Settle();

            Assert.AreEqual(6, emoji.EmojiCount);
            Assert.AreEqual(6, emoji.ImagesShown);
            StringAssert.DoesNotContain("\U0001F480", label.text, "the text itself has only blanks where they go");
            Assert.AreEqual("bshaftoe: GG ", emoji.Raw.Substring(0, 13));

            // Each is inside the text's box, and they run left to right along the first line.
            var rect = label.rectTransform.rect;
            var centers = emoji.Images.Where(i => i.gameObject.activeSelf).Select(i => CenterWithin(label, i)).ToList();
            foreach (var center in centers)
            {
                Assert.That(center.x, Is.InRange(0f, rect.width), "across");
                Assert.That(center.y, Is.InRange(0f, rect.height), "down");
            }

            Assert.Less(centers[0].x, centers[1].x);
            Assert.Less(centers[1].x, centers[2].x);
            Assert.AreEqual(centers[0].y, centers[2].y, 1f, "the first three share a line");
            Assert.Greater(centers[5].y, centers[0].y + 20f, "the last one is further down, after the wrap");
            ScreenshotHelper.Capture("emoji-text");
        }

        [UnityTest]
        public IEnumerator PlainText_HasNoImages_AndAnEmojiWithoutAnImageIsLeftOut()
        {
            var emoji = Build(700f, "no emoji here, just words \U0001F9FF", out var label);
            yield return Settle();

            Assert.AreEqual(0, emoji.EmojiCount, "this one has no image, so it is dropped");
            Assert.AreEqual(0, emoji.ImagesShown);
            Assert.AreEqual("no emoji here, just words ", label.text);
        }

        [UnityTest]
        public IEnumerator ChangingTheText_MovesTheImages()
        {
            var emoji = Build(700f, "\U0001F480 first", out var label);
            yield return Settle();
            Assert.AreEqual(1, emoji.ImagesShown);

            emoji.SetText("words \U0001F5FF\U0001F921 then more");
            yield return Settle();
            Assert.AreEqual(2, emoji.ImagesShown);
            var centers = emoji.Images.Where(i => i.gameObject.activeSelf).Select(i => CenterWithin(label, i)).ToList();
            Assert.Greater(centers[0].x, 60f, "after the word, not at the start");

            emoji.SetText("none now");
            yield return Settle();
            Assert.AreEqual(0, emoji.ImagesShown);
        }
    }
}
