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

    /// <summary>php-app's publicUser(): what /login and /me return.</summary>
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
    }

    public class UserResponse : ApiEnvelope
    {
        [JsonProperty("user")]
        public User User { get; set; }
    }
}
