using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>One puzzle in the collection, with how you've done at it.</summary>
    public class PuzzleInfo
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("slug")]
        public string Slug { get; set; }

        [JsonProperty("title")]
        public string Title { get; set; }

        /// <summary>The puzzle's stated goal. Never a spoiler: it's the same text shown on the board.</summary>
        [JsonProperty("description")]
        public string Description { get; set; }

        /// <summary>"easy", "medium" or "hard".</summary>
        [JsonProperty("difficulty")]
        public string Difficulty { get; set; }

        /// <summary>When set, the goal has to be reached within this many plays, in one unbroken turn.</summary>
        [JsonProperty("max_plays")]
        public int? MaxPlays { get; set; }

        [JsonProperty("solved")]
        public bool Solved { get; set; }

        /// <summary>The fewest plays any solve of yours has taken.</summary>
        [JsonProperty("best_plays")]
        public int? BestPlays { get; set; }

        [JsonProperty("solve_count")]
        public int SolveCount { get; set; }
    }

    /// <summary>GET /puzzles: every active puzzle, easiest first.</summary>
    public class PuzzlesResponse : ApiEnvelope
    {
        [JsonProperty("puzzles")]
        public List<PuzzleInfo> Puzzles { get; set; } = new List<PuzzleInfo>();
    }
}
