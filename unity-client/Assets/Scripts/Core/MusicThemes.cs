using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.RegularExpressions;
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

        // "bshaftoe played Ambition from hand (discard card: Confusion); ..." -- who, and which card.
        private static readonly Regex Played = new Regex(@"^(?<who>.+?) played (?<card>.+?)(?: from |,|;| \(|$)", RegexOptions.Compiled);

        /// <summary>
        /// The theme for a game on the board: the color of the mood played most recently by anyone, which the game's
        /// recent events say (the moods in play are listed by card, not in the order they were played). The main theme
        /// before anything has been played this round, once the round has been scored, or when there is nothing to go on.
        /// </summary>
        /// <param name="catalog">Every card, if it is known: gives the color of a mood that has already left play.</param>
        public static MusicTheme For(GameState state, IEnumerable<BoardCard> catalog = null)
        {
            var inPlay = state?.InPlay;
            if (inPlay == null || inPlay.Count == 0 || state.RecentEvents == null)
            {
                return MusicTheme.Main;
            }

            foreach (var entry in state.RecentEvents.OrderByDescending(e => e.Id))
            {
                var text = entry.Description ?? string.Empty;
                if (text.StartsWith("Round scored", StringComparison.OrdinalIgnoreCase))
                {
                    return MusicTheme.Main; // a new round, with nothing played in it yet
                }

                var match = Played.Match(text);
                if (!match.Success)
                {
                    continue;
                }

                var color = ColorOfPlayed(state, match.Groups["who"].Value, match.Groups["card"].Value, catalog);
                return color != null ? ForColor(color) : MusicTheme.Main;
            }

            return MusicTheme.Main;
        }

        // The color of the card a player played: the mood in play by that name (theirs, if more than one), else the catalog's.
        private static string ColorOfPlayed(GameState state, string who, string cardName, IEnumerable<BoardCard> catalog)
        {
            var named = state.InPlay.Where(c => string.Equals(c.Name, cardName, StringComparison.OrdinalIgnoreCase)).ToList();
            var ownerIds = state.Players.Where(p => string.Equals(p.Username, who, StringComparison.OrdinalIgnoreCase)).Select(p => p.GamePlayerId).ToList();
            var theirs = named.FirstOrDefault(c => c.OwnerGamePlayerId.HasValue && ownerIds.Contains(c.OwnerGamePlayerId.Value));
            var found = theirs ?? named.FirstOrDefault();
            if (found != null)
            {
                return found.Color;
            }

            return catalog?.FirstOrDefault(c => string.Equals(c.Name, cardName, StringComparison.OrdinalIgnoreCase))?.Color;
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
