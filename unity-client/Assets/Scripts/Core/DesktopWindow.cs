using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>How small the desktop window may be made. Unity has no minimum window size of its own.</summary>
    public static class DesktopWindow
    {
        public const int MinimumWidth = 960;
        public const int MinimumHeight = 540;

        public static bool IsTooSmall(int width, int height) => width < MinimumWidth || height < MinimumHeight;

        public static Vector2Int Clamp(int width, int height) =>
            new Vector2Int(Mathf.Max(width, MinimumWidth), Mathf.Max(height, MinimumHeight));
    }

    /// <summary>
    /// Watches the size of a resizable desktop window and says when it should be put back to the minimum. It waits until the
    /// size has stopped changing: the window is being dragged, and pushing it back at every frame would fight the mouse.
    /// UI-free, with the time passed in.
    /// </summary>
    public sealed class WindowSizeKeeper
    {
        public const float SettleSeconds = 0.35f;

        private int _width;
        private int _height;
        private float _since;

        /// <summary>The size to set the window to, or null to leave it alone.</summary>
        public Vector2Int? Check(int width, int height, bool fullScreen, float now)
        {
            if (width != _width || height != _height)
            {
                _width = width;
                _height = height;
                _since = now;
                return null;
            }

            if (fullScreen || !DesktopWindow.IsTooSmall(width, height) || now - _since < SettleSeconds)
            {
                return null;
            }

            // Once, not every frame while the window takes up the new size.
            _since = now;
            return DesktopWindow.Clamp(width, height);
        }
    }
}
