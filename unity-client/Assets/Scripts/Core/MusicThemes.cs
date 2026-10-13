using System;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>The pieces of background music: the main theme, and one for each of the game's colors.</summary>
    public enum MusicTheme
    {
        Main,
        White,
        Blue,
        Black,
        Red,
        Green,
    }

    /// <summary>Which music goes with what is happening.</summary>
    public static class MusicThemes
    {
        /// <summary>The name of the clip (under Resources/Music) that holds a theme.</summary>
        public static string ClipName(MusicTheme theme) => theme.ToString().ToLowerInvariant();

        /// <summary>The theme for a mood's color; the main theme for a color there is no music for.</summary>
        public static MusicTheme ForColor(string color)
        {
            switch ((color ?? string.Empty).ToLowerInvariant())
            {
                case "white": return MusicTheme.White;
                case "blue": return MusicTheme.Blue;
                case "black": return MusicTheme.Black;
                case "red": return MusicTheme.Red;
                case "green": return MusicTheme.Green;
                default: return MusicTheme.Main;
            }
        }

        /// <summary>
        /// The theme for a game on the board: the color of the mood played most recently (the server lists the moods in
        /// play in the order they came into play), or the main theme before any has been played this round.
        /// </summary>
        public static MusicTheme For(GameState state)
        {
            var inPlay = state?.InPlay;
            if (inPlay == null || inPlay.Count == 0)
            {
                return MusicTheme.Main;
            }

            return ForColor(inPlay[inPlay.Count - 1].Color);
        }

        /// <summary>The level of one track after a step of a fade: moves toward 1 (wanted) or 0, taking `seconds` to cross it.</summary>
        public static float Fade(float level, bool wanted, float deltaSeconds, float seconds)
        {
            if (seconds <= 0f)
            {
                return wanted ? 1f : 0f;
            }

            var step = deltaSeconds / seconds;
            var next = wanted ? level + step : level - step;
            return Math.Max(0f, Math.Min(1f, next));
        }
    }
}
