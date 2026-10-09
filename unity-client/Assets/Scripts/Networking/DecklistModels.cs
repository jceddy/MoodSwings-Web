using System.Collections.Generic;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>One saved decklist as the list shows it.</summary>
    public class DecklistSummary
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        /// <summary>"private" or "friends".</summary>
        [JsonProperty("visibility")]
        public string Visibility { get; set; }

        [JsonProperty("card_count")]
        public int CardCount { get; set; }

        [JsonProperty("sideboard_card_count")]
        public int SideboardCardCount { get; set; }

        [JsonProperty("updated_at")]
        public string UpdatedAt { get; set; }
    }

    public class FriendDecklists
    {
        [JsonProperty("friend_id")]
        public int FriendId { get; set; }

        [JsonProperty("friend_username")]
        public string FriendUsername { get; set; }

        [JsonProperty("decklists")]
        public List<DecklistSummary> Decklists { get; set; } = new List<DecklistSummary>();
    }

    /// <summary>GET /decklists: your own decks, and the ones your friends share with friends.</summary>
    public class DecklistsResponse : ApiEnvelope
    {
        [JsonProperty("own")]
        public List<DecklistSummary> Own { get; set; } = new List<DecklistSummary>();

        [JsonProperty("friends")]
        public List<FriendDecklists> Friends { get; set; } = new List<FriendDecklists>();
    }

    /// <summary>One decklist in full, its cards listed once per copy.</summary>
    public class DecklistDetail
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        [JsonProperty("visibility")]
        public string Visibility { get; set; }

        [JsonProperty("owner_user_id")]
        public int OwnerUserId { get; set; }

        [JsonProperty("cards")]
        public List<BoardCard> Cards { get; set; } = new List<BoardCard>();

        [JsonProperty("sideboard_cards")]
        public List<BoardCard> SideboardCards { get; set; } = new List<BoardCard>();
    }

    public class DecklistViewResponse : ApiEnvelope
    {
        [JsonProperty("decklist")]
        public DecklistDetail Decklist { get; set; }
    }

    public class DecklistCreatedResponse : ApiEnvelope
    {
        [JsonProperty("decklist_id")]
        public int DecklistId { get; set; }
    }

    /// <summary>GET /cards/catalog: every printed card, which the deck builder filters on the device.</summary>
    public class CatalogResponse : ApiEnvelope
    {
        [JsonProperty("cards")]
        public List<BoardCard> Cards { get; set; } = new List<BoardCard>();
    }
}
