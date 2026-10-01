using System.Collections.Generic;
using UnityEngine;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// Small string-keyed persistence seam (PlayerPrefs in the app, a
    /// dictionary in tests). Never put secrets here in the clear -- see
    /// <see cref="SecureSessionStore"/>.
    /// </summary>
    public interface IKeyValueStore
    {
        string GetString(string key);

        void SetString(string key, string value);

        void Delete(string key);
    }

    public sealed class PlayerPrefsKeyValueStore : IKeyValueStore
    {
        public string GetString(string key) => PlayerPrefs.HasKey(key) ? PlayerPrefs.GetString(key) : null;

        public void SetString(string key, string value)
        {
            PlayerPrefs.SetString(key, value);
            PlayerPrefs.Save();
        }

        public void Delete(string key)
        {
            PlayerPrefs.DeleteKey(key);
            PlayerPrefs.Save();
        }
    }

    public sealed class InMemoryKeyValueStore : IKeyValueStore
    {
        public Dictionary<string, string> Values { get; } = new Dictionary<string, string>();

        public string GetString(string key) => Values.TryGetValue(key, out var value) ? value : null;

        public void SetString(string key, string value) => Values[key] = value;

        public void Delete(string key) => Values.Remove(key);
    }
}
