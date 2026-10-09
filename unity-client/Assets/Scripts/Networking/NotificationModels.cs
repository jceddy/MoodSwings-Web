using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>What you want to be told about (by push on the website, and by Discord message). All on to begin with.</summary>
    public class NotificationPreferences
    {
        [JsonProperty("notify_your_turn")]
        public bool YourTurn { get; set; } = true;

        [JsonProperty("notify_friend_request")]
        public bool FriendRequest { get; set; } = true;

        [JsonProperty("notify_game_finished")]
        public bool GameFinished { get; set; } = true;

        [JsonProperty("notify_chat_message")]
        public bool ChatMessage { get; set; } = true;

        [JsonProperty("notify_timeout_warning")]
        public bool TimeoutWarning { get; set; } = true;

        [JsonProperty("notify_achievement_unlocked")]
        public bool AchievementUnlocked { get; set; } = true;

        /// <summary>Send everything at once, instead of at most one notification every five minutes.</summary>
        [JsonProperty("disable_cooldown")]
        public bool DisableCooldown { get; set; }

        public NotificationPreferences Copy() => (NotificationPreferences)MemberwiseClone();

        /// <summary>
        /// The POST body. The server reads a missing field as its default, so a save must always carry all of them --
        /// sending one alone would reset the rest.
        /// </summary>
        public Dictionary<string, object> ToBody() => new Dictionary<string, object>
        {
            ["notify_your_turn"] = YourTurn,
            ["notify_friend_request"] = FriendRequest,
            ["notify_game_finished"] = GameFinished,
            ["notify_chat_message"] = ChatMessage,
            ["notify_timeout_warning"] = TimeoutWarning,
            ["notify_achievement_unlocked"] = AchievementUnlocked,
            ["disable_cooldown"] = DisableCooldown,
        };
    }

    /// <summary>GET and POST /notifications/preferences.</summary>
    public class NotificationPreferencesResponse : ApiEnvelope
    {
        [JsonProperty("preferences")]
        public NotificationPreferences Preferences { get; set; }
    }

    /// <summary>GET /discord/status.</summary>
    public class DiscordStatusResponse : ApiEnvelope
    {
        [JsonProperty("linked")]
        public bool Linked { get; set; }

        [JsonProperty("discord_username")]
        public string DiscordUsername { get; set; }
    }
}
