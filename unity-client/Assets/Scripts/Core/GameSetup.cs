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
    /// finished game for a rematch. Scoped to the ready-made deck types and the
    /// drafts the app can play; custom decklists arrive in a later phase, and
    /// rematch is only offered for games this can express.
    /// </summary>
    public sealed class GameSetup
    {
        public const string TraditionalFormat = "standard";

        /// <summary>Like Traditional, but each player draws from a deck of their own.</summary>
        public const string DuelFormat = "duel";

        /// <summary>The format every draft and sealed game has: each player builds a deck of their own from a pool.</summary>
        public const string DraftFormat = "draft";

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
            new DeckOption
            {
                Id = DraftFormat,
                Label = "Draft",
                Description = "Everyone drafts a pool of cards and builds a deck from it. Best of three. 2 to 4 players.",
            },
        };

        /// <summary>The draft types the app can play, with what each is.</summary>
        public static readonly IReadOnlyList<DeckOption> DraftDeckOptions = new List<DeckOption>
        {
            new DeckOption
            {
                Id = "quick_draft",
                Label = "Quick Draft",
                Description = "Piles of cards are passed around the table; keep 2 from each, then build a deck from what you kept.",
            },
            new DeckOption
            {
                Id = "winston_draft",
                Label = "Winston Draft",
                Description = "Three piles that grow: look at each in turn and take it, or pass to see the next. Then build a deck.",
            },
            new DeckOption
            {
                Id = "grid_draft",
                Label = "Grid Draft",
                Description = "A face-up grid of cards; take a whole row or column on your turn. Then build a deck.",
            },
            new DeckOption
            {
                Id = "rotisserie_draft",
                Label = "Rotisserie Draft",
                Description = "One shared pool, laid out face up; take turns choosing a single card. Then build a deck.",
            },
            new DeckOption
            {
                Id = "tiered_rotisserie_draft",
                Label = "Tiered Rotisserie Draft",
                Description = "Rotisserie in stages by rarity: the Mythics first, then the Rares, and so on. Then build a deck.",
            },
            new DeckOption
            {
                Id = ChaosDraft,
                Label = "Chaos Draft",
                Description = "Quick Draft, but each round everyone is offered a choice of two random effects to attach to a card in their hand. (Needs \"Show custom card/effect formats\" in Settings.)",
            },
            new DeckOption
            {
                Id = SealedDeck,
                Label = "Sealed Deck",
                Description = "No drafting: each player is dealt a random 45-card pool and builds a deck of 12 or more from it.",
            },
            new DeckOption
            {
                Id = SealedPoolOfTheDay,
                Label = "Sealed Pool of the Day",
                Description = "Two players, both dealt the same 50-card pool (the day's, shared by every such game). A deck of 12 or more with at most 4 Rares and 2 Mythics.",
            },
        };

        public const string ChaosDraft = "chaos_draft";

        public const string SealedDeck = "sealed_deck";

        public const string SealedPoolOfTheDay = "sealed_pool_of_the_day";

        public const int MinRotisserieCutoff = 13;

        public const int MaxRotisserieCutoff = 20;

        public const int DefaultRotisserieCutoff = 14;

        /// <summary>Where a draft's cards come from.</summary>
        public static readonly IReadOnlyList<DeckOption> PoolSourceOptions = new List<DeckOption>
        {
            new DeckOption { Id = "random_48", Label = "Random 48", Description = "48 cards chosen at random, no repeats." },
            new DeckOption { Id = "structure", Label = "Structure", Description = "A random box's worth of cards, with its rarity mix (doubled for 3 or 4 players)." },
            new DeckOption { Id = "jceddys_75", Label = "jceddy's 75 Card", Description = "15 per color: a Mythic, two Rares, four Uncommons and eight Commons each." },
            new DeckOption { Id = "one_of_each", Label = "One of Each Card", Description = "Every card in the set, once each." },
            new DeckOption { Id = SavedDeckSource, Label = "A saved deck", Description = "One of your decks, or a friend's shared one: its cards are the pool, with all their copies." },
        };

        public const string SavedDeckSource = "saved_deck";

        /// <summary>The deck a game with a custom deck plays: one saved deck shared by the table.</summary>
        public static readonly DeckOption CustomDeckOption = new DeckOption
        {
            Id = CustomDeck,
            Label = "Custom Deck",
            Description = "One saved deck of yours (or a friend's) as the table's shared deck: at least 15 cards, and 15 more for each player beyond two.",
        };

        /// <summary>Duel only: each player brings a saved deck of their own, built to the rules chosen.</summary>
        public static readonly DeckOption CustomDuelOption = new DeckOption
        {
            Id = CustomDuel,
            Label = "Custom Decklists (Duel)",
            Description = "Each player chooses a saved deck of their own once the game is made, built to the rules below.",
        };

        public const string CustomDeck = "custom";

        public const string CustomDuel = "custom_duel";

        /// <summary>The deck-building rules a custom duel can be played under.</summary>
        public static readonly IReadOnlyList<DeckOption> DuelRulePresets = new List<DeckOption>
        {
            new DeckOption { Id = "structure", Label = "Structure rules", Description = "45 cards in a box's rarity mix, no repeats." },
            new DeckOption { Id = "power", Label = "Power Duel", Description = "A small fast deck: one Mythic and the rest limited. Can be sideboarded between games." },
            new DeckOption { Id = "jceddys_75", Label = "jceddy's 75 Card rules", Description = "75 cards, 15 per color, in the same rarity mix." },
        };

        public const string DefaultDuelPreset = "structure";

        /// <summary>A game's custom deck needs this many cards for two players, and as many again for each further player.</summary>
        public const int CustomDeckCardsPerPlayer = 15;

        public const string DefaultPoolSource = "random_48";

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

        /// <summary>Which pool a draft deals from.</summary>
        public string PoolSource { get; set; } = DefaultPoolSource;

        /// <summary>The saved deck a custom game plays, or a draft deals its pool from (and how many cards it holds).</summary>
        public int? SavedDecklistId { get; set; }

        public int SavedDeckCardCount { get; set; }

        /// <summary>Custom duel: the deck-building rules.</summary>
        public string DuelPreset { get; set; } = DefaultDuelPreset;

        /// <summary>Custom duel under the Power rules: a match lets players change their deck from its card pool between games.</summary>
        public bool AllowSideboarding { get; set; }

        /// <summary>Which of the opponents are practice bots; a bot can't pick its own deck for a custom duel, so the creator does.</summary>
        public ISet<int> BotUserIds { get; set; } = new HashSet<int>();

        /// <summary>Custom duel: the saved deck each practice bot plays, by the bot's user id.</summary>
        public Dictionary<int, int> BotDecklistIds { get; set; } = new Dictionary<int, int>();

        /// <summary>Rotisserie Draft: how many cards each player picks before building a deck.</summary>
        public int RotisserieCutoff { get; set; } = DefaultRotisserieCutoff;

        /// <summary>A draft game: each player drafts or is dealt a pool and builds a deck of their own.</summary>
        public bool IsDraftFormat => Format == DraftFormat;

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
            !IsDraftDeck && (IsTeamFormat || ((Format == TraditionalFormat || Format == DuelFormat) && PlayerCount == 2));

        /// <summary>A drafted deck: always a best-of-three match of its own, so the option isn't offered.</summary>
        public bool IsDraftDeck => DraftDeckOptions.Any(d => d.Id == DeckType);

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
            Format == DuelFormat || IsTwoPlayerOnly ? MinPlayers : IsTeamFormat ? TeamPlayerCount : OpenLobbyPlayerCount;

        /// <summary>Whether the lobby listing's player count is the creator's to choose.</summary>
        public bool OpenLobbyCountIsChoosable => (Format == TraditionalFormat || Format == DraftFormat) && !IsTwoPlayerOnly;

        /// <summary>
        /// Which decks the current format can use: the ready-made ones, except that the 15-card Power deck is too
        /// small for a team game (each team of two needs enough cards to last).
        /// </summary>
        public IReadOnlyList<DeckOption> DecksForFormat =>
            (IsDraftFormat ? DraftDeckOptions
            : IsTeamFormat ? DeckOptions.Where(d => d.Id != "power").Append(CustomDeckOption).Concat(DraftDeckOptions.Where(d => d.Id != SealedPoolOfTheDay)).ToList()
            : Format == DuelFormat ? DeckOptions.Append(CustomDuelOption).ToList()
            : DeckOptions.Append(CustomDeckOption).ToList()).Where(d => AllowCustomContent || d.Id != ChaosDraft).ToList();

        /// <summary>This game plays a saved deck: the table's custom deck, or a draft's pool taken from one.</summary>
        public bool UsesSavedDeck => DeckType == CustomDeck || (UsesPoolSource && PoolSource == SavedDeckSource);

        public bool IsCustomDuel => DeckType == CustomDuel;

        /// <summary>Sideboarding between games applies to a two-player custom duel under the Power rules, played as a match.</summary>
        public bool SideboardingAvailable => IsCustomDuel && DuelPreset == "power" && BestOfThree;

        /// <summary>The player has switched on custom card/effect formats (Settings), which is what offers Chaos Draft.</summary>
        public bool AllowCustomContent { get; set; }

        /// <summary>The deal-from pool is the player's to choose for this deck type (the tiered one is fixed by rarity).</summary>
        public bool UsesPoolSource => IsDraftDeck && DeckType != "tiered_rotisserie_draft" && DeckType != SealedDeck && DeckType != SealedPoolOfTheDay;

        /// <summary>The Sealed Pool of the Day is dealt to exactly two players.</summary>
        public bool IsTwoPlayerOnly => DeckType == SealedPoolOfTheDay;

        /// <summary>How many opponents can be picked: one for a two-player-only deck, otherwise up to a full table.</summary>
        public int MaxOpponents => IsTwoPlayerOnly ? 1 : MaxPlayers - 1;

        public bool UsesRotisserieCutoff => DeckType == "rotisserie_draft";

        /// <summary>The request field that names a draft type's pool ("quick_draft_pool_source" and so on).</summary>
        private string PoolSourceField => DraftDisplay.Kind(DeckType).Replace("_draft", string.Empty) + "_draft_pool_source";

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

            if (PoolSourceOptions.All(p => p.Id != PoolSource))
            {
                PoolSource = DefaultPoolSource;
            }

            if (DuelRulePresets.All(p => p.Id != DuelPreset))
            {
                DuelPreset = DefaultDuelPreset;
            }

            if (!SideboardingAvailable)
            {
                AllowSideboarding = false;
            }

            foreach (var gone in BotDecklistIds.Keys.Where(id => !OpponentUserIds.Contains(id)).ToList())
            {
                BotDecklistIds.Remove(gone);
            }

            if (OpponentUserIds.Count > MaxOpponents)
            {
                OpponentUserIds = OpponentUserIds.Take(MaxOpponents).ToList();
            }

            RotisserieCutoff = System.Math.Max(MinRotisserieCutoff, System.Math.Min(MaxRotisserieCutoff, RotisserieCutoff));

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

        // What's missing from the deck choices that need more than a name: the saved deck, its size, the bots' decks.
        private string ValidateSavedDecks(int playerCount)
        {
            if (UsesSavedDeck)
            {
                if (!SavedDecklistId.HasValue)
                {
                    return DeckType == CustomDeck ? "Pick one of your saved decks to play." : "Pick the saved deck to draft from.";
                }

                var needed = CustomDeckCardsPerPlayer * (playerCount - 1);
                if (DeckType == CustomDeck && SavedDeckCardCount < needed)
                {
                    return $"That deck has {SavedDeckCardCount} cards, but {playerCount} players need at least {needed}.";
                }
            }

            return null;
        }

        /// <summary>Null if these settings can be sent as a direct game, otherwise what to fix.</summary>
        public string ValidateDirectGame()
        {
            if (DecksForFormat.All(d => d.Id != DeckType))
            {
                return "Pick a deck.";
            }

            var savedProblem = ValidateSavedDecks(PlayerCount);
            if (savedProblem != null)
            {
                return savedProblem;
            }

            if (IsCustomDuel && OpponentUserIds.Any(id => BotUserIds.Contains(id) && !BotDecklistIds.ContainsKey(id)))
            {
                return "Pick a saved deck for each practice bot.";
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

            if (IsTwoPlayerOnly && OpponentUserIds.Count != 1)
            {
                return "The Sealed Pool of the Day is for exactly two players: pick one opponent.";
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

            var savedProblem = ValidateSavedDecks(EffectiveOpenLobbyPlayerCount);
            if (savedProblem != null)
            {
                return savedProblem;
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
            if (IsCustomDuel && BotDecklistIds.Count > 0)
            {
                body["bot_decklists"] = BotDecklistIds.ToDictionary(p => p.Key.ToString(), p => (object)new Dictionary<string, object> { ["saved_decklist_id"] = p.Value });
            }

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
            // (A draft isn't here: its cards come from a pool the game's summary doesn't say.)
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
            if (UsesPoolSource)
            {
                body[PoolSourceField] = PoolSource;
            }

            if (UsesSavedDeck && SavedDecklistId.HasValue)
            {
                body["saved_decklist_id"] = SavedDecklistId.Value;
            }

            if (IsCustomDuel)
            {
                body["duel_deck_rules"] = new Dictionary<string, object> { ["preset"] = DuelPreset };
                if (AllowSideboarding && SideboardingAvailable)
                {
                    body["allow_sideboarding"] = true;
                }
            }

            if (UsesRotisserieCutoff)
            {
                body["rotisserie_draft_cutoff_count"] = RotisserieCutoff;
            }

            if (DeckType == "tiered_rotisserie_draft")
            {
                body["tiered_rotisserie_draft_mode"] = "rarity";
            }

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
