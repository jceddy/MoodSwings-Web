using System;
using System.Collections.Generic;
using System.Globalization;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Networking
{
    /// <summary>
    /// Reads a JSON object as a dictionary, and also an empty JSON array as an empty
    /// dictionary. PHP has one type for lists and maps and writes an empty one as
    /// <c>[]</c>, so a field that is a map when it has entries (card id to value, say)
    /// arrives as an array when it has none; a plain Dictionary would throw and take
    /// the whole game state with it.
    /// </summary>
    public sealed class PhpMapConverter<TKey, TValue> : JsonConverter<Dictionary<TKey, TValue>>
    {
        public override Dictionary<TKey, TValue> ReadJson(
            JsonReader reader, Type objectType, Dictionary<TKey, TValue> existingValue, bool hasExistingValue, JsonSerializer serializer)
        {
            var token = JToken.Load(reader);
            var result = new Dictionary<TKey, TValue>();
            if (token.Type != JTokenType.Object)
            {
                // null, or an array (which here only ever means empty).
                return token.Type == JTokenType.Null ? null : result;
            }

            foreach (var property in (JObject)token)
            {
                var key = (TKey)Convert.ChangeType(property.Key, typeof(TKey), CultureInfo.InvariantCulture);
                result[key] = property.Value.ToObject<TValue>(serializer);
            }

            return result;
        }

        public override void WriteJson(JsonWriter writer, Dictionary<TKey, TValue> value, JsonSerializer serializer)
        {
            writer.WriteStartObject();
            foreach (var pair in value)
            {
                writer.WritePropertyName(Convert.ToString(pair.Key, CultureInfo.InvariantCulture));
                serializer.Serialize(writer, pair.Value);
            }

            writer.WriteEndObject();
        }
    }
}
