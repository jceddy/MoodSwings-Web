using System;
using System.Collections.Generic;
using System.Linq;

namespace MoodSwings.Core
{
    /// <summary>One thing to choose between, with a line saying what it means.</summary>
    public sealed class TournamentChoice
    {
        public string Id { get; set; }

        public string Label { get; set; }

        public string Description { get; set; }
    }

    /// <summary>
    /// A tournament being set up: what is played, how the bracket runs, who may join, and how many. Every match is a
    /// best of three, and a format decides its own deck, so there is little left to choose -- as on the web page. Free of UI.
    /// </summary>
    public sealed class TournamentSetup
    {
        public const int MinPlayers = 4;

        public const int MaxPlayers = 16;

        public const string PowerDuel = "duel";

        public const string Traditional = "standard";

        public const string GridDraft = "grid";

        public const string SealedDeck = "sealed_deck";

        public const string BoosterDraft = "booster_draft";

        public const string Open = "open";

        public const string InviteOnly = "invite_only";

        public static readonly IReadOnlyList<TournamentChoice> Formats = new[]
        {
            new TournamentChoice { Id = PowerDuel, Label = "Power Duel", Description = "Everyone enters one of their own decks, built to the Power Duel rules, and plays it all tournament." },
            new TournamentChoice { Id = Traditional, Label = "Traditional", Description = "One random Structure deck for the whole tournament, shared by everyone." },
            new TournamentChoice { Id = GridDraft, Label = "Grid Draft", Description = "Drafted from a grid, either fresh for every match or once up front in pods." },
            new TournamentChoice { Id = SealedDeck, Label = "Sealed Deck", Description = "Each match is a sealed game: build a deck from a pool dealt just for it." },
            new TournamentChoice { Id = BoosterDraft, Label = "Booster Draft", Description = "Everyone drafts thirty cards from boosters in pods first, then plays the bracket with decks built from them." },
        };

        public static readonly IReadOnlyList<TournamentChoice> GridModes = new[]
        {
            new TournamentChoice { Id = "grid_draft", Label = "Fresh draft each match", Description = "Every bracket match is its own two-player Grid Draft, drafted right before it's played." },
            new TournamentChoice { Id = "grid_draft_pod", Label = "Pod draft (once)", Description = "Players split into pods of up to four and draft once, before the bracket starts. Everyone then plays decks built from their own pool." },
            new TournamentChoice { Id = "grid_draft_pod_playoff", Label = "Pods with playoffs", Description = "Pods draft once, then each plays its own bracket. The pod winners draft again together, and their bracket picks the champion." },
        };

        public static readonly IReadOnlyList<TournamentChoice> Brackets = new[]
        {
            new TournamentChoice { Id = "single_elimination", Label = "Single elimination", Description = "Lose once and you're out." },
            new TournamentChoice { Id = "double_elimination", Label = "Double elimination", Description = "Out after a second loss." },
            new TournamentChoice { Id = "swiss", Label = "Swiss rounds", Description = "Everyone plays every round, paired by record." },
        };

        public static readonly IReadOnlyList<TournamentChoice> Registrations = new[]
        {
            new TournamentChoice { Id = Open, Label = "Open", Description = "Anyone who is discoverable can join." },
            new TournamentChoice { Id = InviteOnly, Label = "Invite only", Description = "Only the friends you invite can join." },
        };

        public string Name { get; set; } = string.Empty;

        public string FormatId { get; set; } = PowerDuel;

        public string GridMode { get; set; } = "grid_draft";

        public string BracketType { get; set; } = "single_elimination";

        public string Registration { get; set; } = Open;

        public int MinParticipants { get; set; } = MinPlayers;

        public int MaxParticipants { get; set; } = 8;

        public List<int> InviteUserIds { get; set; } = new List<int>();

        public bool AllowSideboarding { get; set; }

        /// <summary>The saved deck you enter (Power Duel).</summary>
        public int? SavedDecklistId { get; set; }

        /// <summary>A Power Duel tournament takes a deck from each player as they join.</summary>
        public bool UsesDeck => FormatId == PowerDuel;

        public bool IsGridDraft => FormatId == GridDraft;

        /// <summary>Pods with playoffs run their own single-elimination brackets, whatever the bracket says.</summary>
        public bool IsPlayoffPods => IsGridDraft && GridMode == "grid_draft_pod_playoff";

        public bool IsInviteOnly => Registration == InviteOnly;

        /// <summary>What the server calls the game format: Sealed Deck is a draft game, Booster Draft a duel.</summary>
        public string EffectiveFormat
        {
            get
            {
                switch (FormatId)
                {
                    case SealedDeck: return "draft";
                    case BoosterDraft: return "duel";
                    case GridDraft: return "draft";
                    default: return FormatId;
                }
            }
        }

        public string DeckType
        {
            get
            {
                switch (FormatId)
                {
                    case SealedDeck: return "sealed_deck";
                    case BoosterDraft: return "booster_draft";
                    case GridDraft: return GridMode;
                    case PowerDuel: return "custom_duel";
                    default: return "structure";
                }
            }
        }

        /// <summary>The bracket actually used.</summary>
        public string EffectiveBracketType => IsPlayoffPods ? "single_elimination" : BracketType;

        /// <summary>Keeps the pieces in step after a choice: pod playoffs force single elimination, and min never exceeds max.</summary>
        public void Normalize()
        {
            BracketType = EffectiveBracketType;
            MaxParticipants = Math.Min(MaxPlayers, Math.Max(MinPlayers, MaxParticipants));
            MinParticipants = Math.Min(MaxParticipants, Math.Max(MinPlayers, MinParticipants));
            if (!UsesDeck)
            {
                SavedDecklistId = null;
                AllowSideboarding = false;
            }

            if (!IsInviteOnly)
            {
                InviteUserIds.Clear();
            }
        }

        /// <summary>What stops this from being created, in words; null when it can be.</summary>
        public string Problem()
        {
            if (string.IsNullOrWhiteSpace(Name))
            {
                return "Give the tournament a name.";
            }

            if (UsesDeck && !SavedDecklistId.HasValue)
            {
                return "Choose the deck you will play.";
            }

            // You take one of the seats yourself, so filling an invite-only event takes everyone else being invited.
            if (IsInviteOnly && InviteUserIds.Count < MaxParticipants - 1)
            {
                return $"Invite at least {MaxParticipants - 1} friends to fill this tournament (up to {MaxParticipants} players, counting you).";
            }

            return null;
        }

        public Dictionary<string, object> ToBody()
        {
            Normalize();
            var body = new Dictionary<string, object>
            {
                ["name"] = Name.Trim(),
                ["bracket_type"] = EffectiveBracketType,
                ["registration_mode"] = Registration,
                ["min_participants"] = MinParticipants,
                ["max_participants"] = MaxParticipants,
                ["invite_user_ids"] = IsInviteOnly ? InviteUserIds.ToArray() : new int[0],
                ["format"] = EffectiveFormat,
                ["deck_type"] = DeckType,
                // Every match is a best of three; the server insists whatever is sent, so this just says so.
                ["best_of_three"] = true,
            };

            if (UsesDeck)
            {
                body["duel_deck_rules"] = new Dictionary<string, object> { ["preset"] = "power" };
                body["saved_decklist_id"] = SavedDecklistId.Value;
                if (AllowSideboarding)
                {
                    body["allow_sideboarding"] = true;
                }
            }

            // A grid draft's pool isn't offered; a random one, as the web page sends.
            if (IsGridDraft)
            {
                body["grid_draft_pool_source"] = "random_48";
            }

            return body;
        }

        /// <summary>The line under the form's Create button.</summary>
        public string Summary()
        {
            var format = Formats.First(f => f.Id == FormatId).Label;
            if (IsGridDraft)
            {
                format += " (" + GridModes.First(m => m.Id == GridMode).Label + ")";
            }

            return $"{format}  -  {Brackets.First(b => b.Id == EffectiveBracketType).Label}  -  {MinParticipants} to {MaxParticipants} players";
        }
    }
}
