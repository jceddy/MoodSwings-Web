using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    public sealed class DeckOption
    {
        public string Id { get; set; }

        public string Label { get; set; }

        public string Description { get; set; }
    }

    /// <summary>
    /// The settings for a new game, with what's needed to send them: validate,
    /// build the POST /games or POST /open-games body, and prefill from a
    /// finished game for a rematch. Scoped to Traditional play with the
    /// ready-made deck types; the other formats (duel, drafts, teams, custom
    /// decklists) arrive in later phases, and rematch is only offered for
    /// games this can express.
    /// </summary>
    public sealed class GameSetup
    {
        public const string TraditionalFormat = "standard";

        /// <summary>A game seats 2 to 4 players, you included.</summary>
        public const int MaxPlayers = 4;

        public const int MinPlayers = 2;

        public const int DefaultWinsNeeded = 3;

        /// <summary>The deck types that need no extra input, in the order the web New Game dialog lists them.</summary>
        public static readonly IReadOnlyList<DeckOption> DeckOptions = new List<DeckOption>
        {
            new DeckOption
            {
                Id = "structure",
                Label = "Structure",
                Description = "A random 45-card deck with the rarity mix of a physical box.",
            },
            new DeckOption
            {
                Id = "power",
                Label = "Power",
                Description = "A faster 15-card deck: one Mythic plus 14 other cards.",
            },
            new DeckOption
            {
                Id = "jceddys_75",
                Label = "jceddy's 75 Card",
                Description = "75 cards, 15 per color: a Mythic, two Rares, four Uncommons and eight Commons each.",
            },
            new DeckOption
            {
                Id = "one_of_each",
                Label = "One of Each Card",
                Description = "Every card in the set, one copy of each (133 cards).",
            },
        };

        public string Format { get; set; } = TraditionalFormat;

        public string DeckType { get; set; } = "structure";

        public int WinsNeeded { get; set; } = DefaultWinsNeeded;

        public bool DefaultSelectionsMode { get; set; }

        public bool BotGoesFirst { get; set; }

        /// <summary>Friends and bots to seat directly. Ignored when posting to the open lobby.</summary>
        public List<int> OpponentUserIds { get; set; } = new List<int>();

        /// <summary>Total players an open-lobby game needs, you included.</summary>
        public int OpenLobbyPlayerCount { get; set; } = MinPlayers;

        /// <summary>Which of the two ways to start a game the New Game screen opens on.</summary>
        public bool PostToOpenLobby { get; set; }

        /// <summary>
        /// Usernames for opponent ids a prefill brings along, so the screen can
        /// show someone who is neither a practice bot nor a friend (a rematch
        /// against a stranger from the open lobby, say).
        /// </summary>
        public Dictionary<int, string> OpponentNames { get; set; } = new Dictionary<int, string>();

        /// <summary>Null if these settings can be sent as a direct game, otherwise what to fix.</summary>
        public string ValidateDirectGame()
        {
            if (!SupportsDeck(DeckType))
            {
                return "Pick a deck.";
            }

            if (OpponentUserIds.Count == 0)
            {
                return "Pick at least one opponent.";
            }

            if (OpponentUserIds.Count > MaxPlayers - 1)
            {
                return $"A game seats at most {MaxPlayers} players, so pick at most {MaxPlayers - 1} opponents.";
            }

            return null;
        }

        /// <summary>Null if these settings can be posted to the open lobby, otherwise what to fix.</summary>
        public string ValidateOpenGame()
        {
            if (!SupportsDeck(DeckType))
            {
                return "Pick a deck.";
            }

            if (OpenLobbyPlayerCount < MinPlayers || OpenLobbyPlayerCount > MaxPlayers)
            {
                return $"An open game needs {MinPlayers} to {MaxPlayers} players.";
            }

            return null;
        }

        /// <summary>The POST /games body: the opponents are seated straight away.</summary>
        public Dictionary<string, object> ToDirectGameBody()
        {
            var body = SharedBody();
            body["opponent_user_ids"] = OpponentUserIds.ToArray();
            if (BotGoesFirst)
            {
                body["bot_goes_first"] = true;
            }

            return body;
        }

        /// <summary>The POST /open-games body: the same settings, plus how many players the game waits for.</summary>
        public Dictionary<string, object> ToOpenGameBody()
        {
            var body = SharedBody();
            body["target_player_count"] = OpenLobbyPlayerCount;
            return body;
        }

        /// <summary>
        /// A rematch of a finished game: the same opponents and settings.
        /// Null when the game used something this can't express yet (another
        /// format, a custom or draft deck), so the caller offers no rematch.
        /// </summary>
        public static GameSetup ForRematch(GameSummary game, int yourUserId)
        {
            if (game.Format != TraditionalFormat || !SupportsDeck(game.DeckType))
            {
                return null;
            }

            return new GameSetup
            {
                DeckType = game.DeckType,
                WinsNeeded = game.WinsNeeded > 0 ? game.WinsNeeded : DefaultWinsNeeded,
                DefaultSelectionsMode = game.DefaultSelectionsMode,
                OpponentUserIds = game.Players.Where(p => p.UserId != yourUserId).Select(p => p.UserId).ToList(),
                OpponentNames = game.Players.Where(p => p.UserId != yourUserId).ToDictionary(p => p.UserId, p => p.Username),
            };
        }

        public static bool SupportsDeck(string deckType) => DeckOptions.Any(o => o.Id == deckType);

        private Dictionary<string, object> SharedBody()
        {
            return new Dictionary<string, object>
            {
                ["format"] = Format,
                ["wins_needed"] = WinsNeeded,
                ["deck_type"] = DeckType,
                ["default_selections_mode"] = DefaultSelectionsMode,
            };
        }
    }
}
