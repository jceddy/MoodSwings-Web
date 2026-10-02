using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    public class ScreenSafeAreaTests
    {
        [Test]
        public void ADisplayWithNoCutOuts_IsUsedWhole()
        {
            ScreenSafeArea.Anchors(new Rect(0, 0, 1920, 1080), 1920, 1080, out var min, out var max);

            Assert.AreEqual(Vector2.zero, min);
            Assert.AreEqual(Vector2.one, max);
        }

        [Test]
        public void ANotchOnOneSide_PullsThatSideIn()
        {
            // A tall phone turned sideways: 2400 wide, with 132 px of notch on the left and 66 on the right.
            ScreenSafeArea.Anchors(new Rect(132, 0, 2202, 1080), 2400, 1080, out var min, out var max);

            Assert.AreEqual(132f / 2400f, min.x, 0.0001f);
            Assert.AreEqual(2334f / 2400f, max.x, 0.0001f);
            Assert.AreEqual(0f, min.y);
            Assert.AreEqual(1f, max.y);
        }

        [Test]
        public void ABarAtTheBottom_PullsUpTheBottomEdge()
        {
            ScreenSafeArea.Anchors(new Rect(0, 63, 1920, 1017), 1920, 1080, out var min, out var max);

            Assert.AreEqual(63f / 1080f, min.y, 0.0001f);
            Assert.AreEqual(1f, max.y);
        }

        [TestCase(0, 0)]
        [TestCase(0, 1080)]
        [TestCase(1920, 0)]
        public void AnEmptySafeArea_MeansTheWholeScreen(float width, float height)
        {
            ScreenSafeArea.Anchors(new Rect(0, 0, width, height), 1920, 1080, out var min, out var max);

            Assert.AreEqual(Vector2.zero, min);
            Assert.AreEqual(Vector2.one, max);
        }

        [Test]
        public void ASafeAreaReportedLargerThanTheScreen_IsClamped()
        {
            ScreenSafeArea.Anchors(new Rect(-50, -50, 2100, 1200), 1920, 1080, out var min, out var max);

            Assert.AreEqual(Vector2.zero, min);
            Assert.AreEqual(Vector2.one, max);
        }
    }
}
