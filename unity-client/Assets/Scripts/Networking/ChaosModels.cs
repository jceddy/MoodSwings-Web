using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>A Chaos Draft effect: something extra that, once attached to a card, stays with it for the game.</summary>
    public class ChaosEffect
    {
        [JsonProperty("id")]
        public int Id { get; set; }

        /// <summary>"common", "uncommon", "rare" or "mythic".</summary>
        [JsonProperty("rarity")]
        public string Rarity { get; set; }

        [JsonProperty("shape")]
        public string Shape { get; set; }

        [JsonProperty("rules_text")]
        public string RulesText { get; set; }
    }

    /// <summary>The choice a player (or, in Open Team Play, a team) faces at the start of a Chaos Draft round: one of two effects, and a card to put it on.</summary>
    public class ChaosOffer
    {
        [JsonProperty("effect_1")]
        public ChaosEffect Effect1 { get; set; }

        [JsonProperty("effect_2")]
        public ChaosEffect Effect2 { get; set; }

        /// <summary>Open Team Play: the team decides together, one proposing and the other agreeing.</summary>
        [JsonProperty("is_team_offer")]
        public bool IsTeamOffer { get; set; }

        /// <summary>"confirm" while a team's proposal waits for the partner's agreement.</summary>
        [JsonProperty("phase")]
        public string Phase { get; set; }

        [JsonProperty("proposer_game_player_id")]
        public int? ProposerGamePlayerId { get; set; }

        public bool IsAwaitingConfirmation => IsTeamOffer && Phase == "confirm";
    }

    /// <summary>GET /games/chaos-draft-offer: your open offer (null once resolved) and whether the round as a whole is clear to play.</summary>
    public class ChaosOfferInfo : ApiEnvelope
    {
        [JsonProperty("offer")]
        public ChaosOffer Offer { get; set; }

        /// <summary>False while anyone at the table still has an offer open; nobody can play or pass until then.</summary>
        [JsonProperty("round_ready")]
        public bool RoundReady { get; set; } = true;
    }

    /// <summary>A repeating effect has set up a loop; the player can apply it a number of times in one go.</summary>
    public class ChaosLoopShortcut
    {
        [JsonProperty("game_player_id")]
        public int GamePlayerId { get; set; }

        [JsonProperty("kind")]
        public string Kind { get; set; }

        /// <summary>Most times it can be applied at once.</summary>
        [JsonProperty("cap")]
        public int Cap { get; set; }

        [JsonProperty("label")]
        public string Label { get; set; }
    }
}
