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
    /// finished game for a rematch. Scoped to Traditional and Duel play with the
    /// ready-made deck types; the other formats (drafts, teams, custom
    /// decklists) arrive in later phases, and rematch is only offered for
    /// games this can express.
    /// </summary>
    public sealed class GameSetup
    {
        public const string TraditionalFormat = "standard";

        /// <summary>Like Traditional, but each player draws from a deck of their own.</summary>
        public const string DuelFormat = "duel";

        /// <summary>Four players as two teams of two, side by side, who see each other's hands.</summary>
        public const string OpenTeamFormat = "team";

        /// <summary>Four players as two teams of two, across the table, with private hands and a card pass to start.</summary>
        public const string ClosedTeamFormat = "closed_team";

        /// <summary>The formats the New Game screen offers, in the order the web dialog lists them.</summary>
        public static readonly IReadOnlyList<DeckOption> FormatOptions = new List<DeckOption>
        {
            new DeckOption
            {
                Id = TraditionalFormat,
                Label = "Traditional",
                Description = "Everyone draws from one shared deck. 2 to 4 players.",
            },
            new DeckOption
            {
                Id = DuelFormat,
                Label = "Duel",
                Description = "Each player gets a deck of their own, built the same way. 2 to 4 players.",
            },
            new DeckOption
            {
                Id = OpenTeamFormat,
                Label = "Open Team Play",
                Description = "4 players as two teams of two, side by side. Partners see each other's hands and score together.",
            },
            new DeckOption
            {
                Id = ClosedTeamFormat,
                Label = "Closed Team Play",
                Description = "4 players as two teams of two, across the table. Hands stay private, but partners pass 2 cards at the start and score together.",
            },
        };

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

        /// <summary>
        /// A live game: both players are at the table, there is a ready check and a 30-second action
        /// clock. Two players only, Traditional or Duel, and only while the server has the feature on.
        /// </summary>
        public bool SynchronousMode { get; set; }

        /// <summary>
        /// First to win two games, with each next game created for you. Two players only, because with more
        /// "the opponent" isn't one person.
        /// </summary>
        public bool BestOfThree { get; set; }

        public bool BestOfThreeAvailable =>
            IsTeamFormat || ((Format == TraditionalFormat || Format == DuelFormat) && PlayerCount == 2);

        /// <summary>Open or Closed Team Play: always four players, as two teams of two.</summary>
        public bool IsTeamFormat => Format == OpenTeamFormat || Format == ClosedTeamFormat;

        /// <summary>Team games seat exactly this many.</summary>
        public const int TeamPlayerCount = 4;

        /// <summary>Which of your opponents is on your team (a direct team game), unless <see cref="RandomTeams"/>.</summary>
        public int? PartnerUserId { get; set; }

        /// <summary>Let the server pick your partner from your opponents.</summary>
        public bool RandomTeams { get; set; }

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

        /// <summary>How many players the game will seat, you included: your opponents, or what the lobby listing waits for.</summary>
        public int PlayerCount => IsTeamFormat ? TeamPlayerCount : PostToOpenLobby ? EffectiveOpenLobbyPlayerCount : 1 + OpponentUserIds.Count;

        /// <summary>
        /// What an open-lobby game waits for. The server seats a Duel with exactly 2 whatever
        /// is asked, so that's what is shown and sent.
        /// </summary>
        public int EffectiveOpenLobbyPlayerCount =>
            Format == DuelFormat ? MinPlayers : IsTeamFormat ? TeamPlayerCount : OpenLobbyPlayerCount;

        /// <summary>Whether the lobby listing's player count is the creator's to choose.</summary>
        public bool OpenLobbyCountIsChoosable => Format == TraditionalFormat;

        /// <summary>
        /// Which decks the current format can use: the ready-made ones, except that the 15-card Power deck is too
        /// small for a team game (each team of two needs enough cards to last).
        /// </summary>
        public IReadOnlyList<DeckOption> DecksForFormat =>
            IsTeamFormat ? DeckOptions.Where(d => d.Id != "power").ToList() : DeckOptions;

        /// <summary>
        /// Whether the synchronous option applies: two players, Traditional or Duel, and the
        /// server's feature switch on (<paramref name="serverAllows"/>).
        /// </summary>
        public bool SynchronousModeAvailable(bool serverAllows) =>
            serverAllows && (Format == TraditionalFormat || Format == DuelFormat) && PlayerCount == 2;

        /// <summary>
        /// Drops options that no longer apply after another choice changed (a third player makes
        /// "synchronous" meaningless), so nothing stale is ever sent.
        /// </summary>
        public void Normalize(bool synchronousModeAllowedByServer)
        {
            if (!SynchronousModeAvailable(synchronousModeAllowedByServer))
            {
                SynchronousMode = false;
            }

            if (!BestOfThreeAvailable)
            {
                BestOfThree = false;
            }

            if (DecksForFormat.All(d => d.Id != DeckType))
            {
                DeckType = DecksForFormat[0].Id;
            }

            if (IsTeamFormat)
            {
                // "Who plays first" is a separate team decision once the game is going, which a bot can't be asked about.
                BotGoesFirst = false;
                if (!OpponentUserIds.Contains(PartnerUserId ?? -1))
                {
                    PartnerUserId = OpponentUserIds.Count > 0 ? OpponentUserIds[0] : (int?)null;
                }
            }
            else
            {
                PartnerUserId = null;
                RandomTeams = false;
            }
        }

        /// <summary>Null if these settings can be sent as a direct game, otherwise what to fix.</summary>
        public string ValidateDirectGame()
        {
            if (DecksForFormat.All(d => d.Id != DeckType))
            {
                return "Pick a deck.";
            }

            if (IsTeamFormat)
            {
                if (OpponentUserIds.Count != TeamPlayerCount - 1)
                {
                    return $"A team game seats {TeamPlayerCount} players: pick exactly {TeamPlayerCount - 1} opponents.";
                }

                if (!RandomTeams && !OpponentUserIds.Contains(PartnerUserId ?? -1))
                {
                    return "Pick your partner from your opponents, or have one chosen at random.";
                }
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
            if (DecksForFormat.All(d => d.Id != DeckType))
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
            if (BotGoesFirst && !IsTeamFormat)
            {
                body["bot_goes_first"] = true;
            }

            if (IsTeamFormat)
            {
                if (RandomTeams)
                {
                    body["random_teams"] = true;
                }
                else
                {
                    body["partner_user_id"] = PartnerUserId;
                }
            }

            return body;
        }

        /// <summary>The POST /open-games body: the same settings, plus how many players the game waits for.</summary>
        public Dictionary<string, object> ToOpenGameBody()
        {
            var body = SharedBody();
            body["target_player_count"] = EffectiveOpenLobbyPlayerCount;
            return body;
        }

        /// <summary>
        /// A rematch of a finished game: the same opponents and settings.
        /// Null when the game used something this can't express yet (another
        /// format, a custom or draft deck), so the caller offers no rematch.
        /// </summary>
        public static GameSetup ForRematch(GameSummary game, int yourUserId)
        {
            if (!FormatOptions.Any(f => f.Id == game.Format) || !SupportsDeck(game.DeckType))
            {
                return null;
            }

            // Partners aren't named in a game's summary, but where they sit says: next to the creator in Open
            // Team Play (seats 0 and 1, 2 and 3), across the table in Closed (0 and 2, 1 and 3).
            int? partner = null;
            var you = game.Players.FirstOrDefault(p => p.UserId == yourUserId);
            if ((game.Format == OpenTeamFormat || game.Format == ClosedTeamFormat) && you != null)
            {
                var partnerSeat = game.Format == OpenTeamFormat ? you.SeatOrder ^ 1 : (you.SeatOrder + 2) % 4;
                partner = game.Players.FirstOrDefault(p => p.SeatOrder == partnerSeat)?.UserId;
            }

            return new GameSetup
            {
                Format = game.Format,
                PartnerUserId = partner,
                BestOfThree = game.GameMatch != null,
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
            var body = new Dictionary<string, object>
            {
                ["format"] = Format,
                ["wins_needed"] = WinsNeeded,
                ["deck_type"] = DeckType,
                ["default_selections_mode"] = DefaultSelectionsMode,
            };
            if (SynchronousMode)
            {
                body["synchronous_mode"] = true;
            }

            if (BestOfThree && BestOfThreeAvailable)
            {
                body["best_of_three"] = true;
            }

            return body;
        }
    }
}
