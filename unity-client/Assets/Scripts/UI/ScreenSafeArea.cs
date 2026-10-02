using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Keeps a screen's content out of the parts of a phone's display that are covered or
    /// cut away -- a camera notch, rounded corners, the gesture bar -- by shrinking the
    /// screen's rectangle to the area the system says is safe. The bars left over show the
    /// camera's background colour, which matches the screens', so it looks like margin.
    /// </summary>
    public static class ScreenSafeArea
    {
        /// <summary>Replaces the system's safe area (tests, or previewing a notch in the Editor).</summary>
        public static Rect? Override { get; set; }

        public static Rect Current => Override ?? Screen.safeArea;

        /// <summary>
        /// The anchors that fit a rectangle to <paramref name="safe"/> within a screen of the
        /// given size. A safe area that is empty, or reported as larger than the screen, means the
        /// whole screen.
        /// </summary>
        public static void Anchors(Rect safe, float screenWidth, float screenHeight, out Vector2 min, out Vector2 max)
        {
            if (screenWidth <= 0f || screenHeight <= 0f || safe.width <= 0f || safe.height <= 0f)
            {
                min = Vector2.zero;
                max = Vector2.one;
                return;
            }

            min = new Vector2(Mathf.Clamp01(safe.xMin / screenWidth), Mathf.Clamp01(safe.yMin / screenHeight));
            max = new Vector2(Mathf.Clamp01(safe.xMax / screenWidth), Mathf.Clamp01(safe.yMax / screenHeight));
        }

        /// <summary>Fits a full-screen rectangle to the current safe area.</summary>
        public static void Apply(RectTransform rect)
        {
            Anchors(Current, Screen.width, Screen.height, out var min, out var max);
            rect.anchorMin = min;
            rect.anchorMax = max;
            rect.offsetMin = Vector2.zero;
            rect.offsetMax = Vector2.zero;
        }
    }
}
