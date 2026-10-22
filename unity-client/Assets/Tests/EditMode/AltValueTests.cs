using MoodSwings.Core;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>When a mood's alternate value is the one in effect, so the table turns it around.</summary>
    public class AltValueTests
    {
        // Chivalry-like: worth 3, 5 under its condition.
        private static BoardCard Mood(int value, int baseValue = 3, int? alt = 5) =>
            new BoardCard { CardId = 1, Name = "Chivalry", Value = value, BaseValue = baseValue, AltValue = alt, HasDiceValue = alt.HasValue };

        [Test]
        public void ACardWithNoAlternateValue_IsNeverTurnedAround()
        {
            Assert.IsFalse(Mood(3, alt: null).IsAltValueActive);
            Assert.IsFalse(Mood(7, alt: null).IsAltValueActive, "its value moved, but not to an alternate one");
        }

        [Test]
        public void ALiveCondition_TurnsItAround_WhileItHolds()
        {
            Assert.IsFalse(Mood(3).IsAltValueActive, "worth its printed value");
            Assert.IsTrue(Mood(5).IsAltValueActive, "worth its alternate value");
        }

        [Test]
        public void AValueThatIsSomethingElse_IsNotTheAlternate()
        {
            Assert.IsFalse(Mood(4).IsAltValueActive);
            Assert.IsFalse(Mood(0).IsAltValueActive);
        }

        [Test]
        public void AnAlternateValueBelowThePrintedOne_ShowsWhenItTakesOver_NotWhenTheHigherOneIsKept()
        {
            // Discipline-like: 6, and 3 under its condition. Encouragement and Idealism take the higher of the two.
            Assert.IsFalse(Mood(6, baseValue: 6, alt: 3).IsAltValueActive, "boosted: still 6, the printed one");
            Assert.IsTrue(Mood(3, baseValue: 6, alt: 3).IsAltValueActive, "its own condition holds: 3");
        }

        [Test]
        public void ALockedInValue_TurnsItAround()
        {
            var locked = Mood(5);
            locked.ValueLocked = true;

            Assert.IsTrue(locked.IsAltValueActive);
        }

        [Test]
        public void ASuppressedMood_IsNotTurnedAround_ByALiveCondition_ButIsByALockedValue()
        {
            var live = Mood(0);
            live.IsSuppressed = true;
            Assert.IsFalse(live.IsAltValueActive, "its value is 0: the alternate isn't in effect");

            var locked = Mood(0);
            locked.IsSuppressed = true;
            locked.ValueLocked = true;
            Assert.IsTrue(locked.IsAltValueActive, "the value was locked in before it was suppressed");
        }

        [Test]
        public void AChaosEffectThatFixesTheValue_DoesNotTurnItAround()
        {
            var fixedValue = Mood(5);
            fixedValue.ChaosValueOverride = 5;
            fixedValue.ValueLocked = true;

            Assert.IsFalse(fixedValue.IsAltValueActive);
        }

        [Test]
        public void AChaosAdjustmentOnTop_IsLeftOutOfTheComparison()
        {
            // Its own value is 5 (the alternate), and a chaos effect has added 2 on top.
            var raised = Mood(7);
            raised.ChaosValueDelta = 2;
            Assert.IsTrue(raised.IsAltValueActive);

            // Its own value is 3, and the same effect has raised it to 5: that is not the alternate value.
            var lifted = Mood(5);
            lifted.ChaosValueDelta = 2;
            Assert.IsFalse(lifted.IsAltValueActive);
        }

        [Test]
        public void AnAlternateValueEqualToThePrintedOne_HasNothingToTurn()
        {
            Assert.IsFalse(Mood(3, baseValue: 3, alt: 3).IsAltValueActive);
        }

        [Test]
        public void TheCloseUp_SaysSoWhenItIsTurnedAround()
        {
            var note = BoardDisplay.AltValueNote(Mood(5));

            StringAssert.Contains("alternate value (5)", note);
            StringAssert.Contains("turned around", note);
            Assert.IsNull(BoardDisplay.AltValueNote(Mood(3)));
            Assert.IsNull(BoardDisplay.AltValueNote(null));
        }

        [Test]
        public void TheFlagComesFromWhatTheServerSends()
        {
            var card = Newtonsoft.Json.JsonConvert.DeserializeObject<BoardCard>(
                "{\"card_id\":5,\"name\":\"Tranquility\",\"value\":6,\"base_value\":3,\"alt_value\":6,\"has_dice_value\":true,"
                + "\"value_locked\":false,\"chaos_value_delta\":0,\"chaos_value_override\":null,\"is_suppressed\":false}");

            Assert.IsTrue(card.IsAltValueActive);
        }
    }
}
