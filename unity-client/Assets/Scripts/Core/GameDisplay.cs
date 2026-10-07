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
            var match = game.GameMatch ?? game.DraftMatch;
            if (match == null)
            {
                return null;
            }

            var number = game.MatchGameNumber.HasValue ? $"Game {game.MatchGameNumber} of the match" : "Best of three";
            return match.Status == "completed"
                ? $"{number} ({match.YourWins} - {match.OpponentWins}, over)"
                : $"{number} (you {match.YourWins} - {match.OpponentWins})";
        }

        /// <summary>Which match a game belongs to ("draft:132" or "game:11"), the same for all its games; null for a one-off game.</summary>
        public static string MatchKey(GameSummary game) =>
            game.DraftMatchId.HasValue ? "draft:" + game.DraftMatchId.Value
            : game.GameMatchId.HasValue ? "game:" + game.GameMatchId.Value
            : null;

        /// <summary>One row of a games list: a game on its own, or every game of one match together.</summary>
        public sealed class GameEntry
        {
            /// <summary>The game itself, or for a match its latest one (the one that matters: the live game, or the last played).</summary>
            public GameSummary Game { get; set; }

            /// <summary>The games of the match, latest first; empty for a one-off game.</summary>
            public List<GameSummary> MatchGames { get; set; } = new List<GameSummary>();

            public MatchSummary Match => Game.GameMatch ?? Game.DraftMatch;

            public bool IsMatch => MatchGames.Count > 0;
        }

        /// <summary>
        /// Groups the games of each match into one entry, which keeps the place of the first of them in the list (so
        /// a match with a game waiting on you is as high as that game would be). A match's games read latest first.
        /// </summary>
        public static List<GameEntry> Group(IEnumerable<GameSummary> games)
        {
            var list = games.ToList();
            var byMatch = list.Where(g => MatchKey(g) != null).GroupBy(MatchKey).ToDictionary(g => g.Key, g => g.ToList());
            var entries = new List<GameEntry>();
            var seen = new HashSet<string>();
            foreach (var game in list)
            {
                var key = MatchKey(game);
                if (key == null)
                {
                    entries.Add(new GameEntry { Game = game });
                }
                else if (seen.Add(key))
                {
                    var ordered = byMatch[key].OrderByDescending(g => g.MatchGameNumber ?? 0).ThenByDescending(g => g.Id).ToList();
                    entries.Add(new GameEntry { Game = ordered[0], MatchGames = ordered });
                }
            }

            return entries;
        }

        /// <summary>"Finished 2026-09-01" or "Started 2026-09-01": when, for telling a match's games apart.</summary>
        public static string WhenLine(GameSummary game)
        {
            if (game.Status == "waiting")
            {
                return "Waiting to start";
            }

            var when = game.IsCompleted ? game.CompletedAt : game.StartedAt ?? game.CreatedAt;
            var day = string.IsNullOrEmpty(when) ? string.Empty : " " + (when.Length >= 10 ? when.Substring(0, 10) : when);
            return (game.IsCompleted ? "Finished" : "Started") + day;
        }

        /// <summary>"Match score: you 1, opponent 0 (first to 2 wins)".</summary>
        public static string MatchScore(MatchSummary match) =>
            $"Match score: you {match.YourWins}, opponent {match.OpponentWins} (first to {match.GamesToWin} wins)";

        /// <summary>"jceddy won the match" once the match is decided; null before.</summary>
        public static string MatchResult(MatchSummary match)
        {
            if (match.Status != "completed")
            {
                return null;
            }

            var winners = match.WinnerUsernames.Count > 0
                ? match.WinnerUsernames
                : string.IsNullOrEmpty(match.WinnerUsername) ? new List<string>() : new List<string> { match.WinnerUsername };
            return winners.Count > 0 ? string.Join(" & ", winners) + " won the match" : "Match over";
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
