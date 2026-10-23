using MoodSwings.Core;
using NUnit.Framework;
using UnityEditor;

namespace MoodSwings.Tests
{
    /// <summary>A desktop window that can be resized, but not made too small.</summary>
    public class DesktopWindowTests
    {
        [Test]
        public void TheDesktopWindowIsResizable()
        {
            Assert.IsTrue(PlayerSettings.resizableWindow);
        }

        [Test]
        public void ASizeBelowTheMinimumIsRaisedToIt_OneSideAtATime()
        {
            Assert.IsTrue(DesktopWindow.IsTooSmall(800, 600));
            Assert.IsTrue(DesktopWindow.IsTooSmall(1920, 300));
            Assert.IsFalse(DesktopWindow.IsTooSmall(960, 540));
            Assert.IsFalse(DesktopWindow.IsTooSmall(1920, 1080));

            Assert.AreEqual(new UnityEngine.Vector2Int(960, 600), DesktopWindow.Clamp(700, 600));
            Assert.AreEqual(new UnityEngine.Vector2Int(1920, 540), DesktopWindow.Clamp(1920, 300));
            Assert.AreEqual(new UnityEngine.Vector2Int(1600, 900), DesktopWindow.Clamp(1600, 900), "a good size is left alone");
        }

        [Test]
        public void AWindowThatIsBeingDragged_IsLeftAloneUntilItStops()
        {
            var keeper = new WindowSizeKeeper();

            Assert.IsNull(keeper.Check(1600, 900, false, 0f), "the first look only notes the size");
            Assert.IsNull(keeper.Check(800, 500, false, 0.1f), "still being dragged");
            Assert.IsNull(keeper.Check(700, 480, false, 0.2f), "and still");
            Assert.IsNull(keeper.Check(700, 480, false, 0.3f), "stopped, but not for long yet");

            var settled = keeper.Check(700, 480, false, 0.7f);

            Assert.AreEqual(new UnityEngine.Vector2Int(960, 540), settled);
        }

        [Test]
        public void ItIsPutBackOnce_NotEveryFrameWhileTheWindowTakesUpTheNewSize()
        {
            var keeper = new WindowSizeKeeper();
            keeper.Check(700, 480, false, 0f);
            Assert.IsNotNull(keeper.Check(700, 480, false, 1f));

            Assert.IsNull(keeper.Check(700, 480, false, 1.016f), "the next frame, before the size has changed");
            Assert.IsNull(keeper.Check(960, 540, false, 1.05f), "the new size is fine");
            Assert.IsNull(keeper.Check(960, 540, false, 3f));
        }

        [Test]
        public void FullScreen_OrAGoodSize_IsNeverTouched()
        {
            var keeper = new WindowSizeKeeper();
            keeper.Check(640, 480, true, 0f);
            Assert.IsNull(keeper.Check(640, 480, true, 5f), "full screen reports whatever it likes");

            var other = new WindowSizeKeeper();
            other.Check(1280, 720, false, 0f);
            Assert.IsNull(other.Check(1280, 720, false, 5f));
        }
    }
}
