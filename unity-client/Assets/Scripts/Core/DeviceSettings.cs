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

        private bool Read(string key) => _store.GetString(key) != "0";
    }
}
