using System;
using System.Collections.Generic;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Plays the game's sounds. There are no sound files yet, so they are placeholders
    /// synthesized here: short tones and a thump, enough to hear that things are wired up.
    /// Replace <see cref="SoundBank.Create"/> with real clips when there are some.
    /// </summary>
    public sealed class SoundPlayer : MonoBehaviour, ISoundPlayer
    {
        private readonly Dictionary<SoundId, AudioClip> _clips = new Dictionary<SoundId, AudioClip>();
        private AudioSource _source;

        public static SoundPlayer Create()
        {
            var go = new GameObject("Sound player");
            DontDestroyOnLoad(go);
            var player = go.AddComponent<SoundPlayer>();
            player._source = go.AddComponent<AudioSource>();
            player._source.playOnAwake = false;
            player._source.volume = 0.6f;
            return player;
        }

        public void Play(SoundId sound)
        {
            if (!_clips.TryGetValue(sound, out var clip))
            {
                clip = SoundBank.Create(sound);
                _clips[sound] = clip;
            }

            _source.PlayOneShot(clip);
        }
    }

    /// <summary>The placeholder sounds, as generated audio.</summary>
    public static class SoundBank
    {
        public const int SampleRate = 22050;

        public static AudioClip Create(SoundId sound)
        {
            var samples = Samples(sound);
            var clip = AudioClip.Create(sound.ToString(), samples.Length, 1, SampleRate, false);
            clip.SetData(samples, 0);
            return clip;
        }

        /// <summary>The waveform for a sound, mono, in -1..1. Public so it can be checked without an audio device.</summary>
        public static float[] Samples(SoundId sound)
        {
            switch (sound)
            {
                case SoundId.CardPlay:
                    // A soft thump: a low tone that drops in pitch, with a click at the start.
                    return Mix(Sweep(0.14f, 190f, 90f, 0.7f), Noise(0.03f, 0.35f));
                case SoundId.YourTurn:
                    return Notes(0.12f, 660f, 880f);
                case SoundId.Attention:
                    return Notes(0.09f, 880f, 0f, 880f);
                case SoundId.RoundWon:
                    return Notes(0.12f, 523f, 659f, 784f);
                case SoundId.RoundLost:
                    return Notes(0.16f, 392f, 330f, 262f);
                case SoundId.GameWon:
                    return Notes(0.16f, 523f, 659f, 784f, 1047f);
                case SoundId.GameLost:
                    return Notes(0.22f, 330f, 262f, 196f);
                case SoundId.Chat:
                    return Notes(0.05f, 1200f);
                default:
                    throw new ArgumentOutOfRangeException(nameof(sound));
            }
        }

        private static float[] Tone(float seconds, float hertz, float volume = 0.5f)
        {
            var samples = new float[Mathf.Max(1, Mathf.RoundToInt(seconds * SampleRate))];
            for (var i = 0; i < samples.Length; i++)
            {
                var t = (float)i / SampleRate;
                var envelope = Mathf.Exp(-5f * i / samples.Length) * Mathf.Min(1f, i / 80f);
                samples[i] = hertz <= 0f ? 0f : Mathf.Sin(2f * Mathf.PI * hertz * t) * volume * envelope;
            }

            return samples;
        }

        private static float[] Sweep(float seconds, float fromHertz, float toHertz, float volume)
        {
            var samples = new float[Mathf.RoundToInt(seconds * SampleRate)];
            var phase = 0f;
            for (var i = 0; i < samples.Length; i++)
            {
                var progress = (float)i / samples.Length;
                phase += 2f * Mathf.PI * Mathf.Lerp(fromHertz, toHertz, progress) / SampleRate;
                samples[i] = Mathf.Sin(phase) * volume * Mathf.Exp(-4f * progress);
            }

            return samples;
        }

        private static float[] Noise(float seconds, float volume)
        {
            var random = new System.Random(7);
            var samples = new float[Mathf.RoundToInt(seconds * SampleRate)];
            for (var i = 0; i < samples.Length; i++)
            {
                samples[i] = ((float)random.NextDouble() * 2f - 1f) * volume * Mathf.Exp(-9f * i / samples.Length);
            }

            return samples;
        }

        private static float[] Notes(float secondsEach, params float[] hertz)
        {
            var all = new List<float>();
            foreach (var note in hertz)
            {
                all.AddRange(Tone(secondsEach, note));
            }

            return all.ToArray();
        }

        private static float[] Mix(float[] a, float[] b)
        {
            var mixed = new float[Mathf.Max(a.Length, b.Length)];
            for (var i = 0; i < mixed.Length; i++)
            {
                mixed[i] = Mathf.Clamp((i < a.Length ? a[i] : 0f) + (i < b.Length ? b[i] : 0f), -1f, 1f);
            }

            return mixed;
        }
    }
}
