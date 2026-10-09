using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>The settings every match of a tournament is played with (the same shape as a game's creation request).</summary>
    public class TournamentMatchParams
    {
        /// <summary>"duel", "standard" or "draft".</summary>
        [JsonProperty("format")]
        public string Format { get; set; }

        /// <summary>"custom_duel" (Power Duel), "structure", "sealed_deck", "booster_draft", "grid_draft", "grid_draft_pod" or "grid_draft_pod_playoff".</summary>
        [JsonProperty("deck_type")]
        public string DeckType { get; set; }

        [JsonProperty("allow_sideboarding")]
        public bool? AllowSideboarding { get; set; }
    }

    /// <summary>A tournament as the list and the tournament view both carry it.</summary>
    public class TournamentSummary
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        /// <summary>"single_elimination", "double_elimination" or "swiss".</summary>
        [JsonProperty("bracket_type")]
        public string BracketType { get; set; }

        /// <summary>"open" (anyone may join) or "invite_only".</summary>
        [JsonProperty("registration_mode")]
        public string RegistrationMode { get; set; }

        [JsonProperty("created_by_user_id")]
        public int CreatedByUserId { get; set; }

        [JsonProperty("match_params")]
        public TournamentMatchParams MatchParams { get; set; } = new TournamentMatchParams();

        [JsonProperty("swiss_round_count")]
        public int? SwissRoundCount { get; set; }

        [JsonProperty("min_participants")]
        public int MinParticipants { get; set; }

        [JsonProperty("max_participants")]
        public int? MaxParticipants { get; set; }

        /// <summary>"registration", "drafting", "in_progress", "completed" or "cancelled".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("winner_user_id")]
        public int? WinnerUserId { get; set; }

        // --- only on the lists ---

        /// <summary>Your own seat: "invited", "joined", "declined" or "withdrawn"; null when you never had one (you made it but didn't join, or you cast it).</summary>
        [JsonProperty("my_participant_status")]
        public string MyParticipantStatus { get; set; }

        [JsonProperty("winner_username")]
        public string WinnerUsername { get; set; }

        [JsonProperty("joined_count")]
        public int JoinedCount { get; set; }

        /// <summary>Only on the "open to join" list.</summary>
        [JsonProperty("creator_username")]
        public string CreatorUsername { get; set; }
    }

    /// <summary>GET /tournaments (open to join) and GET /tournaments?mine=1.</summary>
    public class TournamentsResponse : ApiEnvelope
    {
        [JsonProperty("tournaments")]
        public List<TournamentSummary> Tournaments { get; set; } = new List<TournamentSummary>();
    }

    public class TournamentParticipant
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("user_id")]
        public int UserId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("seed")]
        public int? Seed { get; set; }

        /// <summary>The name of the deck submitted at join time (a Power Duel tournament); null before one is in.</summary>
        [JsonProperty("deck_name")]
        public string DeckName { get; set; }

        /// <summary>Your own drafted pool (Booster Draft and Grid Draft pods); null for everyone else's seat.</summary>
        [JsonProperty("draft_pool_card_ids")]
        public List<BoardCard> DraftPool { get; set; }

        /// <summary>The deck you last played from that pool; null for everyone else's seat, and before you have.</summary>
        [JsonProperty("current_deck_card_ids")]
        public List<BoardCard> CurrentDeck { get; set; }
    }

    public class TournamentRound
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        /// <summary>"single", "winners", "losers", "grand_final" or "swiss".</summary>
        [JsonProperty("bracket")]
        public string Bracket { get; set; }

        [JsonProperty("round_number")]
        public int RoundNumber { get; set; }

        [JsonProperty("status")]
        public string Status { get; set; }

        /// <summary>Only on a Grid Draft pod-playoff round: that pod's own mini bracket carries its matches inline.</summary>
        [JsonProperty("matches")]
        public List<TournamentMatch> Matches { get; set; }
    }

    public class TournamentMatch
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("slot")]
        public int Slot { get; set; }

        [JsonProperty("participant1_id")]
        public int? Participant1Id { get; set; }

        [JsonProperty("participant2_id")]
        public int? Participant2Id { get; set; }

        [JsonProperty("winner_participant_id")]
        public int? WinnerParticipantId { get; set; }

        /// <summary>The match's game (a single game, or the first of a match); null until it exists.</summary>
        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        /// <summary>"pending" (waiting for players), "in_progress", "completed" or "bye".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }
    }

    public class SwissStanding
    {
        [JsonProperty("wins")]
        public int Wins { get; set; }

        [JsonProperty("buchholz")]
        public int Buchholz { get; set; }
    }

    public class PodSeat
    {
        [JsonProperty("participant_id")]
        public int ParticipantId { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("seat_order")]
        public int SeatOrder { get; set; }
    }

    /// <summary>One draft pod of a Booster Draft or Grid Draft pod tournament.</summary>
    public class TournamentPod
    {
        [JsonProperty("pod_number")]
        public int PodNumber { get; set; }

        /// <summary>"regular", or "final" for the pod of pod winners in a pod-playoff tournament.</summary>
        [JsonProperty("kind")]
        public string Kind { get; set; }

        /// <summary>"drafting", "playing" (its own bracket) or "completed".</summary>
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("current_round")]
        public int CurrentRound { get; set; }

        /// <summary>A Grid Draft pod drafts in an ordinary game; null for a Booster Draft pod.</summary>
        [JsonProperty("game_id")]
        public int? GameId { get; set; }

        [JsonProperty("seats")]
        public List<PodSeat> Seats { get; set; } = new List<PodSeat>();

        [JsonProperty("winner_username")]
        public string WinnerUsername { get; set; }

        [JsonProperty("bracket_rounds")]
        public List<TournamentRound> BracketRounds { get; set; } = new List<TournamentRound>();
    }

    /// <summary>GET /tournaments/state.</summary>
    public class TournamentStateResponse : ApiEnvelope
    {
        [JsonProperty("tournament")]
        public TournamentSummary Tournament { get; set; }

        [JsonProperty("participants")]
        public List<TournamentParticipant> Participants { get; set; } = new List<TournamentParticipant>();

        [JsonProperty("rounds")]
        public List<TournamentRound> Rounds { get; set; } = new List<TournamentRound>();

        [JsonProperty("matches_by_round")]
        [JsonConverter(typeof(PhpMapConverter<int, List<TournamentMatch>>))]
        public Dictionary<int, List<TournamentMatch>> MatchesByRound { get; set; } = new Dictionary<int, List<TournamentMatch>>();

        /// <summary>Swiss only, once it has started: participant id to record, best first.</summary>
        [JsonProperty("standings")]
        [JsonConverter(typeof(PhpMapConverter<int, SwissStanding>))]
        public Dictionary<int, SwissStanding> Standings { get; set; }

        /// <summary>Booster Draft and Grid Draft pod tournaments only.</summary>
        [JsonProperty("pods")]
        public List<TournamentPod> Pods { get; set; }
    }

    /// <summary>GET /tournaments/pod-draft/state: the two boosters in front of you (null once you've picked from one this round).</summary>
    public class PodDraftStateResponse : ApiEnvelope
    {
        /// <summary>"drafting" or "completed".</summary>
        [JsonProperty("pod_status")]
        public string PodStatus { get; set; }

        [JsonProperty("current_round")]
        public int CurrentRound { get; set; }

        [JsonProperty("total_rounds")]
        public int TotalRounds { get; set; }

        [JsonProperty("pod_size")]
        public int PodSize { get; set; }

        [JsonProperty("drafted_cards")]
        public List<BoardCard> DraftedCards { get; set; } = new List<BoardCard>();

        [JsonProperty("left")]
        public List<BoardCard> Left { get; set; }

        [JsonProperty("right")]
        public List<BoardCard> Right { get; set; }
    }

    /// <summary>POST /tournaments answers 201 with the new id.</summary>
    public class CreateTournamentResponse : ApiEnvelope
    {
        [JsonProperty("tournament_id")]
        public int TournamentId { get; set; }
    }
}
