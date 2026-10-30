using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>The League event that is running (or, on the leaderboard, one that has ended).</summary>
    public class LeagueEvent
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        /// <summary>The set the event is about, when it has one.</summary>
        [JsonProperty("set_code")]
        public string SetCode { get; set; }

        /// <summary>"active" or "ended"; only the leaderboard says.</summary>
        [JsonProperty("status")]
        public string Status { get; set; }
    }

    /// <summary>One of your League matches, a best-of-three against someone from the queue.</summary>
    public class LeagueMatchRow
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        /// <summary>The match's latest game, to open it; null when it has none.</summary>
        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        [JsonProperty("opponent_username")]
        public string OpponentUsername { get; set; }

        /// <summary>"in_progress" or "completed".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        /// <summary>Whether you won it; null until it's over.</summary>
        [JsonProperty("won")]
        public bool? Won { get; set; }

        public bool InProgress => Status == "in_progress";
    }

    public class LeagueCollectionSummary
    {
        [JsonProperty("unique_cards")]
        public int UniqueCards { get; set; }

        [JsonProperty("total_cards")]
        public int TotalCards { get; set; }
    }

    /// <summary>What a League deck must look like.</summary>
    public class LeagueDeckRulesInfo
    {
        [JsonProperty("min_cards")]
        public int MinCards { get; set; }

        [JsonProperty("max_mythics")]
        public int MaxMythics { get; set; }

        [JsonProperty("max_rares")]
        public int MaxRares { get; set; }
    }

    /// <summary>GET /league: the running event (null when there is none), whether you're in it and queued, your record and matches.</summary>
    public class LeagueStatus : ApiEnvelope
    {
        [JsonProperty("event")]
        public LeagueEvent Event { get; set; }

        /// <summary>You've joined this event (and so were granted your starter deck).</summary>
        [JsonProperty("joined")]
        public bool Joined { get; set; }

        /// <summary>You're waiting in the queue for an opponent.</summary>
        [JsonProperty("queued")]
        public bool Queued { get; set; }

        [JsonProperty("in_progress_count")]
        public int InProgressCount { get; set; }

        /// <summary>You can have this many League matches going at once.</summary>
        [JsonProperty("concurrent_match_cap")]
        public int ConcurrentMatchCap { get; set; }

        [JsonProperty("wins")]
        public int Wins { get; set; }

        [JsonProperty("losses")]
        public int Losses { get; set; }

        /// <summary>Newest first.</summary>
        [JsonProperty("matches")]
        public List<LeagueMatchRow> Matches { get; set; } = new List<LeagueMatchRow>();

        [JsonProperty("collection")]
        public LeagueCollectionSummary Collection { get; set; } = new LeagueCollectionSummary();

        [JsonProperty("deck_rules")]
        public LeagueDeckRulesInfo DeckRules { get; set; } = new LeagueDeckRulesInfo();
    }

    /// <summary>POST /league/join: "paired" with a game made at once, or "waiting" in the queue.</summary>
    public class LeagueJoinResponse : ApiEnvelope
    {
        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        [JsonProperty("opponent_username")]
        public string OpponentUsername { get; set; }

        public bool Paired => Status == "paired" && GameId.HasValue;
    }

    /// <summary>GET /league/leaderboard: null event when there is no such event (and then no standings).</summary>
    public class LeagueLeaderboardResponse : ApiEnvelope
    {
        [JsonProperty("event")]
        public LeagueEvent Event { get; set; }

        [JsonProperty("standings")]
        public List<WeeklyStandingRow> Standings { get; set; } = new List<WeeklyStandingRow>();
    }

    /// <summary>GET /league/collection: your cards, a copy count each, in color, rarity, name order.</summary>
    public class LeagueCollectionResponse : ApiEnvelope
    {
        [JsonProperty("cards")]
        public List<BoardCard> Cards { get; set; } = new List<BoardCard>();
    }

    /// <summary>
    /// A League game that is waiting on your deck (<c>league_deck_builder</c> in the game's state): the cards you may use,
    /// with how many copies of each. Game 1 offers your whole collection and a small sideboard; from game 2 the pool is
    /// fixed at the deck and sideboard you declared then, and swapping is only ever between the two.
    /// </summary>
    public class LeagueDeckBuilder
    {
        [JsonProperty("pool")]
        public List<BoardCard> Pool { get; set; } = new List<BoardCard>();

        /// <summary>
        /// The deck to start from, one id per copy: your last game's; on game 1, the deck you submitted for game 1 of your
        /// last League match (before any sideboarding); or, for a first-ever match, the starter deck you were granted.
        /// </summary>
        [JsonProperty("default_deck_card_ids")]
        public List<int> DefaultDeckCardIds { get; set; } = new List<int>();

        /// <summary>Game 1 only: the sideboard you declared with that carried-over deck; empty otherwise (and from an older server).</summary>
        [JsonProperty("default_sideboard_card_ids")]
        public List<int> DefaultSideboardCardIds { get; set; } = new List<int>();

        [JsonProperty("min_cards")]
        public int MinCards { get; set; }

        /// <summary>The most mythics and rares a deck may have.</summary>
        [JsonProperty("rarity_limits")]
        [JsonConverter(typeof(PhpMapConverter<string, int>))]
        public Dictionary<string, int> RarityLimits { get; set; } = new Dictionary<string, int>();

        /// <summary>How many cards the sideboard may hold (0 once the pool is fixed).</summary>
        [JsonProperty("sideboard_max")]
        public int SideboardMax { get; set; }

        /// <summary>Game 2 or 3: the pool is the deck and sideboard from game 1; whatever the deck leaves over is the sideboard.</summary>
        [JsonProperty("fixed_pool")]
        public bool FixedPool { get; set; }
    }
}
