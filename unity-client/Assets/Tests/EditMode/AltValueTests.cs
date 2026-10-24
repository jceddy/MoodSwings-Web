using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// When a mood is turned around on the table for being on its alternate value: when the server says so -- its own while-in-play
    /// condition holds (alt_value_active) or the value was locked in (value_locked), the same two flags the web client turns a card for.
    /// </summary>
    public class AltValueTests
    {
        private static BoardCard Mood() =>
            new BoardCard { CardId = 1, Name = "Chivalry", Value = 3, BaseValue = 3, AltValue = 5, HasDiceValue = true };

        [Test]
        public void ANormalMood_IsNotTurnedAround()
        {
            Assert.IsFalse(Mood().IsAltValueActive);
        }

        [Test]
        public void AMoodWhoseOwnConditionHolds_IsTurnedAround_AccordingToTheServer()
        {
            var card = Mood();
            card.WhileInPlayAltValueActive = true;

            Assert.IsTrue(card.IsAltValueActive);
        }

        [Test]
        public void ALockedInValue_IsTurnedAround()
        {
            var card = Mood();
            card.ValueLocked = true;

            Assert.IsTrue(card.IsAltValueActive);
        }

        [Test]
        public void TheClientDoesNotWorkItOutFromTheNumbers_ItGoesByTheServersFlags()
        {
            // Worth its alternate value as far as the numbers go (a dice boost from Encouragement, say), but the server does
            // not say its own condition holds, and nothing is locked in: not turned.
            var boosted = Mood();
            boosted.Value = 5;
            boosted.BoostedByCardId = 99;
            Assert.IsFalse(boosted.IsAltValueActive);

            // And the other way round: the flag says so whatever the numbers look like.
            var flagged = Mood();
            flagged.WhileInPlayAltValueActive = true;
            flagged.Value = 3;
            Assert.IsTrue(flagged.IsAltValueActive);
        }

        [Test]
        public void TheFlagsComeFromWhatTheServerSends()
        {
            var live = JsonConvert.DeserializeObject<BoardCard>(
                "{\"card_id\":5,\"name\":\"Tranquility\",\"value\":6,\"base_value\":3,\"alt_value\":6,\"has_dice_value\":true,"
                + "\"value_locked\":false,\"alt_value_active\":true,\"is_suppressed\":false}");
            var locked = JsonConvert.DeserializeObject<BoardCard>(
                "{\"card_id\":6,\"name\":\"Dignity\",\"value\":5,\"base_value\":3,\"alt_value\":5,\"value_locked\":true,\"alt_value_active\":false}");
            var plain = JsonConvert.DeserializeObject<BoardCard>("{\"card_id\":7,\"name\":\"Calm\",\"value\":3,\"base_value\":3}");

            Assert.IsTrue(live.WhileInPlayAltValueActive);
            Assert.IsTrue(live.IsAltValueActive);
            Assert.IsFalse(locked.WhileInPlayAltValueActive);
            Assert.IsTrue(locked.IsAltValueActive, "locked in, which has its own flag");
            Assert.IsFalse(plain.IsAltValueActive, "a state from a server that doesn't send the flag: nothing turned");
        }

        [Test]
        public void ACardTurnedOntoItsPrintedAlternateValue_NeedsNoBadgeForIt()
        {
            // Worth exactly the alternate value, which is printed on the turned art itself.
            var live = Mood();
            live.WhileInPlayAltValueActive = true;
            live.Value = 5;
            Assert.IsTrue(live.ShowsPrintedAltValue);

            var locked = Mood();
            locked.ValueLocked = true;
            locked.Value = 5;
            Assert.IsTrue(locked.ShowsPrintedAltValue);
        }

        [Test]
        public void AValueThatIsNotThePrintedAlternate_StillNeedsItsBadge()
        {
            // Turned, but worth something else: a chaos adjustment on top of it.
            var adjusted = Mood();
            adjusted.WhileInPlayAltValueActive = true;
            adjusted.Value = 7;
            adjusted.ChaosValueDelta = 2;
            Assert.IsFalse(adjusted.ShowsPrintedAltValue);

            // Turned, and the delta nets out to the alternate value, but it is still a chaos adjustment, which has its own badge.
            var netted = Mood();
            netted.WhileInPlayAltValueActive = true;
            netted.Value = 5;
            netted.ChaosValueDelta = 1;
            Assert.IsFalse(netted.ShowsPrintedAltValue);

            // Locked in at a value that is not the printed alternate.
            var odd = Mood();
            odd.ValueLocked = true;
            odd.Value = 4;
            Assert.IsFalse(odd.ShowsPrintedAltValue);

            // Not turned at all.
            var plain = Mood();
            plain.Value = 5;
            Assert.IsFalse(plain.ShowsPrintedAltValue);

            // No alternate value to show.
            var none = Mood();
            none.AltValue = null;
            none.ValueLocked = true;
            Assert.IsFalse(none.ShowsPrintedAltValue);
        }

        [Test]
        public void TheCloseUp_SaysSoWhenItIsTurnedAround()
        {
            var live = Mood();
            live.WhileInPlayAltValueActive = true;

            var note = BoardDisplay.AltValueNote(live);

            StringAssert.Contains("alternate value (5)", note);
            StringAssert.Contains("turned around", note);
            Assert.IsNull(BoardDisplay.AltValueNote(Mood()));
            Assert.IsNull(BoardDisplay.AltValueNote(null));

            var noNumber = Mood();
            noNumber.AltValue = null;
            noNumber.ValueLocked = true;
            StringAssert.Contains("alternate value is", BoardDisplay.AltValueNote(noNumber));
        }
    }
}
