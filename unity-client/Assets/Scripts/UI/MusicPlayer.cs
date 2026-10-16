using System.Collections.Generic;
using MoodSwings.Core;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// The background music. The main theme plays while the app is open; on the board it gives way, with a slow
    /// crossfade, to the theme of the color of the mood played most recently. Each theme keeps its own place: going back
    /// to one carries on where it left off rather than starting again. Volume and mute are the device's settings and
    /// take effect as they are changed.
    /// </summary>
    public sealed class MusicPlayer : MonoBehaviour
    {
        /// <summary>How long a change of theme takes, in seconds.</summary>
        public const float FadeSeconds = 2f;

        private sealed class Track
        {
            public AudioSource Source;
            public float Level;
        }

        private static MusicPlayer _instance;

        private readonly Dictionary<MusicTheme, Track> _tracks = new Dictionary<MusicTheme, Track>();
        private readonly HashSet<MusicTheme> _missing = new HashSet<MusicTheme>();
        private MusicTheme _wanted = MusicTheme.Main;
        private bool _hasTheme;

        /// <summary>The one player, made the first time it is asked for and kept across scene loads.</summary>
        public static MusicPlayer Instance
        {
            get
            {
                if (_instance == null)
                {
                    var go = new GameObject("Music player");
                    DontDestroyOnLoad(go);
                    if (FindAnyObjectByType<AudioListener>() == null)
                    {
                        go.AddComponent<AudioListener>();
                    }

                    _instance = go.AddComponent<MusicPlayer>();
                }

                return _instance;
            }
        }

        /// <summary>The theme being played (or faded to).</summary>
        public MusicTheme Theme => _wanted;

        /// <summary>The loudness the device settings allow the music right now: 0 while it is muted.</summary>
        public float Gain { get; private set; }

        /// <summary>Whether a theme has been asked for yet.</summary>
        public bool HasTheme => _hasTheme;

        /// <summary>How far a theme is faded in: 0 is silent, 1 is its full level.</summary>
        public float LevelOf(MusicTheme theme) => _tracks.TryGetValue(theme, out var track) ? track.Level : 0f;

        /// <summary>The volume a theme is actually playing at now, after the device's settings.</summary>
        public float VolumeOf(MusicTheme theme) =>
            _tracks.TryGetValue(theme, out var track) && track.Source != null ? track.Source.volume : 0f;

        public bool IsPlaying(MusicTheme theme) =>
            _tracks.TryGetValue(theme, out var track) && track.Source != null && track.Source.isPlaying;

        /// <summary>Changes to this theme (a no-op if it already is).</summary>
        public void SetTheme(MusicTheme theme)
        {
            _hasTheme = true;
            _wanted = theme;
        }

        private void Update()
        {
            if (!_hasTheme)
            {
                return;
            }

            var device = AppServices.Device;
            var gain = device != null && device.MusicOn ? device.MusicVolume : 0f;
            Gain = gain;

            // Nothing is decoded while it is muted.
            if (gain <= 0f)
            {
                foreach (var track in _tracks.Values)
                {
                    if (track.Source != null && track.Source.isPlaying)
                    {
                        track.Source.Pause();
                    }
                }

                return;
            }

            TrackFor(_wanted); // made the first time it is wanted
            foreach (var pair in _tracks)
            {
                var track = pair.Value;
                var isWanted = pair.Key == _wanted;
                track.Level = MusicThemes.Fade(track.Level, isWanted, Time.unscaledDeltaTime, FadeSeconds);
                track.Source.volume = track.Level * gain;
                if (track.Level <= 0f)
                {
                    if (track.Source.isPlaying)
                    {
                        track.Source.Pause();
                    }
                }
                else if (!track.Source.isPlaying)
                {
                    // First time, or back from a pause: starts from the beginning, or from where it was left.
                    track.Source.UnPause();
                    if (!track.Source.isPlaying)
                    {
                        track.Source.Play();
                    }
                }
            }
        }

        // The track for a theme, made when it is first wanted. Null when the clip is missing.
        private Track TrackFor(MusicTheme theme)
        {
            if (_tracks.TryGetValue(theme, out var existing))
            {
                return existing;
            }

            if (_missing.Contains(theme))
            {
                return null;
            }

            var clip = Resources.Load<AudioClip>("Music/" + MusicThemes.ClipName(theme));
            if (clip == null)
            {
                _missing.Add(theme);
                Debug.LogWarning("No music for the " + theme + " theme.");
                return null;
            }

            var source = gameObject.AddComponent<AudioSource>();
            source.clip = clip;
            source.loop = true;
            source.playOnAwake = false;
            source.spatialBlend = 0f;
            source.volume = 0f;
            var track = new Track { Source = source };
            _tracks[theme] = track;
            return track;
        }
    }
}
