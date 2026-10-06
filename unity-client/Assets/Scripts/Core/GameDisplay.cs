using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>Turns games and listings into text a player can read at a glance.</summary>
    public static class GameDisplay
    {
        private static readonly Dictionary<string, string> Formats = new Dictionary<string, string>
        {
            ["standard"] = "Traditional",
            ["duel"] = "Duel",
            ["draft"] = "Draft",
            ["sealed_deck"] = "Sealed Deck",
            ["sealed_pool_of_the_day"] = "Sealed Pool of the Day",
            ["team"] = "Open Team Play",
            ["closed_team"] = "Closed Team Play",
        };

        private static readonly Dictionary<string, string> Decks = new Dictionary<string, string>
        {
            ["structure"] = "Structure",
            ["power"] = "Power",
            ["jceddys_75"] = "jceddy's 75 Card",
            ["one_of_each"] = "One of Each Card",
            ["quick_draft"] = "Quick Draft",
            ["winston_draft"] = "Winston Draft",
            ["grid_draft"] = "Grid Draft",
            ["rotisserie_draft"] = "Rotisserie Draft",
            ["tiered_rotisserie_draft"] = "Tiered Rotisserie Draft",
            ["chaos_draft"] = "Chaos Draft",
            ["sealed_deck"] = "Sealed Deck",
            ["sealed_pool_of_the_day"] = "Sealed Pool of the Day",
            ["custom_duel"] = "Custom Decklists",
        };

        public static string FormatName(string format) => Lookup(Formats, format);

        public static string DeckName(string deckType, string customDeckName = null)
        {
            if (deckType == "custom")
            {
                return string.IsNullOrWhiteSpace(customDeckName) ? "Custom Decklist" : customDeckName;
            }

            return Lookup(Decks, deckType);
        }

        /// <summary>"Traditional  -  Structure  -  First to 3".</summary>
        public static string Settings(string format, string deckType, int winsNeeded, string customDeckName = null)
        {
            var parts = new List<string> { FormatName(format), DeckName(deckType, customDeckName) };
            if (winsNeeded > 0)
            {
                parts.Add("First to " + winsNeeded);
            }

            return string.Join("  -  ", parts);
        }

        public static string Settings(GameSummary game)
        {
            var settings = Settings(game.Format, game.DeckType, game.WinsNeeded, game.CustomDeckName);
            var match = MatchNote(game);
            return match == null ? settings : settings + "  -  " + match;
        }

        /// <summary>"Game 2 of the match (you 1 - 0)" for a game in a best-of-three; null for a one-off game.</summary>
        public static string MatchNote(GameSummary game)
        {
            if (game.GameMatch == null)
            {
                return null;
            }

            var number = game.MatchGameNumber.HasValue ? $"Game {game.MatchGameNumber} of the match" : "Best of three";
            return game.GameMatch.Status == "completed"
                ? $"{number} ({game.GameMatch.YourWins} - {game.GameMatch.OpponentWins}, over)"
                : $"{number} (you {game.GameMatch.YourWins} - {game.GameMatch.OpponentWins})";
        }

        /// <summary>The other players, in seat order, e.g. "BotSage, BotSageQuick".</summary>
        public static string Opponents(GameSummary game, string yourUsername)
        {
            var names = game.Players
                .Where(p => p.Username != yourUsername)
                .OrderBy(p => p.SeatOrder)
                .Select(p => p.Username)
                .ToList();
            return names.Count == 0 ? "no opponents" : string.Join(", ", names);
        }

        /// <summary>What's going on in a game right now, from the player's point of view.</summary>
        public static string StatusLine(GameSummary game, string yourUsername)
        {
            if (game.IsCompleted)
            {
                if (game.WinnerUsernames.Count == 0)
                {
                    return "Finished";
                }

                return game.WinnerUsernames.Contains(yourUsername)
                    ? "You won"
                    : "Won by " + string.Join(", ", game.WinnerUsernames);
            }

            if (game.Status == "in_progress")
            {
                if (game.IsAwaitingYourResponse)
                {
                    return "Your response is needed";
                }

                if (game.IsYourTurn)
                {
                    return "Your turn";
                }

                if (game.AwaitingResponseUsernames.Count > 0)
                {
                    return "Waiting for " + string.Join(", ", game.AwaitingResponseUsernames) + " to respond";
                }

                return string.IsNullOrEmpty(game.CurrentTurnUsername)
                    ? "In progress"
                    : "Waiting for " + game.CurrentTurnUsername;
            }

            return Prettify(game.Status);
        }

        /// <summary>True when the game is waiting on you -- worth drawing attention to.</summary>
        public static bool NeedsYou(GameSummary game) =>
            !game.IsCompleted && (game.IsYourTurn || game.IsAwaitingYourResponse);

        /// <summary>
        /// "Alice's game  -  2 of 3 seated", or "Your game  -  ..." for one you posted.
        /// The server leaves creator_username out of the list of listings you
        /// posted (it joins the username only for the other two lists), so
        /// "yours" is decided by id, and a missing name never shows as "'s game".
        /// </summary>
        public static string ListingTitle(OpenGameListing listing, int yourUserId)
        {
            string owner;
            if (listing.CreatedByUserId == yourUserId)
            {
                owner = "Your";
            }
            else if (!string.IsNullOrWhiteSpace(listing.CreatorUsername))
            {
                owner = listing.CreatorUsername + "'s";
            }
            else
            {
                owner = "Someone's";
            }

            return $"{owner} game  -  {listing.JoinedCount + 1} of {listing.TargetPlayerCount} seated";
        }

        public static string ListingSettings(OpenGameListing listing) =>
            Settings(listing.Settings.Format, listing.Settings.DeckType, listing.Settings.WinsNeeded);

        private static string Lookup(Dictionary<string, string> names, string id)
        {
            if (string.IsNullOrEmpty(id))
            {
                return string.Empty;
            }

            return names.TryGetValue(id, out var name) ? name : Prettify(id);
        }

        /// <summary>"in_progress" -> "In progress": a readable fallback for values this client doesn't know yet.</summary>
        private static string Prettify(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return string.Empty;
            }

            var spaced = value.Replace('_', ' ');
            return char.ToUpperInvariant(spaced[0]) + spaced.Substring(1);
        }
    }
}
