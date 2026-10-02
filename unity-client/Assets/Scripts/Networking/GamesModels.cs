using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    public class GamePlayerSummary
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("is_bot")]
        public bool IsBot { get; set; }

        [JsonProperty("seat_order")]
        public int SeatOrder { get; set; }
    }

    /// <summary>One entry of GET /games (active) or GET /games/past, as the lobby shows it. Not the full board -- that's GET /games/state.</summary>
    /// <summary>One side of a best-of-three match, with its game wins so far.</summary>
    public class MatchPlayer
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("wins")]
        public int Wins { get; set; }

        [JsonProperty("is_you")]
        public bool IsYou { get; set; }
    }

    /// <summary>
    /// A best-of-three match the game belongs to: first to win two games. Sent with a game in the
    /// lists and in a game's state (where it also says which game to go to next).
    /// </summary>
    public class MatchSummary
    {
        /// <summary>"in_progress" or "completed".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("your_wins")]
        public int YourWins { get; set; }

        [JsonProperty("opponent_wins")]
        public int OpponentWins { get; set; }

        [JsonProperty("games_to_win")]
        public int GamesToWin { get; set; }

        [JsonProperty("winner_usernames")]
        public List<string> WinnerUsernames { get; set; } = new List<string>();

        [JsonProperty("players")]
        public List<MatchPlayer> Players { get; set; } = new List<MatchPlayer>();

        /// <summary>Only in a game's state: once this game is over and the match goes on, the game that follows it.</summary>
        [JsonProperty("next_game_id")]
        public int? NextGameId { get; set; }
    }

    public class GameSummary
    {
        /// <summary>Which game of its match this is (1 to 3); null when it isn't part of one.</summary>
        [JsonProperty("match_game_number")]
        public int? MatchGameNumber { get; set; }

        [JsonProperty("game_match")]
        public MatchSummary GameMatch { get; set; }

        [JsonProperty("id")]
        public int Id { get; set; }

        /// <summary>e.g. "in_progress" or "completed"; other values exist for games still being set up or drafted.</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("format")]
        public string Format { get; set; }

        [JsonProperty("deck_type")]
        public string DeckType { get; set; }

        [JsonProperty("wins_needed")]
        public int WinsNeeded { get; set; }

        [JsonProperty("default_selections_mode")]
        public bool DefaultSelectionsMode { get; set; }

        [JsonProperty("custom_deck_name")]
        public string CustomDeckName { get; set; }

        [JsonProperty("current_turn_username")]
        public string CurrentTurnUsername { get; set; }

        [JsonProperty("is_your_turn")]
        public bool IsYourTurn { get; set; }

        /// <summary>A card effect is waiting on you to choose something, even if it isn't your turn.</summary>
        [JsonProperty("is_awaiting_your_response")]
        public bool IsAwaitingYourResponse { get; set; }

        [JsonProperty("awaiting_response_usernames")]
        public List<string> AwaitingResponseUsernames { get; set; } = new List<string>();

        [JsonProperty("winner_usernames")]
        public List<string> WinnerUsernames { get; set; } = new List<string>();

        [JsonProperty("players")]
        public List<GamePlayerSummary> Players { get; set; } = new List<GamePlayerSummary>();

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }

        [JsonProperty("started_at")]
        public string StartedAt { get; set; }

        [JsonProperty("completed_at")]
        public string CompletedAt { get; set; }

        [JsonProperty("last_move_at")]
        public string LastMoveAt { get; set; }

        public bool IsCompleted => Status == "completed";
    }

    public class GamesResponse : ApiEnvelope
    {
        [JsonProperty("games")]
        public List<GameSummary> Games { get; set; } = new List<GameSummary>();
    }

    /// <summary>A practice bot you can seat as an opponent (GET /games/bots).</summary>
    public class Bot
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        /// <summary>The stronger tactical bots, as opposed to the simple ones.</summary>
        [JsonProperty("uses_tactical_ai")]
        public bool UsesTacticalAi { get; set; }
    }

    /// <summary>A server-side feature switch (GET /config/...).</summary>
    public class FeatureFlagResponse : ApiEnvelope
    {
        [JsonProperty("enabled")]
        public bool Enabled { get; set; }
    }

    public class BotsResponse : ApiEnvelope
    {
        [JsonProperty("bots")]
        public List<Bot> Bots { get; set; } = new List<Bot>();
    }

    public class CreateGameResponse : ApiEnvelope
    {
        [JsonProperty("game_id")]
        public int GameId { get; set; }
    }

    /// <summary>The slice of a listing's create-game settings the lobby shows; the server stores many more.</summary>
    public class OpenGameSettings
    {
        [JsonProperty("format")]
        public string Format { get; set; }

        [JsonProperty("wins_needed")]
        public int WinsNeeded { get; set; }

        [JsonProperty("deck_type")]
        public string DeckType { get; set; }

        [JsonProperty("default_selections_mode")]
        public bool DefaultSelectionsMode { get; set; }
    }

    /// <summary>A game posted to the open lobby that is waiting for enough players.</summary>
    public class OpenGameListing
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("created_by_user_id")]
        public int CreatedByUserId { get; set; }

        [JsonProperty("creator_username")]
        public string CreatorUsername { get; set; }

        [JsonProperty("create_game_params")]
        public OpenGameSettings Settings { get; set; } = new OpenGameSettings();

        /// <summary>Total players the game needs, including the poster.</summary>
        [JsonProperty("target_player_count")]
        public int TargetPlayerCount { get; set; }

        /// <summary>Players who have joined besides the poster.</summary>
        [JsonProperty("joined_count")]
        public int JoinedCount { get; set; }

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }
    }

    public class OpenGamesResponse : ApiEnvelope
    {
        [JsonProperty("listings")]
        public List<OpenGameListing> Listings { get; set; } = new List<OpenGameListing>();
    }

    public class PostOpenGameResponse : ApiEnvelope
    {
        [JsonProperty("listing_id")]
        public int ListingId { get; set; }
    }

    /// <summary>
    /// POST /open-games/join. Unusually, the body's "status" is the outcome
    /// rather than "ok": "waiting" (still short of players: JoinedCount and
    /// TargetPlayerCount are set) or "started" (you were the last one in:
    /// GameId is set).
    /// </summary>
    public class JoinOpenGameResponse : ApiEnvelope
    {
        [JsonProperty("joined_count")]
        public int JoinedCount { get; set; }

        [JsonProperty("target_player_count")]
        public int TargetPlayerCount { get; set; }

        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        public bool Started => Status == "started" && GameId.HasValue;
    }
}
