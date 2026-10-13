using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Shared colors/metrics, so screens don't hard-code their own. The palette is the game's five colors: white for
    /// the page, blue for what you act on, black for text, red for what is dangerous or resigns and green for what is
    /// good. It comes in two modes -- light (the default) and dark -- chosen by <see cref="Apply"/>; screens read the
    /// colors when they are built, so changing the mode means building them again.
    /// </summary>
    [CreateAssetMenu(menuName = "MoodSwings/UI Theme", fileName = "UiTheme")]
    public sealed class UiTheme : ScriptableObject
    {
        public Color background = new Color(0.95f, 0.95f, 0.93f);
        public Color panel = new Color(1.00f, 1.00f, 1.00f);

        /// <summary>One step above a panel: buttons, rows, inputs.</summary>
        public Color raised = new Color(0.87f, 0.90f, 0.95f);

        /// <summary>The primary action, headings and what is switched on.</summary>
        public Color accent = new Color(0.13f, 0.38f, 0.74f);

        /// <summary>Text (and icons) drawn on the accent color.</summary>
        public Color onAccent = new Color(1.00f, 1.00f, 1.00f);

        public Color textPrimary = new Color(0.08f, 0.09f, 0.11f);
        public Color textMuted = new Color(0.28f, 0.31f, 0.38f);

        /// <summary>Red: errors, Resign, anything that can't be undone.</summary>
        public Color danger = new Color(0.78f, 0.16f, 0.16f);

        /// <summary>Green: good news (online, a share, the sideboard).</summary>
        public Color success = new Color(0.14f, 0.55f, 0.30f);

        /// <summary>Round-win trophies.</summary>
        public Color gold = new Color(0.86f, 0.64f, 0.08f);

        /// <summary>A very subtle mark on a surface: empty slots, scroll tracks, unlit dots.</summary>
        public Color faint = new Color(0.05f, 0.08f, 0.14f, 0.10f);

        public float spacing = 16f;
        public float cornerRadius = 8f;

        /// <summary>Whether the dark palette is in use.</summary>
        public bool IsDark { get; private set; }

        /// <summary>Raised and lit: the chosen tab or option.</summary>
        public Color Selected => Color.Lerp(raised, accent, 0.30f);

        /// <summary>Switches to the light or the dark palette.</summary>
        public void Apply(bool dark)
        {
            IsDark = dark;
            if (dark)
            {
                background = new Color(0.06f, 0.07f, 0.09f);
                panel = new Color(0.11f, 0.13f, 0.17f);
                raised = new Color(0.19f, 0.22f, 0.29f);
                accent = new Color(0.40f, 0.64f, 1.00f);
                onAccent = new Color(0.04f, 0.06f, 0.10f);
                textPrimary = new Color(0.94f, 0.94f, 0.92f);
                textMuted = new Color(0.62f, 0.65f, 0.72f);
                danger = new Color(0.92f, 0.32f, 0.32f);
                success = new Color(0.32f, 0.80f, 0.50f);
                gold = new Color(0.95f, 0.72f, 0.18f);
                faint = new Color(1f, 1f, 1f, 0.12f);
            }
            else
            {
                background = new Color(0.95f, 0.95f, 0.93f);
                panel = new Color(1.00f, 1.00f, 1.00f);
                raised = new Color(0.87f, 0.90f, 0.95f);
                accent = new Color(0.13f, 0.38f, 0.74f);
                onAccent = new Color(1.00f, 1.00f, 1.00f);
                textPrimary = new Color(0.08f, 0.09f, 0.11f);
                textMuted = new Color(0.28f, 0.31f, 0.38f);
                danger = new Color(0.78f, 0.16f, 0.16f);
                success = new Color(0.14f, 0.55f, 0.30f);
                gold = new Color(0.86f, 0.64f, 0.08f);
                faint = new Color(0.05f, 0.08f, 0.14f, 0.10f);
            }
        }
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
