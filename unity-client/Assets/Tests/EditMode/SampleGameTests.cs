using System.IO;
using System.Linq;
using MoodSwings.Core;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>The tour's sample game: that its tables are right, and say what the commentary says.</summary>
    public class SampleGameTests
    {
        [SetUp]
        public void SetUp() =>
            SampleGame.LoadCards(File.ReadAllText(Path.Combine(Application.dataPath, "Resources", "Tour", "sample-cards.json")));

        private static SampleStep Step(string title) => SampleGame.Steps.Single(s => s.Title == title);

        private static (int you, int them) Scores(string title)
        {
            var state = Step(title).State;
            return (SampleGame.Score(state.You), SampleGame.Score(state.Them));
        }

        [Test]
        public void TheScoresInTheCommentary_AreWhatTheCardsAddUpTo()
        {
            Assert.AreEqual((4, 0), Scores("MarkBot plays Laziness"));
            Assert.AreEqual((4, 1), Scores("AriBot plays Charity"));
            Assert.AreEqual((4, 8), Scores("Superiority goes up to 7"));
            Assert.AreEqual((4, 11), Scores("Round two: AriBot plays Serenity"));
            Assert.AreEqual((6, 3), Scores("MarkBot plays Rage"));
            Assert.AreEqual((14, 4), Scores("MarkBot plays Betrayal"));
            Assert.AreEqual((14, 8), Scores("AriBot plays Indifference"));
            Assert.AreEqual(20, Scores("Round four: MarkBot plays Cheer").you);
            Assert.AreEqual((9, 10), Scores("AriBot plays Meekness"));
            Assert.AreEqual((21, 13), Scores("Meekness returns to AriBot's hand"));
        }

        [Test]
        public void TheRoundsAreWonInTheOrderTheCommentarySays()
        {
            (int you, int them) Wins(string title) => (Step(title).State.You.Wins, Step(title).State.Them.Wins);

            Assert.AreEqual((0, 1), Wins("AriBot wins round one"));
            Assert.AreEqual((1, 1), Wins("MarkBot wins round two"));
            Assert.AreEqual((2, 1), Wins("MarkBot wins round three"));
            Assert.AreEqual((2, 2), Wins("AriBot wins round four"));
            Assert.AreEqual((3, 2), Wins("MarkBot wins the game"));
            Assert.AreEqual(SampleGame.WinsNeeded, Step("MarkBot wins the game").State.You.Wins);
        }

        [Test]
        public void ACardTurnsUpsideDownWhenItsAlternateValueIsUsed_AndBackWhenItIsNot()
        {
            Assert.IsFalse(Step("AriBot plays Superiority").State.Them.Moods.Single(m => m.Card == "Superiority").Alt);
            Assert.IsTrue(Step("Superiority goes up to 7").State.Them.Moods.Single(m => m.Card == "Superiority").Alt, "it has more moods");
            Assert.IsFalse(Step("MarkBot plays Rage").State.Them.Moods.Single(m => m.Card == "Superiority").Alt, "and loses them");

            var cheer = Step("Round four: MarkBot plays Cheer").State.You.Moods.Single(m => m.Card == "Cheer");
            Assert.IsTrue(cheer.Alt && cheer.Locked, "Cheer's text locks its value in at 5");
        }

        [Test]
        public void SuppressedMoods_LieOnTheirSide_AndAreWorthNothing()
        {
            var you = Step("AriBot plays Meekness").State.You;

            Assert.IsTrue(you.Moods.Single(m => m.Card == "Cheer").Suppressed);
            Assert.IsTrue(you.Moods.Single(m => m.Card == "Betrayal").Suppressed);
            Assert.AreEqual(0, SampleGame.Value(you.Moods.Single(m => m.Card == "Cheer")));
            Assert.IsFalse(Step("Meekness returns to AriBot's hand").State.You.Moods.Any(m => m.Suppressed));

            // Drawn as the board draws a locked-in alternate value that is suppressed: a card on its side AND turned around.
            var card = SampleGame.ToBoardCard(you.Moods.Single(m => m.Card == "Cheer"), 1);
            Assert.IsTrue(card.IsSuppressed);
            Assert.IsTrue(card.IsAltValueActive);
        }

        [Test]
        public void EveryCardTheGameUsesIsInTheCardFile()
        {
            foreach (var step in SampleGame.Steps)
            {
                foreach (var seat in step.State.Seats)
                {
                    foreach (var mood in seat.Moods)
                    {
                        Assert.IsTrue(SampleGame.HasCard(mood.Card), step.Title + ": " + mood.Card);
                    }
                }

                foreach (var card in step.State.Discard)
                {
                    Assert.IsTrue(SampleGame.HasCard(card), step.Title + ": " + card + " in the discard pile");
                }
            }

            Assert.AreEqual(14, SampleGame.Card("Cheer").CatalogCardId == 0 ? 0 : 14, "the cards come with their art keys");
            Assert.AreEqual(5, SampleGame.Card("Cheer").AltValue);
            Assert.AreEqual(3, SampleGame.Card("Cheer").BaseValue);
        }

        [Test]
        public void ACardIsInOnePlaceAtATime_AndTheTablesFit()
        {
            foreach (var step in SampleGame.Steps)
            {
                var everywhere = step.State.Seats.SelectMany(s => s.Moods.Select(m => m.Card)).Concat(step.State.Discard).ToList();
                // Doubt and the others are in a hand, not on the table, until the game says otherwise; no card is in two places.
                CollectionAssert.AllItemsAreUnique(everywhere, step.Title);
                foreach (var seat in step.State.Seats)
                {
                    Assert.LessOrEqual(seat.Moods.Count, 7, step.Title + ": room for them on the table");
                    Assert.GreaterOrEqual(seat.Hand, 0, step.Title);
                }
            }
        }

        [Test]
        public void WhatEachStepPointsAt_IsOnItsTable()
        {
            foreach (var step in SampleGame.Steps)
            {
                Assert.IsNotEmpty(step.Focus, step.Title);
                Assert.LessOrEqual(step.Focus.Count, 6, step.Title);
                foreach (var key in step.Focus)
                {
                    var kind = key.Substring(0, key.IndexOf(':') < 0 ? key.Length : key.IndexOf(':'));
                    var name = key.Contains(":") ? key.Substring(key.IndexOf(':') + 1) : null;
                    switch (kind)
                    {
                        case "score":
                            Assert.IsNotNull(SampleGame.SeatOf(step.State, name), step.Title + ": " + key);
                            break;
                        case "mood":
                            Assert.IsTrue(step.State.Seats.Any(s => s.Moods.Any(m => m.Card == name)), step.Title + ": " + key + " is not on the table");
                            break;
                        case "discard":
                            break;
                        default:
                            Assert.Fail(step.Title + ": unknown focus " + key);
                            break;
                    }
                }
            }
        }

        [Test]
        public void EveryStepHasSomethingToSay_AndTheTitlesAreUnique()
        {
            foreach (var step in SampleGame.Steps)
            {
                Assert.Greater(step.Commentary.Length, 40, step.Title);
                Assert.IsNotNull(step.State.You);
                Assert.IsNotNull(step.State.Them);
            }

            CollectionAssert.AllItemsAreUnique(SampleGame.Steps.Select(s => s.Title).ToList());
            Assert.GreaterOrEqual(SampleGame.Steps.Count, 20);
        }

        [Test]
        public void TheSampleGameIsPartOfTheTour_BetweenTheTableAndTheEnd_WithAWayPastIt()
        {
            var ids = TourSteps.All.Select(s => s.Id).ToList();
            var intro = TourSteps.All.Single(s => s.Id == "sample-intro");

            Assert.AreEqual(ids.IndexOf("table") + 1, ids.IndexOf("sample-intro"));
            Assert.AreEqual(SampleGame.Steps.Count, TourSteps.All.Count(s => s.Sample != null));
            Assert.AreEqual("more-players", intro.SkipTo, "so the people-count rules are not lost with it");
            Assert.AreEqual(ids.IndexOf("sample-intro") + 1 + SampleGame.Steps.Count, ids.IndexOf("more-players"));
            Assert.AreEqual("done", TourSteps.All.Last().Id);
            Assert.IsTrue(TourSteps.All.Where(s => s.Sample != null).All(s => s.Target == null && s.Screen == TourScreen.Home));
        }

        [Test]
        public void TheLastBitOfTheCommentary_IsAboutMorePlayers()
        {
            var more = TourSteps.All.Single(s => s.Id == "more-players");

            StringAssert.Contains("Hurt Feelings", more.Body);
            StringAssert.Contains("extra play", more.Body);
        }
    }
}
