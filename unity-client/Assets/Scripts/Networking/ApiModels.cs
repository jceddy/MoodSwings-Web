using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>
    /// Every php-app JSON response carries "status" ("ok"/"error"/
    /// "maintenance") and, on anything but success, a "message". Response
    /// models derive from this so ApiClient can read both uniformly.
    /// </summary>
    public class ApiEnvelope
    {
        [JsonProperty("status")]
        public string Status { get; set; }

        [JsonProperty("message")]
        public string Message { get; set; }
    }

    /// <summary>
    /// The user as /login and /me return them. /login sends only the first
    /// four fields (php-app's publicUser()); /me adds the preference flags,
    /// which is why those are nullable -- null means "not sent", not false.
    /// </summary>
    public class User
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }

        [JsonProperty("email")]
        public string Email { get; set; }

        [JsonProperty("phone_number")]
        public string PhoneNumber { get; set; }

        [JsonProperty("allow_custom_content")]
        public bool? AllowCustomContent { get; set; }

        [JsonProperty("auto_apply_scoring_bonuses")]
        public bool? AutoApplyScoringBonuses { get; set; }

        [JsonProperty("auto_pass_on_empty_hand")]
        public bool? AutoPassOnEmptyHand { get; set; }

        [JsonProperty("board_layout_preference")]
        public string BoardLayoutPreference { get; set; }

        [JsonProperty("default_selections_mode_preference")]
        public bool? DefaultSelectionsModePreference { get; set; }

        [JsonProperty("matchmaking_discoverable")]
        public bool? MatchmakingDiscoverable { get; set; }

        [JsonProperty("pause_before_own_turn")]
        public bool? PauseBeforeOwnTurn { get; set; }

        [JsonProperty("share_presence")]
        public bool? SharePresence { get; set; }
    }

    public class UserResponse : ApiEnvelope
    {
        [JsonProperty("user")]
        public User User { get; set; }
    }
}
