using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Which moods are acting on which, and which have an ongoing effect, from what the server says about them.</summary>
    public class BoardEffectsTests
    {
        private static BoardCard Card(int id, string name = null, string rules = null, int value = 3, int baseValue = 3) =>
            new BoardCard { CardId = id, Name = name ?? "Card" + id, RulesText = rules, Value = value, BaseValue = baseValue, Color = "red", BaseColor = "red" };

        private static GameState Table(params BoardCard[] cards) =>
            new GameState { InPlay = cards.ToList(), Round = new BoardRound() };

        [Test]
        public void ASuppression_IsALineFromWhatSuppressesToWhatIsSuppressed()
        {
            var scorn = Card(1, "Scorn");
            var laziness = Card(2, "Laziness");
            laziness.IsSuppressed = true;
            laziness.Suppressions.Add(new Suppression { SuppressedByCardId = 1, SuppressedByName = "Scorn", Expiry = "while_source_in_play" });

            var links = BoardEffects.Links(Table(scorn, laziness));

            CollectionAssert.AreEqual(new[] { new EffectLink(1, 2, EffectLinkKind.Suppress) }, links);
        }

        [Test]
        public void TheSameEffect_SaidFromBothEnds_IsOneLine()
        {
            var scorn = Card(1, "Scorn");
            scorn.Affecting.Add(new AffectingEntry { CardId = 2, Name = "Laziness", Relationship = "suppressed" });
            var laziness = Card(2, "Laziness");
            laziness.IsSuppressed = true;
            laziness.Suppressions.Add(new Suppression { SuppressedByCardId = 1 });

            Assert.AreEqual(1, BoardEffects.Links(Table(scorn, laziness)).Count);
        }

        [Test]
        public void ADiceValueBoost_AndAMoodHeldForSomeoneElse_AreLinesToo()
        {
            var booster = Card(1);
            var boosted = Card(2);
            boosted.BoostedByCardId = 1;
            var stolen = Card(3);
            stolen.TemporaryOwnership = new TemporaryOwnership { SourceCardId = 1, SourceCardName = "Arrogance", Reverts = "when_source_leaves_play" };

            var links = BoardEffects.Links(Table(booster, boosted, stolen));

            CollectionAssert.AreEquivalent(
                new[] { new EffectLink(1, 2, EffectLinkKind.Boost), new EffectLink(1, 3, EffectLinkKind.Control) }, links);
        }

        [Test]
        public void ALine_NeedsBothMoodsToBeInPlay()
        {
            var laziness = Card(2);
            laziness.Suppressions.Add(new Suppression { SuppressedByCardId = 99 }); // it left play

            Assert.IsEmpty(BoardEffects.Links(Table(laziness)));
        }

        [Test]
        public void AMoodWhoseWhileInPlayEffectHasMovedItsValue_IsAffectingTheGame()
        {
            var loyalty = Card(1, rules: "While in play — This mood's value is 6 if there are two or more blue moods.", value: 6, baseValue: 3);
            var state = Table(loyalty);

            Assert.IsTrue(BoardEffects.IsAffectingTheGame(state, loyalty));
        }

        [Test]
        public void AWhileInPlayEffectWaitingForItsCondition_IsNot()
        {
            var loyalty = Card(1, rules: "While in play — This mood's value is 6 if there are two or more blue moods.");

            Assert.IsFalse(BoardEffects.IsAffectingTheGame(Table(loyalty), loyalty));
        }

        [Test]
        public void ASuppressedMood_IsNotAffectingAnything()
        {
            var card = Card(1, rules: "While in play — Something.", value: 0, baseValue: 3);
            card.IsSuppressed = true;

            Assert.IsFalse(BoardEffects.IsAffectingTheGame(Table(card), card));
        }

        [Test]
        public void AMoodTheServerListsAmongTheScoringEffects_IsAffectingTheGame()
        {
            var card = Card(1, rules: "While in play — Each round you score double.");
            var state = Table(card);
            state.Round.ScoringEffects.Add(new EffectNote { CardId = 1, CardName = card.Name, Description = "doubles" });

            Assert.IsTrue(BoardEffects.IsAffectingTheGame(state, card));
        }

        [Test]
        public void ASourceOfALine_IsAffectingTheGame_ButOnlyIfItsRulesSayWhileInPlay()
        {
            var scorn = Card(1, rules: "While in play — Suppresses a mood.");
            var plain = Card(3, rules: "After playing this mood — suppress a mood.");
            var target = Card(2);
            target.IsSuppressed = true;
            target.Suppressions.Add(new Suppression { SuppressedByCardId = 1 });
            var state = Table(scorn, target, plain);

            Assert.IsTrue(BoardEffects.IsAffectingTheGame(state, scorn));
            Assert.IsFalse(BoardEffects.IsAffectingTheGame(state, plain));
        }

        [Test]
        public void ACreativityCopy_SaysSo_ButAnotherCardDoesNot()
        {
            var copy = Card(1, "Shock");
            copy.IsCreativityCopy = true;

            Assert.AreEqual("A copy made by Creativity, playing as Shock", BoardDisplay.CreativityCopyNote(copy));
            Assert.IsNull(BoardDisplay.CreativityCopyNote(Card(2)));
            Assert.IsNull(BoardDisplay.CreativityCopyNote(null));
        }

        [Test]
        public void TheEffectsInTheCapturedGames_ParseWithoutTheNewFields()
        {
            foreach (var id in new[] { 405, 406, 407 })
            {
                var state = BoardFixtures.Load(id);
                Assert.DoesNotThrow(() => BoardEffects.Links(state));
                Assert.IsTrue(state.InPlay.All(c => c.Affecting != null));
            }
        }
    }
}
