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
        private const string PushKey = "device.push";
        private const string DarkModeKey = "device.darkMode";
        private const string DevServerKey = "device.devServer";
        private const string HideLockedKey = "device.achievements.hideLocked";
        private const string SeenAtKey = "device.achievements.seenAt";

        private readonly IKeyValueStore _store;

        public DeviceSettings(IKeyValueStore store)
        {
            _store = store;
        }

        public bool SoundOn
        {
            get => Read(SoundKey);
            set => _store.SetString(SoundKey, value ? "1" : "0");
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

        private bool Read(string key) => _store.GetString(key) != "0";
    }
}
