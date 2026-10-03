using System.Collections.Generic;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Networking
{
    /// <summary>
    /// A draft game's state block: the match so far, and whichever of drafting or deck building is under way. Every draft
    /// type has one of these under its own key in the game state (quick_draft, winston_draft, grid_draft...), all in
    /// this shape except for what "drafting" holds, which differs by type and is read through the typed accessors.
    /// </summary>
    public class DraftMatchState
    {
        [JsonProperty("draft_match_id")]
        public int DraftMatchId { get; set; }

        [JsonProperty("match_game_number")]
        public int? MatchGameNumber { get; set; }

        /// <summary>"drafting", "deck_building" or "completed".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("games_to_win")]
        public int GamesToWin { get; set; }

        /// <summary>Once this game is over and the match goes on, the game that follows it.</summary>
        [JsonProperty("next_game_id")]
        public int? NextGameId { get; set; }

        [JsonProperty("your_wins")]
        public int YourWins { get; set; }

        [JsonProperty("opponent_wins")]
        public int OpponentWins { get; set; }

        [JsonProperty("players")]
        public List<MatchPlayer> Players { get; set; } = new List<MatchPlayer>();

        /// <summary>What is being drafted right now; its shape depends on the draft type. Null outside drafting.</summary>
        [JsonProperty("drafting")]
        public JObject Drafting { get; set; }

        [JsonProperty("deck_building")]
        public DraftDeckBuilding DeckBuilding { get; set; }

        public bool IsDrafting => Status == "drafting" && Drafting != null;

        public bool IsBuildingDeck => Status == "deck_building" && DeckBuilding != null;

        public QuickDrafting AsQuickDrafting() => Drafting?.ToObject<QuickDrafting>();

        public WinstonDrafting AsWinstonDrafting() => Drafting?.ToObject<WinstonDrafting>();

        public GridDrafting AsGridDrafting() => Drafting?.ToObject<GridDrafting>();

        /// <summary>Rotisserie and Tiered Rotisserie share a shape; the tiered one has the tier fields filled in.</summary>
        public RotisserieDrafting AsRotisserieDrafting() => Drafting?.ToObject<RotisserieDrafting>();
    }

    /// <summary>The deck-building stage that follows a draft (and is all there is to a sealed game): choose a deck from your pool.</summary>
    public class DraftDeckBuilding
    {
        /// <summary>The pool to choose from. In Open Team Play it includes your partner's unclaimed picks.</summary>
        [JsonProperty("drafted_cards")]
        public List<BoardCard> DraftedCards { get; set; } = new List<BoardCard>();

        /// <summary>The deck you submitted, if you have.</summary>
        [JsonProperty("deck_card_ids")]
        public List<int> DeckCardIds { get; set; }

        /// <summary>The deck you played the previous game of the match with, to start from.</summary>
        [JsonProperty("previous_deck_card_ids")]
        public List<int> PreviousDeckCardIds { get; set; }

        [JsonProperty("min_deck_size")]
        public int MinDeckSize { get; set; }

        [JsonProperty("max_deck_size")]
        public int MaxDeckSize { get; set; }

        [JsonProperty("you_submitted")]
        public bool YouSubmitted { get; set; }

        [JsonProperty("opponent_submitted")]
        public bool OpponentSubmitted { get; set; }

        [JsonProperty("other_players")]
        public List<DeckSubmission> OtherPlayers { get; set; } = new List<DeckSubmission>();

        /// <summary>Most of a rarity the deck may hold (Sealed Pool of the Day); null when there are no caps.</summary>
        [JsonProperty("rarity_caps")]
        [JsonConverter(typeof(PhpMapConverter<string, int>))]
        public Dictionary<string, int> RarityCaps { get; set; }

        /// <summary>Open Team Play: the cards you and your partner drafted, shared.</summary>
        [JsonProperty("team_drafted_cards")]
        public TeamDraftedCards TeamDraftedCards { get; set; }
    }

    public class DeckSubmission
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("submitted")]
        public bool Submitted { get; set; }
    }

    /// <summary>Open Team Play shows both partners' drafted cards to each other.</summary>
    public class TeamDraftedCards
    {
        [JsonProperty("teammate_user_id")]
        public int TeammateUserId { get; set; }

        [JsonProperty("teammate_username")]
        public string TeammateUsername { get; set; }

        [JsonProperty("cards")]
        public List<BoardCard> Cards { get; set; } = new List<BoardCard>();
    }

    /// <summary>Quick Draft (and Chaos Draft): packs of cards passed around the table, keeping two from each.</summary>
    public class QuickDrafting
    {
        [JsonProperty("round")]
        public int Round { get; set; }

        [JsonProperty("total_rounds")]
        public int TotalRounds { get; set; }

        /// <summary>Which step of the round this is; with two players there are two (first pick, second pick).</summary>
        [JsonProperty("stage")]
        public int Stage { get; set; }

        [JsonProperty("total_stages")]
        public int TotalStages { get; set; }

        /// <summary>"left" or "right": which way the pack you pass goes.</summary>
        [JsonProperty("pass_direction")]
        public string PassDirection { get; set; }

        /// <summary>"picking" (choose from the pack) or "awaiting_others" (you've chosen; wait).</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("pack")]
        public List<BoardCard> Pack { get; set; } = new List<BoardCard>();

        [JsonProperty("kept_so_far")]
        public List<BoardCard> KeptSoFar { get; set; } = new List<BoardCard>();

        [JsonProperty("team_drafted_cards")]
        public TeamDraftedCards TeamDraftedCards { get; set; }
    }

    /// <summary>Winston Draft: three piles that grow, looked at one at a time -- take the pile or pass to the next.</summary>
    public class WinstonDrafting
    {
        [JsonProperty("is_your_turn")]
        public bool IsYourTurn { get; set; }

        [JsonProperty("current_turn_username")]
        public string CurrentTurnUsername { get; set; }

        /// <summary>1 to 3: the pile being looked at.</summary>
        [JsonProperty("current_pile_number")]
        public int CurrentPileNumber { get; set; }

        [JsonProperty("pile_sizes")]
        public List<int> PileSizes { get; set; } = new List<int>();

        [JsonProperty("remaining_deck_count")]
        public int RemainingDeckCount { get; set; }

        /// <summary>The pile being looked at; only sent on your turn, since the others' piles are face down.</summary>
        [JsonProperty("current_pile_cards")]
        public List<BoardCard> CurrentPileCards { get; set; } = new List<BoardCard>();

        [JsonProperty("drafted_so_far")]
        public List<BoardCard> DraftedSoFar { get; set; } = new List<BoardCard>();

        [JsonProperty("other_players")]
        public List<WinstonOtherPlayer> OtherPlayers { get; set; } = new List<WinstonOtherPlayer>();

        [JsonProperty("team_drafted_cards")]
        public TeamDraftedCards TeamDraftedCards { get; set; }
    }

    public class WinstonOtherPlayer
    {
        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("drafted_card_count")]
        public int DraftedCardCount { get; set; }

        [JsonProperty("last_take_pile_number")]
        public int? LastTakePileNumber { get; set; }

        /// <summary>They passed all three piles and drew from the deck instead.</summary>
        [JsonProperty("last_drew_from_deck")]
        public bool LastDrewFromDeck { get; set; }
    }

    /// <summary>What another player (or team) has drafted so far, which Grid and Rotisserie drafts show openly.</summary>
    public class PlayerDrafted
    {
        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("drafted_so_far")]
        public List<BoardCard> DraftedSoFar { get; set; } = new List<BoardCard>();
    }

    public class TeamDrafted
    {
        [JsonProperty("team_id")]
        public int TeamId { get; set; }

        [JsonProperty("is_your_team")]
        public bool IsYourTeam { get; set; }

        [JsonProperty("member_usernames")]
        public List<string> MemberUsernames { get; set; } = new List<string>();

        [JsonProperty("drafted_so_far")]
        public List<BoardCard> DraftedSoFar { get; set; } = new List<BoardCard>();
    }

    /// <summary>Grid Draft: a face-up grid of cards; each pick takes a whole row or column of what's left.</summary>
    public class GridDrafting
    {
        [JsonProperty("is_your_turn")]
        public bool IsYourTurn { get; set; }

        [JsonProperty("current_turn_username")]
        public string CurrentTurnUsername { get; set; }

        [JsonProperty("current_round")]
        public int CurrentRound { get; set; }

        [JsonProperty("total_rounds")]
        public int TotalRounds { get; set; }

        /// <summary>Cards along each side of the grid.</summary>
        [JsonProperty("grid_size")]
        public int GridSize { get; set; }

        [JsonProperty("picks_this_round")]
        public int PicksThisRound { get; set; }

        [JsonProperty("total_picks_per_round")]
        public int TotalPicksPerRound { get; set; }

        /// <summary>Row by row; null where a card has been taken this round.</summary>
        [JsonProperty("grid_cards")]
        public List<BoardCard> GridCards { get; set; } = new List<BoardCard>();

        [JsonProperty("remaining_deck_count")]
        public int RemainingDeckCount { get; set; }

        [JsonProperty("drafted_so_far")]
        public List<BoardCard> DraftedSoFar { get; set; } = new List<BoardCard>();

        [JsonProperty("other_players_drafted_so_far")]
        public List<PlayerDrafted> OtherPlayersDraftedSoFar { get; set; } = new List<PlayerDrafted>();

        [JsonProperty("teams_drafted_so_far")]
        public List<TeamDrafted> TeamsDraftedSoFar { get; set; }
    }

    /// <summary>
    /// Rotisserie Draft: one shared face-up pool; players take turns picking a single card. Tiered Rotisserie is
    /// the same with a pool per tier, one tier after another (the tier fields are empty for the plain one).
    /// </summary>
    public class RotisserieDrafting
    {
        [JsonProperty("is_your_turn")]
        public bool IsYourTurn { get; set; }

        [JsonProperty("current_turn_username")]
        public string CurrentTurnUsername { get; set; }

        [JsonProperty("cutoff_count")]
        public int CutoffCount { get; set; }

        /// <summary>Plain: picks made in all. Tiered has these as picks_made_this_tier.</summary>
        [JsonProperty("picks_made")]
        public int PicksMade { get; set; }

        [JsonProperty("total_picks_needed")]
        public int TotalPicksNeeded { get; set; }

        [JsonProperty("current_tier_index")]
        public int CurrentTierIndex { get; set; }

        [JsonProperty("current_tier_label")]
        public string CurrentTierLabel { get; set; }

        [JsonProperty("tiers")]
        public List<DraftTier> Tiers { get; set; }

        [JsonProperty("picks_made_this_tier")]
        public int PicksMadeThisTier { get; set; }

        [JsonProperty("total_picks_needed_this_tier")]
        public int TotalPicksNeededThisTier { get; set; }

        [JsonProperty("total_picks_made")]
        public int TotalPicksMade { get; set; }

        [JsonProperty("pool_cards")]
        public List<BoardCard> PoolCards { get; set; } = new List<BoardCard>();

        [JsonProperty("drafted_so_far")]
        public List<BoardCard> DraftedSoFar { get; set; } = new List<BoardCard>();

        [JsonProperty("other_players_drafted_so_far")]
        public List<PlayerDrafted> OtherPlayersDraftedSoFar { get; set; } = new List<PlayerDrafted>();

        [JsonProperty("teams_drafted_so_far")]
        public List<TeamDrafted> TeamsDraftedSoFar { get; set; }

        public bool IsTiered => Tiers != null && Tiers.Count > 0;
    }

    public class DraftTier
    {
        [JsonProperty("label")]
        public string Label { get; set; }

        [JsonProperty("cutoff_count")]
        public int CutoffCount { get; set; }

        /// <summary>"completed", "current" or "upcoming".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }
    }
}
