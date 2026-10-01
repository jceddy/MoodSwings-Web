using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>One accepted friend, from GET /friends.</summary>
    public class Friend
    {
        /// <summary>The friendship row's id -- not the friend's user id.</summary>
        [JsonProperty("id")]
        public int FriendshipId { get; set; }

        [JsonProperty("friend_id")]
        public int UserId { get; set; }

        [JsonProperty("friend_username")]
        public string Username { get; set; }

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }

        /// <summary>"online", "offline", or "hidden" (the friend doesn't share their status).</summary>
        [JsonProperty("presence")]
        public string Presence { get; set; }

        public bool IsOnline => Presence == "online";
    }

    public class FriendsResponse : ApiEnvelope
    {
        [JsonProperty("friends")]
        public List<Friend> Friends { get; set; } = new List<Friend>();
    }

    /// <summary>A pending friend request, in either direction (GET /friends/invites).</summary>
    public class FriendInvite
    {
        [JsonProperty("id")]
        public int FriendshipId { get; set; }

        /// <summary>The user on the other end: the sender of an incoming request, the recipient of an outgoing one.</summary>
        [JsonProperty("other_user_id")]
        public int OtherUserId { get; set; }

        [JsonProperty("other_username")]
        public string OtherUsername { get; set; }

        [JsonProperty("created_at")]
        public string CreatedAt { get; set; }
    }

    public class FriendInvitesResponse : ApiEnvelope
    {
        [JsonProperty("incoming")]
        public List<FriendInvite> Incoming { get; set; } = new List<FriendInvite>();

        [JsonProperty("outgoing")]
        public List<FriendInvite> Outgoing { get; set; } = new List<FriendInvite>();
    }

    public class UserReference
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("username")]
        public string Username { get; set; }
    }

    public class SendInviteResponse : ApiEnvelope
    {
        [JsonProperty("user")]
        public UserReference User { get; set; }
    }
}
