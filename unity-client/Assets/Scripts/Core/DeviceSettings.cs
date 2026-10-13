using System;
using MoodSwings.Core.Storage;

namespace MoodSwings.Core
{
    /// <summary>
    /// Settings that belong to this device rather than the account (so they aren't
    /// on the server): sound and vibration, both on until switched off.
    /// </summary>
    public sealed class DeviceSettings
    {
        private const string SoundKey = "device.sound";
        private const string VibrationKey = "device.vibration";
        private const string EffectsVolumeKey = "device.effectsVolume";
        private const string MusicKey = "device.music";
        private const string MusicVolumeKey = "device.musicVolume";
        private const string PushKey = "device.push";
        private const string DarkModeKey = "device.darkMode";
        private const string DevServerKey = "device.devServer";
        private const string HideLockedKey = "device.achievements.hideLocked";
        private const string ChatSeenPrefix = "device.chatSeen.";
        private const string SeenAtKey = "device.achievements.seenAt";

        private readonly IKeyValueStore _store;

        public DeviceSettings(IKeyValueStore store)
        {
            _store = store;
        }

        /// <summary>Sound effects, on until switched off.</summary>
        public bool SoundOn
        {
            get => Read(SoundKey);
            set => _store.SetString(SoundKey, value ? "1" : "0");
        }

        /// <summary>How loud the sound effects are, 0 to 1 (a little under full to start with).</summary>
        public float EffectsVolume
        {
            get => ReadVolume(EffectsVolumeKey, 0.8f);
            set => WriteVolume(EffectsVolumeKey, value);
        }

        /// <summary>The background music, on until switched off.</summary>
        public bool MusicOn
        {
            get => Read(MusicKey);
            set => _store.SetString(MusicKey, value ? "1" : "0");
        }

        /// <summary>How loud the music is, 0 to 1 (quieter than the effects to start with: it is always on).</summary>
        public float MusicVolume
        {
            get => ReadVolume(MusicVolumeKey, 0.5f);
            set => WriteVolume(MusicVolumeKey, value);
        }

        public bool VibrationOn
        {
            get => Read(VibrationKey);
            set => _store.SetString(VibrationKey, value ? "1" : "0");
        }

        /// <summary>
        /// Which server this device was switched to by the hidden tap switch on the login screen: true for the dev
        /// server, false for production, null if it never was (the build's own default applies).
        /// </summary>
        public bool? DevServerChoice
        {
            get
            {
                var stored = _store.GetString(DevServerKey);
                return stored == null ? (bool?)null : stored == "1";
            }
            set
            {
                if (value.HasValue)
                {
                    _store.SetString(DevServerKey, value.Value ? "1" : "0");
                }
                else
                {
                    _store.Delete(DevServerKey);
                }
            }
        }

        /// <summary>The dark palette instead of the light one (off until switched on).</summary>
        public bool DarkMode
        {
            get => _store.GetString(DarkModeKey) == "1";
            set => _store.SetString(DarkModeKey, value ? "1" : "0");
        }

        /// <summary>Push notifications to this device: on until switched off (where the device can get them at all).</summary>
        public bool PushOn
        {
            get => Read(PushKey);
            set => _store.SetString(PushKey, value ? "1" : "0");
        }

        /// <summary>Show only the achievements you've unlocked (off until switched on).</summary>
        public bool HideLockedAchievements
        {
            get => _store.GetString(HideLockedKey) == "1";
            set => _store.SetString(HideLockedKey, value ? "1" : "0");
        }

        /// <summary>
        /// The newest unlock time you have seen on the Achievements screen; null before the first look, which is
        /// when whatever is already unlocked counts as seen.
        /// </summary>
        public string AchievementsSeenAt
        {
            get => _store.GetString(SeenAtKey);
            set
            {
                if (value == null)
                {
                    _store.Delete(SeenAtKey);
                }
                else
                {
                    _store.SetString(SeenAtKey, value);
                }
            }
        }

        /// <summary>
        /// How many of a game's chat messages have been read on this device, so a game opened again (or the app coming back
        /// from the background) doesn't count them as unread a second time.
        /// </summary>
        public int ChatSeen(int gameId) =>
            int.TryParse(_store.GetString(ChatSeenPrefix + gameId), out var count) ? count : 0;

        public void SetChatSeen(int gameId, int count) => _store.SetString(ChatSeenPrefix + gameId, count.ToString());

        private float ReadVolume(string key, float fallback) =>
            int.TryParse(_store.GetString(key), out var percent) ? Math.Max(0, Math.Min(100, percent)) / 100f : fallback;

        private void WriteVolume(string key, float value) =>
            _store.SetString(key, Math.Max(0, Math.Min(100, (int)Math.Round(value * 100f))).ToString());

        private bool Read(string key) => _store.GetString(key) != "0";
    }
}
