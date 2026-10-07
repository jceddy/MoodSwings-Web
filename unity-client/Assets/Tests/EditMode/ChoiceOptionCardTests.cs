using System.Linq;
using MoodSwings.Core;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>An option that is a card carries the card, so the player can read it before choosing.</summary>
    public class ChoiceOptionCardTests
    {
        [Test]
        public void ChoosingAHandCard_EachOptionIsThatCard_InYourHand()
        {
            var state = BoardFixtures.Load(407);
            var decision = state.Round.PendingDecision;
            var form = ChoiceForm.ForDecision(state, decision);

            var options = form.OptionsFor(decision.Field);

            Assert.AreEqual(state.You.Hand.Count, options.Count);
            Assert.IsTrue(options.All(o => o.Card != null && o.Where == "In your hand"));
            CollectionAssert.AreEquivalent(state.You.Hand.Select(c => c.CardId), options.Select(o => o.Card.CardId));
        }

        [Test]
        public void ChoosingAMood_EachOptionIsThatMood_SayingWhoseItIs()
        {
            var state = BoardFixtures.Load(405);
            var decision = state.Round.PendingDecision;
            var form = ChoiceForm.ForDecision(state, decision);

            var options = form.OptionsFor(decision.Field);

            Assert.IsTrue(options.Count > 0);
            Assert.IsTrue(options.All(o => o.Card != null && o.Where.StartsWith("In play for ")));
        }

        [Test]
        public void ChoosingADiscardedCard_SaysWhoDiscardedIt()
        {
            var state = BoardFixtures.Load(406);
            var field = new MoodSwings.Networking.ChoiceField { Key = "card", Type = "discard_card" };
            var form = ChoiceForm.ForCard(state, state.You.Hand[0]);

            var options = form.OptionsFor(field);

            Assert.AreEqual(state.DiscardPile.Count, options.Count);
            Assert.AreEqual("Discarded from bshaftoe", options[0].Where);
            Assert.AreEqual(state.DiscardPile[0].CardId, options[0].Card.CardId);
        }

        [Test]
        public void AnOptionThatIsNotACard_HasNoCardToLookAt()
        {
            var state = BoardFixtures.Load(406);
            var field = new MoodSwings.Networking.ChoiceField { Key = "who", Type = "player" };
            var form = ChoiceForm.ForCard(state, state.You.Hand[0]);

            Assert.IsTrue(form.OptionsFor(field).All(o => o.Card == null));
        }
    }
}
