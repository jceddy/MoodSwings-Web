using System.Collections.Generic;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Networking
{
    /// <summary>
    /// A card as it appears in a hand, in play, or in the discard pile. One
    /// shape serves all three; fields that don't apply to a zone are null.
    /// Modeled: what the board draws, plus what playing the card asks for (its
    /// choice fields). The server also sends chaos-draft effects and more that
    /// later phases will need.
    /// </summary>
    public class BoardCard
    {
        /// <summary>This copy of the card in this game (not the same as <see cref="CatalogCardId"/>).</summary>
        [JsonProperty("card_id")]
        public int CardId { get; set; }

        /// <summary>Which printed card it is; also the card-art key.</summary>
        [JsonProperty("catalog_card_id")]
        public int CatalogCardId { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        [JsonProperty("color")]
        public string Color { get; set; }

        [JsonProperty("base_color")]
        public string BaseColor { get; set; }

        /// <summary>The value right now, after effects. The art only shows the printed one.</summary>
        [JsonProperty("value")]
        public int Value { get; set; }

        [JsonProperty("base_value")]
        public int BaseValue { get; set; }

        /// <summary>The value this card has under its alternate condition; null for cards that have none.</summary>
        [JsonProperty("alt_value")]
        public int? AltValue { get; set; }

        [JsonProperty("rules_text")]
        public string RulesText { get; set; }

        [JsonProperty("effect_key")]
        public string EffectKey { get; set; }

        [JsonProperty("is_playable")]
        public bool IsPlayable { get; set; }

        /// <summary>In play only: this mood still has a play grant (Hope, Grace) that is lost if it leaves play.</summary>
        [JsonProperty("has_unused_play_grant")]
        public bool HasUnusedPlayGrant { get; set; }

        /// <summary>In play only: Creativity, playing as a copy of another mood.</summary>
        [JsonProperty("is_creativity_copy")]
        public bool IsCreativityCopy { get; set; }

        /// <summary>The printed value has an alternate (dice) value; some effects can apply it to this card.</summary>
        [JsonProperty("has_dice_value")]
        public bool HasDiceValue { get; set; }

        /// <summary>A hand card only: what playing it asks the player to choose. Empty when it asks nothing.</summary>
        [JsonProperty("choice_fields")]
        public List<ChoiceField> ChoiceFields { get; set; } = new List<ChoiceField>();

        /// <summary>
        /// Creativity only, in hand: what it would ask for if it copied each mood
        /// in play, by that mood's card id. Null for every other card.
        /// </summary>
        [JsonProperty("copy_simulation")]
        [JsonConverter(typeof(PhpMapConverter<int, CopySimulation>))]
        public Dictionary<int, CopySimulation> CopySimulation { get; set; }

        /// <summary>In play only: another effect is switching this mood's own ability off.</summary>
        [JsonProperty("is_suppressed")]
        public bool IsSuppressed { get; set; }

        /// <summary>In play only: what is suppressing this mood (it can be more than one), and until when.</summary>
        [JsonProperty("suppressions")]
        public List<Suppression> Suppressions { get; set; } = new List<Suppression>();

        [JsonProperty("value_locked")]
        public bool ValueLocked { get; set; }

        /// <summary>In play only: whose mood it is (the seat it sits in front of).</summary>
        [JsonProperty("owner_game_player_id")]
        public int? OwnerGamePlayerId { get; set; }

        /// <summary>Discard pile only: whose it was.</summary>
        [JsonProperty("last_owner_game_player_id")]
        public int? LastOwnerGamePlayerId { get; set; }

        [JsonProperty("last_owner_name")]
        public string LastOwnerName { get; set; }

        /// <summary>True when an effect has moved the value away from the printed one.</summary>
        public bool ValueIsModified => Value != BaseValue;
    }

    /// <summary>One thing suppressing a mood: a suppressed mood's value is 0; nothing else about it changes.</summary>
    public class Suppression
    {
        [JsonProperty("suppressed_by_card_id")]
        public int? SuppressedByCardId { get; set; }

        [JsonProperty("suppressed_by_name")]
        public string SuppressedByName { get; set; }

        /// <summary>When it wears off: end_of_round, or while_source_in_play.</summary>
        [JsonProperty("expiry")]
        public string Expiry { get; set; }
    }

    public class BoardPlayer
    {
        [JsonProperty("game_player_id")]
        public int GamePlayerId { get; set; }

        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        /// <summary>Turn order; ascending seat order is clockwise around the table.</summary>
        [JsonProperty("seat_order")]
        public int SeatOrder { get; set; }

        [JsonProperty("is_bot")]
        public bool IsBot { get; set; }

        /// <summary>Opponents' hands are hidden: only how many cards.</summary>
        [JsonProperty("hand_count")]
        public int HandCount { get; set; }

        [JsonProperty("deck_count")]
        public int DeckCount { get; set; }

        /// <summary>Points across the rounds so far.</summary>
        [JsonProperty("total_score")]
        public int TotalScore { get; set; }

        /// <summary>Rounds won so far, towards the game's wins needed.</summary>
        [JsonProperty("total_wins")]
        public int TotalWins { get; set; }

        [JsonProperty("resigned")]
        public bool Resigned { get; set; }

        [JsonProperty("ready")]
        public bool Ready { get; set; }

        /// <summary>"online", "offline" or "hidden".</summary>
        [JsonProperty("presence")]
        public string Presence { get; set; }

        [JsonProperty("team_id")]
        public int? TeamId { get; set; }

        /// <summary>
        /// Synchronous games: how many timeouts this player can still absorb
        /// before the clock really counts against them.
        /// </summary>
        [JsonProperty("timeout_extensions_banked")]
        public int TimeoutExtensionsBanked { get; set; }
    }

    /// <summary>The viewer. A spectator has no seat, so the id and hand may be absent.</summary>
    public class BoardViewer
    {
        [JsonProperty("game_player_id")]
        public int? GamePlayerId { get; set; }

        [JsonProperty("user_id")]
        public int? UserId { get; set; }

        [JsonProperty("hand")]
        public List<BoardCard> Hand { get; set; } = new List<BoardCard>();

        [JsonProperty("is_your_turn")]
        public bool IsYourTurn { get; set; }

        /// <summary>Open Team Play only: the viewer's partner.</summary>
        [JsonProperty("teammate_game_player_id")]
        public int? TeammateGamePlayerId { get; set; }

        /// <summary>The "Advance Turn" pause: the new turn is waiting for you to acknowledge it.</summary>
        [JsonProperty("turn_pending_acknowledgment")]
        public bool TurnPendingAcknowledgment { get; set; }
    }

    /// <summary>A choice some card effect is waiting on a player to make.</summary>
    public class PendingDecision
    {
        [JsonProperty("decision_type")]
        public string DecisionType { get; set; }

        [JsonProperty("is_you")]
        public bool IsYou { get; set; }

        [JsonProperty("initiating_game_player_id")]
        public int? InitiatingGamePlayerId { get; set; }

        [JsonProperty("target_game_player_id")]
        public int? TargetGamePlayerId { get; set; }

        [JsonProperty("played_card_id")]
        public int? PlayedCardId { get; set; }

        [JsonProperty("played_card_name")]
        public string PlayedCardName { get; set; }

        /// <summary>What to ask the player for. Only present for the player being asked.</summary>
        [JsonProperty("field")]
        public ChoiceField Field { get; set; }
    }

    public class BoardRound
    {
        [JsonProperty("round_number")]
        public int RoundNumber { get; set; }

        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("current_turn_game_player_id")]
        public int? CurrentTurnGamePlayerId { get; set; }

        [JsonProperty("first_game_player_id")]
        public int? FirstGamePlayerId { get; set; }

        /// <summary>Set in games of 3 or more: whoever has the Hurt Feelings card this round.</summary>
        [JsonProperty("hurt_feelings_game_player_id")]
        public int? HurtFeelingsGamePlayerId { get; set; }

        [JsonProperty("plays_remaining")]
        public int PlaysRemaining { get; set; }

        /// <summary>Visible to everyone: the current turn holder is waiting to acknowledge the start of their turn.</summary>
        [JsonProperty("turn_pending_acknowledgment")]
        public bool TurnPendingAcknowledgment { get; set; }

        /// <summary>Moods in play whose abilities change how this round will be scored.</summary>
        [JsonProperty("scoring_effects")]
        public List<EffectNote> ScoringEffects { get; set; } = new List<EffectNote>();

        /// <summary>Moods in play changing the board as a whole (a declared color, a color ban).</summary>
        [JsonProperty("board_effects")]
        public List<EffectNote> BoardEffects { get; set; } = new List<EffectNote>();

        [JsonProperty("banned_colors")]
        public List<string> BannedColors { get; set; } = new List<string>();

        [JsonProperty("pending_decision")]
        public PendingDecision PendingDecision { get; set; }

        /// <summary>What each player would score if the round ended now; only while a scoring-time decision is outstanding.</summary>
        [JsonProperty("scoring_preview")]
        public ScoringPreview ScoringPreview { get; set; }
    }

    /// <summary>One line describing an effect that's currently in force.</summary>
    public class EffectNote
    {
        [JsonProperty("description")]
        public string Description { get; set; }
    }

    public class ScoringPreview
    {
        /// <summary>Score so far by game_player_id.</summary>
        [JsonProperty("scores")]
        [JsonConverter(typeof(PhpMapConverter<int, int>))]
        public Dictionary<int, int> Scores { get; set; } = new Dictionary<int, int>();

        [JsonProperty("sneakiness_swaps")]
        public List<ScoreSwap> SneakinessSwaps { get; set; } = new List<ScoreSwap>();
    }

    public class ScoreSwap
    {
        [JsonProperty("game_player_id")]
        public int GamePlayerId { get; set; }

        [JsonProperty("swaps_with_game_player_id")]
        public int SwapsWithGamePlayerId { get; set; }
    }

    /// <summary>A repeated board state this turn; repeating it again ends the turn.</summary>
    public class LoopWarning
    {
        [JsonProperty("game_player_id")]
        public int GamePlayerId { get; set; }

        [JsonProperty("occurrence_count")]
        public int OccurrenceCount { get; set; }
    }

    public class BoardGameInfo
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("format")]
        public string Format { get; set; }

        [JsonProperty("deck_type")]
        public string DeckType { get; set; }

        [JsonProperty("wins_needed")]
        public int WinsNeeded { get; set; }

        [JsonProperty("winner_game_player_id")]
        public int? WinnerGamePlayerId { get; set; }

        [JsonProperty("winner_usernames")]
        public List<string> WinnerUsernames { get; set; } = new List<string>();

        [JsonProperty("synchronous_mode")]
        public bool SynchronousMode { get; set; }

        /// <summary>Synchronous games: when the player on the clock runs out of time, "yyyy-MM-dd HH:mm:ss" in UTC.</summary>
        [JsonProperty("action_deadline_at")]
        public string ActionDeadlineAt { get; set; }

        [JsonProperty("action_deadline_game_player_id")]
        public int? ActionDeadlineGamePlayerId { get; set; }

        /// <summary>Set only for the player it's about, once the same board state has repeated this turn.</summary>
        [JsonProperty("loop_warning")]
        public LoopWarning LoopWarning { get; set; }
    }

    public class BoardEvent
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }

        [JsonProperty("description")]
        public string Description { get; set; }
    }

    public class BoardChatMessage
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("sender_username")]
        public string SenderUsername { get; set; }

        [JsonProperty("message_text")]
        public string MessageText { get; set; }

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }
    }

    /// <summary>POST /games/spectate/resolve: the game a spectate code belongs to.</summary>
    public class SpectateResolveResponse : ApiEnvelope
    {
        [JsonProperty("game_id")]
        public int GameId { get; set; }
    }

    /// <summary>
    /// GET /games/state (and /games/spectate/state, which has the same shape):
    /// everything needed to draw a game. The server also sends large blocks
    /// for draft and team formats; those aren't modeled yet.
    /// </summary>
    public class GameState : ApiEnvelope
    {
        [JsonProperty("game")]
        public BoardGameInfo Game { get; set; } = new BoardGameInfo();

        [JsonProperty("round")]
        public BoardRound Round { get; set; } = new BoardRound();

        [JsonProperty("you")]
        public BoardViewer You { get; set; } = new BoardViewer();

        [JsonProperty("players")]
        public List<BoardPlayer> Players { get; set; } = new List<BoardPlayer>();

        [JsonProperty("in_play")]
        public List<BoardCard> InPlay { get; set; } = new List<BoardCard>();

        [JsonProperty("discard_pile")]
        public List<BoardCard> DiscardPile { get; set; } = new List<BoardCard>();

        [JsonProperty("deck_count")]
        public int DeckCount { get; set; }

        /// <summary>Newest first.</summary>
        [JsonProperty("recent_events")]
        public List<BoardEvent> RecentEvents { get; set; } = new List<BoardEvent>();

        [JsonProperty("chat_messages")]
        public List<BoardChatMessage> ChatMessages { get; set; } = new List<BoardChatMessage>();

        // The three below are present (non-null) only for formats and moments this client
        // can't play yet; BoardDisplay.UnsupportedReason() reads them.

        /// <summary>Game 2 or 3 of a match: who goes first is still being decided.</summary>
        [JsonProperty("first_player_decision")]
        public JToken FirstPlayerDecision { get; set; }

        /// <summary>Team play: the partners are choosing who acts.</summary>
        [JsonProperty("team_decision")]
        public JToken TeamDecision { get; set; }

        /// <summary>Team play: the opening card pass between partners.</summary>
        [JsonProperty("initial_card_pass")]
        public JToken InitialCardPass { get; set; }
    }

    /// <summary>
    /// What a successful play, pass, response, resignation or turn change
    /// reports. The board itself is read afresh from GET /games/state.
    /// </summary>
    public class GameActionResponse : ApiEnvelope
    {
        [JsonProperty("round_scored")]
        public bool RoundScored { get; set; }

        [JsonProperty("game_completed")]
        public bool GameCompleted { get; set; }

        [JsonProperty("winner_game_player_id")]
        public int? WinnerGamePlayerId { get; set; }

        /// <summary>The action stopped on a question for some player.</summary>
        [JsonProperty("pending_decision")]
        public bool PendingDecision { get; set; }
    }
}
