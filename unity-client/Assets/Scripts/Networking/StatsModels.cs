using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>Your lifetime totals. The percentages are null until there is a game (or match) to measure.</summary>
    public class UserStats
    {
        [JsonProperty("game_wins")]
        public int GameWins { get; set; }

        [JsonProperty("game_losses")]
        public int GameLosses { get; set; }

        [JsonProperty("game_win_percentage")]
        public double? GameWinPercentage { get; set; }

        [JsonProperty("match_wins")]
        public int MatchWins { get; set; }

        [JsonProperty("match_losses")]
        public int MatchLosses { get; set; }

        [JsonProperty("match_win_percentage")]
        public double? MatchWinPercentage { get; set; }
    }

    /// <summary>GET /user/stats.</summary>
    public class UserStatsResponse : ApiEnvelope
    {
        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("stats")]
        public UserStats Stats { get; set; }

        /// <summary>The Weekly Sealed Pool weeks you've finished, newest first.</summary>
        [JsonProperty("prior_weekly_sealed_pool_events")]
        public List<WeeklySealedEvent> PriorWeeklySealedPoolEvents { get; set; } = new List<WeeklySealedEvent>();
    }

    /// <summary>One past Weekly Sealed Pool week: your record in it and where that placed you.</summary>
    public class WeeklySealedEvent
    {
        [JsonProperty("period_start")]
        public string PeriodStart { get; set; }

        [JsonProperty("wins")]
        public int Wins { get; set; }

        [JsonProperty("losses")]
        public int Losses { get; set; }

        /// <summary>You finished in the top this many percent.</summary>
        [JsonProperty("percentile")]
        public int Percentile { get; set; }
    }

    /// <summary>One achievement, with your progress at it. A hidden one reads "???" until it's unlocked.</summary>
    public class Achievement
    {
        [JsonProperty("slug")]
        public string Slug { get; set; }

        [JsonProperty("title")]
        public string Title { get; set; }

        [JsonProperty("description")]
        public string Description { get; set; }

        /// <summary>"Bronze", "Silver", "Gold", "Platinum" or "Diamond".</summary>
        [JsonProperty("tier")]
        public string Tier { get; set; }

        /// <summary>What <see cref="Progress"/> counts up to; null for an all-or-nothing achievement.</summary>
        [JsonProperty("target")]
        public int? Target { get; set; }

        [JsonProperty("progress")]
        public int Progress { get; set; }

        /// <summary>When it was unlocked (server time, sorts as text); null while locked.</summary>
        [JsonProperty("unlocked_at")]
        public string UnlockedAt { get; set; }

        [JsonProperty("hidden")]
        public bool Hidden { get; set; }

        public bool Unlocked => UnlockedAt != null;
    }

    /// <summary>GET /user/achievements: the whole catalog by category letter.</summary>
    public class AchievementsResponse : ApiEnvelope
    {
        [JsonProperty("achievements")]
        [JsonConverter(typeof(PhpMapConverter<string, List<Achievement>>))]
        public Dictionary<string, List<Achievement>> Achievements { get; set; } = new Dictionary<string, List<Achievement>>();
    }
}
