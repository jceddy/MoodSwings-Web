using System.Collections.Generic;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Networking
{
    /// <summary>
    /// One thing a card (or a pending decision) asks the player for. The server
    /// describes every card's choices this way (php-app/src/Rules/CardChoiceSchema.php)
    /// so a client can render a form for exactly the card being played; the same
    /// shape is used for the field of a pending decision. Types: player, mood,
    /// hand_card, discard_card, mode, value, bool, nested, grant_choice, card_order.
    /// </summary>
    public class ChoiceField
    {
        /// <summary>The key the answer is sent under.</summary>
        [JsonProperty("key")]
        public string Key { get; set; }

        [JsonProperty("type")]
        public string Type { get; set; }

        [JsonProperty("label")]
        public string Label { get; set; }

        [JsonProperty("required")]
        public bool Required { get; set; }

        /// <summary>Several can be chosen; the answer is a list.</summary>
        [JsonProperty("multi")]
        public bool Multi { get; set; }

        /// <summary>player: any or other. mood: own, other or any.</summary>
        [JsonProperty("scope")]
        public string Scope { get; set; }

        /// <summary>mode: the strings to pick from. grant_choice: objects with value and label. Read through <see cref="ChoiceOption"/>.</summary>
        [JsonProperty("options")]
        public JArray RawOptions { get; set; }

        /// <summary>value only: the smallest and largest value to offer.</summary>
        [JsonProperty("min")]
        public int? Min { get; set; }

        [JsonProperty("max")]
        public int? Max { get; set; }

        /// <summary>value only: further values above <see cref="Max"/> that some mood in play actually has.</summary>
        [JsonProperty("extra_values")]
        public List<int> ExtraValues { get; set; } = new List<int>();

        [JsonProperty("filter")]
        public ChoiceFilter Filter { get; set; }

        /// <summary>Players and moods owned by your teammate aren't legal (the card says "opponent").</summary>
        [JsonProperty("excludes_teammate")]
        public bool ExcludesTeammate { get; set; }

        /// <summary>mood: the card being played may name itself.</summary>
        [JsonProperty("includes_self")]
        public bool IncludesSelf { get; set; }

        /// <summary>multi: how many may be chosen.</summary>
        [JsonProperty("count")]
        public ChoiceCount Count { get; set; }

        /// <summary>multi: a relationship the chosen ones must satisfy together.</summary>
        [JsonProperty("constraint")]
        public ChoiceConstraint Constraint { get; set; }

        /// <summary>"cost" for a choice that pays to play the card rather than resolving its effect.</summary>
        [JsonProperty("stage")]
        public string Stage { get; set; }

        /// <summary>A required target that doesn't stop the card being played when there is no legal one.</summary>
        [JsonProperty("optional_if_no_targets")]
        public bool OptionalIfNoTargets { get; set; }

        /// <summary>This field only does anything once the card's mode field is set to this value.</summary>
        [JsonProperty("requires_mode")]
        public string RequiresMode { get; set; }

        /// <summary>nested: the sub-fields (Duplicity's repeat offer).</summary>
        [JsonProperty("fields")]
        public List<ChoiceField> Fields { get; set; }

        /// <summary>card_order: the cards to put in order.</summary>
        [JsonProperty("cards")]
        public List<OrderedCard> Cards { get; set; }

        /// <summary>The server's exact list of legal moods; wins over scope and filter.</summary>
        [JsonProperty("candidate_card_ids")]
        public List<int> CandidateCardIds { get; set; }

        /// <summary>The server's exact list of legal players; wins over scope and filter.</summary>
        [JsonProperty("candidate_player_ids")]
        public List<int> CandidatePlayerIds { get; set; }

        /// <summary>
        /// Each mood's value as if the card being played were already in play (a
        /// mood's value can depend on the board), for a combined-value limit.
        /// </summary>
        [JsonProperty("candidate_values")]
        [JsonConverter(typeof(PhpMapConverter<int, int>))]
        public Dictionary<int, int> CandidateValues { get; set; }

        /// <summary>A starting selection the server suggests (a mode's default).</summary>
        [JsonProperty("default")]
        public JToken Default { get; set; }
    }

    public class ChoiceFilter
    {
        [JsonProperty("colors")]
        public List<string> Colors { get; set; }

        /// <summary>A hand card's base value must be one of these.</summary>
        [JsonProperty("values")]
        public List<int> Values { get; set; }

        [JsonProperty("min_value")]
        public int? MinValue { get; set; }

        [JsonProperty("max_value")]
        public int? MaxValue { get; set; }

        /// <summary>"odd" or "even".</summary>
        [JsonProperty("parity")]
        public string Parity { get; set; }

        [JsonProperty("has_dice_value")]
        public bool HasDiceValue { get; set; }

        [JsonProperty("min_hand_count")]
        public int? MinHandCount { get; set; }

        [JsonProperty("min_mood_count")]
        public int? MinMoodCount { get; set; }
    }

    public class ChoiceCount
    {
        [JsonProperty("min")]
        public int? Min { get; set; }

        [JsonProperty("max")]
        public int? Max { get; set; }

        /// <summary>Choosing none is fine even though a minimum is set.</summary>
        [JsonProperty("zero_ok")]
        public bool ZeroOk { get; set; }
    }

    public class ChoiceConstraint
    {
        /// <summary>same_color_or_value, same_owner, distinct_owners or max_total_value.</summary>
        [JsonProperty("type")]
        public string Type { get; set; }

        [JsonProperty("max")]
        public int? Max { get; set; }
    }

    public class OrderedCard
    {
        [JsonProperty("card_id")]
        public int CardId { get; set; }

        [JsonProperty("name")]
        public string Name { get; set; }

        [JsonProperty("description")]
        public string Description { get; set; }
    }

    /// <summary>What Creativity would need if it copied one particular mood.</summary>
    public class CopySimulation
    {
        /// <summary>The fields to ask for once that mood is the one being copied.</summary>
        [JsonProperty("extra_fields")]
        public List<ChoiceField> ExtraFields { get; set; } = new List<ChoiceField>();

        /// <summary>False when the copied mood has a cost the player can't pay.</summary>
        [JsonProperty("cost_payable")]
        public bool CostPayable { get; set; } = true;
    }
}
