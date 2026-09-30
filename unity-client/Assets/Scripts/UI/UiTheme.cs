using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Shared colors/metrics, so screens don't hard-code their own. Create
    /// an asset via Assets > Create > MoodSwings > UI Theme. Values here are
    /// a neutral dark placeholder until the Arena-style look is designed.
    /// </summary>
    [CreateAssetMenu(menuName = "MoodSwings/UI Theme", fileName = "UiTheme")]
    public sealed class UiTheme : ScriptableObject
    {
        public Color background = new Color(0.07f, 0.08f, 0.10f);
        public Color panel = new Color(0.12f, 0.14f, 0.17f);
        public Color accent = new Color(0.95f, 0.68f, 0.20f);
        public Color textPrimary = new Color(0.94f, 0.94f, 0.92f);
        public Color textMuted = new Color(0.60f, 0.62f, 0.66f);
        public Color danger = new Color(0.85f, 0.25f, 0.25f);

        public float spacing = 16f;
        public float cornerRadius = 8f;
    }

    public static class CanvasSetup
    {
        /// <summary>Every layout is authored against this, then scaled to the real screen.</summary>
        public static readonly Vector2 ReferenceResolution = new Vector2(1920f, 1080f);

        /// <summary>
        /// Landscape-only (mobile) and desktop windows both span wide
        /// aspect ratios, so scale by a blend of width and height (0.5)
        /// rather than pinning to one axis.
        /// </summary>
        public static void Configure(CanvasScaler scaler)
        {
            scaler.uiScaleMode = CanvasScaler.ScaleMode.ScaleWithScreenSize;
            scaler.referenceResolution = ReferenceResolution;
            scaler.screenMatchMode = CanvasScaler.ScreenMatchMode.MatchWidthOrHeight;
            scaler.matchWidthOrHeight = 0.5f;
        }
    }
}
