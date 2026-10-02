using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>What counts as something happening between two looks at a game, and what it sounds like.</summary>
    public class BoardCuesTests
    {
        private static GameState Fresh() => BoardFixtures.Load(406); // your turn, 3 players

        private static GameState Copy(GameState state) =>
            Newtonsoft.Json.JsonConvert.DeserializeObject<GameState>(Newtonsoft.Json.JsonConvert.SerializeObject(state));

        private static List<CueKind> Kinds(GameState before, GameState after) =>
            BoardCues.Between(before, after).Select(c => c.Kind).ToList();

        [Test]
        public void TheFirstLook_IsNotAnEvent()
        {
            Assert.AreEqual(0, BoardCues.Between(null, Fresh()).Count);
        }

        [Test]
        public void TwoDifferentGames_AreNeverCompared()
        {
            var other = Fresh();
            other.Game.Id += 1;
            other.InPlay.Clear();

            Assert.AreEqual(0, BoardCues.Between(Fresh(), other).Count);
        }

        [Test]
        public void NothingChanged_MeansNoCues()
        {
            Assert.AreEqual(0, BoardCues.Between(Fresh(), Fresh()).Count);
        }

        [Test]
        public void ANewMoodInPlay_IsACardPlayed_ForItsOwner()
        {
            var after = Fresh();
            after.InPlay.Add(new BoardCard { CardId = 9001, Name = "New", OwnerGamePlayerId = 910 });

            var cue = BoardCues.Between(Fresh(), after).Single();

            Assert.AreEqual(CueKind.CardPlayed, cue.Kind);
            Assert.AreEqual(9001, cue.CardId);
            Assert.AreEqual(910, cue.PlayerId);
        }

        [Test]
        public void AMoodThatChangedHands_IsNotAnotherPlay()
        {
            var after = Fresh();
            after.InPlay[0].OwnerGamePlayerId = 910;

            Assert.AreEqual(0, BoardCues.Between(Fresh(), after).Count);
        }

        [Test]
        public void TheTurnComingToYou_IsYourTurn_OnlyOnce()
        {
            var before = Fresh();
            before.Round.CurrentTurnGamePlayerId = 910;
            var after = Fresh();

            CollectionAssert.AreEqual(new[] { CueKind.YourTurn }, Kinds(before, after));
            Assert.AreEqual(0, BoardCues.Between(after, Fresh()).Count, "still your turn is not news");
        }

        [Test]
        public void ASpectator_NeverHasATurn()
        {
            var before = Fresh();
            before.Round.CurrentTurnGamePlayerId = 910;
            var after = Fresh();
            after.You.GamePlayerId = null;

            Assert.AreEqual(0, BoardCues.Between(before, after).Count);
        }

        [Test]
        public void ANewQuestionForYou_IsADecisionCue_ButNotTwice()
        {
            var withQuestion = BoardFixtures.Load(407);
            var without = Copy(withQuestion);
            without.Round.PendingDecision = null;

            CollectAssert(Kinds(without, withQuestion), CueKind.DecisionForYou);
            Assert.AreEqual(0, BoardCues.Between(withQuestion, Copy(withQuestion)).Count(c => c.Kind == CueKind.DecisionForYou));
        }

        private static void CollectAssert(List<CueKind> kinds, CueKind expected) => CollectionAssert.Contains(kinds, expected);

        [Test]
        public void AQuestionForSomeoneElse_IsNotACueForYou()
        {
            var withQuestion = BoardFixtures.Load(407);
            withQuestion.Round.PendingDecision.IsYou = false;
            var without = Copy(withQuestion);
            without.Round.PendingDecision = null;

            CollectionAssert.DoesNotContain(Kinds(without, withQuestion), CueKind.DecisionForYou);
        }

        // --- round and game endings ----------------------------------------------------------------

        private static GameState NextRound(GameState state, params (int player, int winsGained)[] wins)
        {
            var next = Copy(state);
            next.Round.RoundNumber = state.Round.RoundNumber + 1;
            foreach (var (player, gained) in wins)
            {
                next.Players.Single(p => p.GamePlayerId == player).TotalWins += gained;
            }

            return next;
        }

        [Test]
        public void ARoundYouWon_SaysSo()
        {
            var before = Fresh();
            var cue = BoardCues.Between(before, NextRound(before, (909, 1))).Single(c => c.Kind != CueKind.YourTurn);

            Assert.AreEqual(CueKind.RoundWon, cue.Kind);
            Assert.AreEqual("You won the round!", cue.Text);
        }

        [Test]
        public void ARoundSomeoneElseWon_NamesThem()
        {
            var before = Fresh();
            var cue = BoardCues.Between(before, NextRound(before, (910, 1))).Single();

            Assert.AreEqual(CueKind.RoundLost, cue.Kind);
            Assert.AreEqual("BotSage won the round.", cue.Text);
        }

        [Test]
        public void ARoundWithNoWinner_IsJustOver()
        {
            var before = Fresh();
            var cue = BoardCues.Between(before, NextRound(before)).Single();

            Assert.AreEqual(CueKind.RoundOver, cue.Kind);
        }

        [Test]
        public void ASpectatorToARound_IsToldWhoWon_WithoutWinOrLose()
        {
            var before = Fresh();
            var after = NextRound(before, (911, 1));
            after.You.GamePlayerId = null;

            var cue = BoardCues.Between(before, after).Single();

            Assert.AreEqual(CueKind.RoundOver, cue.Kind);
            Assert.AreEqual("BotSageQuick won the round.", cue.Text);
        }

        [Test]
        public void TheGameEnding_IsAWinOrALoss_NotAlsoARoundEnd()
        {
            var before = Fresh();
            var won = NextRound(before, (909, 1));
            won.Game.Status = "completed";
            won.Game.WinnerUsernames = new List<string> { "bshaftoe" };
            var lost = Copy(won);
            lost.Game.WinnerUsernames = new List<string> { "BotSage" };

            var wonCues = BoardCues.Between(before, won);
            var lostCues = BoardCues.Between(before, lost);

            CollectionAssert.AreEqual(new[] { CueKind.GameWon }, wonCues.Select(c => c.Kind).ToArray());
            Assert.AreEqual("You won the game!", wonCues[0].Text);
            CollectionAssert.AreEqual(new[] { CueKind.GameLost }, lostCues.Select(c => c.Kind).ToArray());
            Assert.AreEqual("BotSage won the game.", lostCues[0].Text);
        }

        [Test]
        public void AGameThatWasAlreadyOver_IsNotOverAgain()
        {
            var over = Fresh();
            over.Game.Status = "completed";

            Assert.AreEqual(0, BoardCues.Between(over, Copy(over)).Count);
        }

        // --- chat -------------------------------------------------------------------------------------

        [Test]
        public void AMessageFromSomeoneElse_IsACue_YourOwnIsNot()
        {
            var before = Fresh();
            var after = Copy(before);
            after.ChatMessages.Add(new BoardChatMessage { Id = 5, SenderUsername = "bshaftoe", MessageText = "mine" });
            Assert.AreEqual(0, BoardCues.Between(before, after).Count);

            after.ChatMessages.Add(new BoardChatMessage { Id = 6, SenderUsername = "BotSage", MessageText = "theirs" });
            CollectionAssert.AreEqual(new[] { CueKind.Chat }, Kinds(before, after));
        }

        // --- what they sound like -----------------------------------------------------------------------

        [Test]
        public void Feedback_OnlyYourOwnPlaysBuzz()
        {
            var mine = FeedbackPlan.For(new BoardCue { Kind = CueKind.CardPlayed, PlayerId = 909 }, 909).Value;
            var theirs = FeedbackPlan.For(new BoardCue { Kind = CueKind.CardPlayed, PlayerId = 910 }, 909).Value;

            Assert.AreEqual(SoundId.CardPlay, mine.Sound);
            Assert.AreEqual(HapticKind.Light, mine.Haptic);
            Assert.AreEqual(SoundId.CardPlay, theirs.Sound);
            Assert.IsNull(theirs.Haptic);
        }

        [Test]
        public void Feedback_TurnAndQuestionsBuzz_ChatDoesNot_AndWatchingEndsAreSilent()
        {
            Assert.AreEqual(HapticKind.Medium, FeedbackPlan.For(new BoardCue { Kind = CueKind.YourTurn }, 1).Value.Haptic);
            Assert.AreEqual(SoundId.Attention, FeedbackPlan.For(new BoardCue { Kind = CueKind.DecisionForYou }, 1).Value.Sound);
            Assert.IsNull(FeedbackPlan.For(new BoardCue { Kind = CueKind.Chat }, 1).Value.Haptic);
            Assert.IsNull(FeedbackPlan.For(new BoardCue { Kind = CueKind.RoundOver }, 1));
            Assert.IsNull(FeedbackPlan.For(new BoardCue { Kind = CueKind.GameOver }, 1));
            Assert.AreEqual(HapticKind.Heavy, FeedbackPlan.For(new BoardCue { Kind = CueKind.GameWon }, 1).Value.Haptic);
        }

        private sealed class RecordingSound : ISoundPlayer
        {
            public List<SoundId> Played { get; } = new List<SoundId>();

            public void Play(SoundId sound) => Played.Add(sound);
        }

        private sealed class RecordingHaptics : IHaptics
        {
            public List<HapticKind> Pulses { get; } = new List<HapticKind>();

            public void Pulse(HapticKind kind) => Pulses.Add(kind);
        }

        [Test]
        public void TheDeviceSwitches_DecideWhatActuallyPlays()
        {
            var sound = new RecordingSound();
            var haptics = new RecordingHaptics();
            GameFeedback.Sound = sound;
            GameFeedback.Haptics = haptics;
            try
            {
                var settings = new DeviceSettings(new InMemoryKeyValueStore());
                var cue = new BoardCue { Kind = CueKind.YourTurn };

                GameFeedback.Play(cue, 1, settings);
                CollectionAssert.AreEqual(new[] { SoundId.YourTurn }, sound.Played);
                CollectionAssert.AreEqual(new[] { HapticKind.Medium }, haptics.Pulses);

                settings.SoundOn = false;
                GameFeedback.Play(cue, 1, settings);
                Assert.AreEqual(1, sound.Played.Count, "sound off");
                Assert.AreEqual(2, haptics.Pulses.Count, "but it still buzzes");

                settings.VibrationOn = false;
                GameFeedback.Play(cue, 1, settings);
                Assert.AreEqual(1, sound.Played.Count);
                Assert.AreEqual(2, haptics.Pulses.Count, "and now it's silent");
            }
            finally
            {
                GameFeedback.Sound = null;
                GameFeedback.Haptics = null;
            }
        }

        [Test]
        public void DeviceSettings_StartOn_AndRememberBeingSwitchedOff()
        {
            var store = new InMemoryKeyValueStore();
            var settings = new DeviceSettings(store);
            Assert.IsTrue(settings.SoundOn);
            Assert.IsTrue(settings.VibrationOn);

            settings.SoundOn = false;

            Assert.IsFalse(new DeviceSettings(store).SoundOn, "remembered by a fresh reader of the same store");
            Assert.IsTrue(new DeviceSettings(store).VibrationOn);
        }

        [Test]
        public void EveryPlaceholderSound_IsAudibleAndWithinRange()
        {
            foreach (SoundId sound in System.Enum.GetValues(typeof(SoundId)))
            {
                var samples = SoundBank.Samples(sound);

                Assert.Greater(samples.Length, SoundBank.SampleRate / 40, sound + " is more than a blip of silence");
                Assert.IsTrue(samples.All(s => !float.IsNaN(s) && s >= -1f && s <= 1f), sound + " stays in range");
                Assert.Greater(samples.Max(Mathf.Abs), 0.1f, sound + " is loud enough to hear");
            }
        }
    }
}
