using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    public class UiIconsTests
    {
        private static float AlphaAt(Sprite sprite, float x, float y)
        {
            var texture = sprite.texture;
            return texture.GetPixel(Mathf.RoundToInt(x * (texture.width - 1)), Mathf.RoundToInt(y * (texture.height - 1))).a;
        }

        [Test]
        public void TheEye_IsAnAlmondOutline_WithAnIrisAndAPupil()
        {
            var eye = UiIcons.Eye();

            Assert.IsNotNull(eye);
            Assert.AreSame(eye, UiIcons.Eye(), "drawn once");
            Assert.Less(AlphaAt(eye, 0.5f, 0.5f), 0.1f, "the pupil is clear");
            Assert.Greater(AlphaAt(eye, 0.5f + 0.13f, 0.5f), 0.9f, "the iris is solid");
            Assert.Greater(AlphaAt(eye, 0.5f, 0.5f + 0.26f - 0.0f), 0.9f, "the top of the outline is solid");
            Assert.Less(AlphaAt(eye, 0.02f, 0.98f), 0.05f, "the corners are clear");
            Assert.Less(AlphaAt(eye, 0.5f, 0.97f), 0.05f, "nothing above the lid");
        }
    }
}
