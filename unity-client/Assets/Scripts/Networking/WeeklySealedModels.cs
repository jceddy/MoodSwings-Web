using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>GET /weekly-sealed-pool/queue: whether you're waiting for an opponent, and how many of this week's matches are going.</summary>
    public class WeeklyQueueStatus : ApiEnvelope
    {
        [JsonProperty("queued")]
        public bool Queued { get; set; }

        [JsonProperty("in_progress_count")]
        public int InProgressCount { get; set; }

        /// <summary>You can have this many Weekly Sealed Pool matches going at once.</summary>
        [JsonProperty("concurrent_match_cap")]
        public int ConcurrentMatchCap { get; set; }
    }

    /// <summary>POST /weekly-sealed-pool/queue: "paired" with a game made at once, or "waiting" in the queue.</summary>
    public class WeeklyQueueJoinResponse : ApiEnvelope
    {
        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        [JsonProperty("opponent_username")]
        public string OpponentUsername { get; set; }

        public bool Paired => Status == "paired" && GameId.HasValue;
    }

    public class WeeklyStandingRow
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("wins")]
        public int Wins { get; set; }

        [JsonProperty("losses")]
        public int Losses { get; set; }

        /// <summary>Ties share a rank; the next one after a tie skips ahead.</summary>
        [JsonProperty("rank")]
        public int Rank { get; set; }

        /// <summary>You finished in the top this many percent.</summary>
        [JsonProperty("percentile")]
        public int Percentile { get; set; }
    }

    /// <summary>GET /weekly-sealed-pool/standings: null when there was no event that week (prior week only).</summary>
    public class WeeklyStandingsResponse : ApiEnvelope
    {
        [JsonProperty("standings")]
        public List<WeeklyStandingRow> Standings { get; set; }
    }
}
