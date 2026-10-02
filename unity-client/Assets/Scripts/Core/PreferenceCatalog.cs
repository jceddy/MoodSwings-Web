using System;
using System.Collections.Generic;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>One on/off account preference: which route saves it, which JSON key it uses, and where it lives on <see cref="User"/>.</summary>
    public sealed class BoolPreference
    {
        public string Label { get; set; }

        public string Description { get; set; }

        public string Route { get; set; }

        public string JsonKey { get; set; }

        /// <summary>What to show when the server hasn't told us (e.g. the user object came from /login, which omits preferences).</summary>
        public bool DefaultValue { get; set; }

        public Func<User, bool?> Read { get; set; }

        public Action<User, bool> Write { get; set; }
    }

    /// <summary>The preferences the Settings screen offers, in the order and wording the web Settings dialog uses.</summary>
    public static class PreferenceCatalog
    {
        public const string BoardLayoutRoute = "/user/board-layout-preference";
        public const string BoardLayoutKey = "board_layout_preference";
        public const string BoardLayoutAbovePlayArea = "above_play_area";
        public const string BoardLayoutBelowHand = "below_hand";

        public static readonly IReadOnlyList<BoolPreference> GameDefaults = new List<BoolPreference>
        {
            new BoolPreference
            {
                Label = "Default selections mode",
                Description = "Pre-fill card choices with a reasonable default when starting a new game.",
                Route = "/user/default-selections-mode-preference",
                JsonKey = "default_selections_mode_preference",
                DefaultValue = false,
                Read = u => u.DefaultSelectionsModePreference,
                Write = (u, v) => u.DefaultSelectionsModePreference = v,
            },
            new BoolPreference
            {
                Label = "Auto-pass on an empty hand",
                Description = "Pass automatically when your hand is empty on your turn.",
                Route = "/user/auto-pass-on-empty-hand-preference",
                JsonKey = "auto_pass_on_empty_hand",
                DefaultValue = true,
                Read = u => u.AutoPassOnEmptyHand,
                Write = (u, v) => u.AutoPassOnEmptyHand = v,
            },
            new BoolPreference
            {
                Label = "Auto-apply scoring bonuses",
                Description = "Apply Enthusiasm and Passion's scoring bonus automatically.",
                Route = "/user/auto-apply-scoring-bonuses-preference",
                JsonKey = "auto_apply_scoring_bonuses",
                DefaultValue = true,
                Read = u => u.AutoApplyScoringBonuses,
                Write = (u, v) => u.AutoApplyScoringBonuses = v,
            },
            new BoolPreference
            {
                Label = "Pause at the start of your turn",
                Description = "Require Advance Turn before you can act, so you can review what just happened.",
                Route = "/user/pause-before-own-turn-preference",
                JsonKey = "pause_before_own_turn",
                DefaultValue = false,
                Read = u => u.PauseBeforeOwnTurn,
                Write = (u, v) => u.PauseBeforeOwnTurn = v,
            },
            new BoolPreference
            {
                Label = "Show custom card/effect formats",
                Description = "Offer custom formats (such as Chaos Draft) when creating a new game.",
                Route = "/user/allow-custom-content-preference",
                JsonKey = "allow_custom_content",
                DefaultValue = false,
                Read = u => u.AllowCustomContent,
                Write = (u, v) => u.AllowCustomContent = v,
            },
        };

        public static readonly IReadOnlyList<BoolPreference> Privacy = new List<BoolPreference>
        {
            new BoolPreference
            {
                Label = "Share my online status",
                Description = "Let friends and fellow players see whether you're online.",
                Route = "/user/presence-preference",
                JsonKey = "share_presence",
                DefaultValue = true,
                Read = u => u.SharePresence,
                Write = (u, v) => u.SharePresence = v,
            },
            new BoolPreference
            {
                Label = "Discoverable for open games",
                Description = "Let strangers see and join games you post to the open lobby.",
                Route = "/user/matchmaking-discoverable-preference",
                JsonKey = "matchmaking_discoverable",
                DefaultValue = true,
                Read = u => u.MatchmakingDiscoverable,
                Write = (u, v) => u.MatchmakingDiscoverable = v,
            },
        };
    }
}
