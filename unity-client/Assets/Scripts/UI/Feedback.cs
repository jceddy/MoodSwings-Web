using MoodSwings.Core;
using UnityEngine;

namespace MoodSwings.UI
{
    public enum SoundId
    {
        CardPlay,
        YourTurn,
        Attention,
        RoundWon,
        RoundLost,
        GameWon,
        GameLost,
        Chat,
    }

    public enum HapticKind
    {
        Light,
        Medium,
        Heavy,
    }

    public interface ISoundPlayer
    {
        void Play(SoundId sound);
    }

    public interface IHaptics
    {
        void Pulse(HapticKind kind);
    }

    /// <summary>What a cue sounds and feels like: a sound, and optionally a buzz.</summary>
    public readonly struct FeedbackPlan
    {
        public FeedbackPlan(SoundId sound, HapticKind? haptic)
        {
            Sound = sound;
            Haptic = haptic;
        }

        public SoundId Sound { get; }

        public HapticKind? Haptic { get; }

        /// <summary>
        /// The feedback for a cue, or null for one that stays silent (a round ending for
        /// someone who is only watching). Only your own plays buzz; everything is quiet
        /// for the things that happen to other people except their chat and the cards they play.
        /// </summary>
        public static FeedbackPlan? For(BoardCue cue, int? viewerGamePlayerId)
        {
            switch (cue.Kind)
            {
                case CueKind.CardPlayed:
                    return new FeedbackPlan(SoundId.CardPlay, cue.PlayerId == viewerGamePlayerId ? HapticKind.Light : (HapticKind?)null);
                case CueKind.YourTurn:
                    return new FeedbackPlan(SoundId.YourTurn, HapticKind.Medium);
                case CueKind.DecisionForYou:
                    return new FeedbackPlan(SoundId.Attention, HapticKind.Medium);
                case CueKind.RoundWon:
                    return new FeedbackPlan(SoundId.RoundWon, HapticKind.Light);
                case CueKind.RoundLost:
                    return new FeedbackPlan(SoundId.RoundLost, HapticKind.Light);
                case CueKind.GameWon:
                    return new FeedbackPlan(SoundId.GameWon, HapticKind.Heavy);
                case CueKind.GameLost:
                    return new FeedbackPlan(SoundId.GameLost, HapticKind.Heavy);
                case CueKind.Chat:
                    return new FeedbackPlan(SoundId.Chat, null);
                default:
                    return null;
            }
        }
    }

    /// <summary>
    /// Sound and vibration for things that happen on the board, honoring the device's
    /// settings. The players are swappable so tests can see what would have played.
    /// </summary>
    public static class GameFeedback
    {
        private static ISoundPlayer _sound;
        private static IHaptics _haptics;

        public static ISoundPlayer Sound
        {
            get => _sound ?? (_sound = SoundPlayer.Create());
            set => _sound = value;
        }

        public static IHaptics Haptics
        {
            get => _haptics ?? (_haptics = PlatformHaptics.Create());
            set => _haptics = value;
        }

        public static void Play(BoardCue cue, int? viewerGamePlayerId, DeviceSettings settings)
        {
            var plan = FeedbackPlan.For(cue, viewerGamePlayerId);
            if (plan == null)
            {
                return;
            }

            if (settings.SoundOn)
            {
                Sound.Play(plan.Value.Sound);
            }

            if (settings.VibrationOn && plan.Value.Haptic.HasValue)
            {
                Haptics.Pulse(plan.Value.Haptic.Value);
            }
        }
    }

    /// <summary>Short buzzes on a phone; nothing anywhere else.</summary>
    public static class PlatformHaptics
    {
        public static IHaptics Create()
        {
#if UNITY_ANDROID && !UNITY_EDITOR
            return new AndroidHaptics();
#else
            return new NoHaptics();
#endif
        }

        private sealed class NoHaptics : IHaptics
        {
            public void Pulse(HapticKind kind)
            {
            }
        }

#if UNITY_ANDROID && !UNITY_EDITOR
        private sealed class AndroidHaptics : IHaptics
        {
            private AndroidJavaObject _vibrator;
            private bool _failed;

            public void Pulse(HapticKind kind)
            {
                if (_failed)
                {
                    return;
                }

                // The long buzz is for the biggest moments. Using this call is also what makes
                // Unity add the VIBRATE permission to the Android manifest for the calls below.
                if (kind == HapticKind.Heavy)
                {
                    Handheld.Vibrate();
                    return;
                }

                try
                {
                    if (_vibrator == null)
                    {
                        using (var player = new AndroidJavaClass("com.unity3d.player.UnityPlayer"))
                        using (var activity = player.GetStatic<AndroidJavaObject>("currentActivity"))
                        {
                            _vibrator = activity.Call<AndroidJavaObject>("getSystemService", "vibrator");
                        }
                    }

                    // The plain millisecond form is deprecated but works on every API level.
                    var milliseconds = kind == HapticKind.Light ? 18L : 40L;
                    _vibrator.Call("vibrate", milliseconds);
                }
                catch (System.Exception e)
                {
                    // No vibrator, or no permission: stay quiet rather than fail on every cue.
                    _failed = true;
                    Debug.LogWarning("Vibration unavailable: " + e.Message);
                }
            }
        }
#endif
    }
}
