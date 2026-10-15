using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>When the board offers a rematch (the web client's rules), and what it sets up.</summary>
    public class RematchTests
    {
        // Game 405 (you are bshaftoe, user 2, who made it; BotSage is user 18), played out and won by you.
        private static GameState Finished()
        {
            var state = BoardFixtures.Load(405);
            state.Game.Status = "completed";
            state.Game.WinnerUsernames = new List<string> { "bshaftoe" };
            state.Game.CreatedByUserId = 2;
            state.Game.IsTournamentMatch = false;
            foreach (var player in state.Players)
            {
                player.Resigned = false;
            }

            return state;
        }

        [Test]
        public void AGameYouMade_ThatWasPlayedOut_OffersARematch()
        {
            Assert.IsTrue(BoardDisplay.CanRematch(Finished()));
        }

        [Test]
        public void ItIsOnlyOfferedToTheGamesMaker()
        {
            var state = Finished();
            state.Game.CreatedByUserId = 18;

            Assert.IsFalse(BoardDisplay.CanRematch(state));
        }

        [Test]
        public void NotWhileTheGameIsStillOn()
        {
            var state = Finished();
            state.Game.Status = "in_progress";

            Assert.IsFalse(BoardDisplay.CanRematch(state));
        }

        [Test]
        public void NotForAGameSomeoneResignedFromOrNobodyWon()
        {
            var resigned = Finished();
            resigned.Players[1].Resigned = true;
            Assert.IsFalse(BoardDisplay.CanRematch(resigned));

            var nobody = Finished();
            nobody.Game.WinnerUsernames = new List<string>();
            Assert.IsFalse(BoardDisplay.CanRematch(nobody));
        }

        [Test]
        public void NotForATournamentMatchOrAPuzzle()
        {
            var tournament = Finished();
            tournament.Game.IsTournamentMatch = true;
            Assert.IsFalse(BoardDisplay.CanRematch(tournament));

            var puzzle = Finished();
            puzzle.Game.Format = "puzzle";
            Assert.IsFalse(BoardDisplay.CanRematch(puzzle));
        }

        [Test]
        public void ForAGameInAMatch_OnlyOnceTheWholeMatchIsOver()
        {
            var state = Finished();
            state.GameMatch = new MatchSummary { Status = "in_progress", NextGameId = 777 };
            Assert.IsFalse(BoardDisplay.CanRematch(state), "there is a next game to go on to");

            state.GameMatch = new MatchSummary { Status = "completed" };
            Assert.IsTrue(BoardDisplay.CanRematch(state));
        }

        [Test]
        public void TheRematch_IsTheSameOpponentsAndSettings()
        {
            var state = Finished();

            var setup = GameSetup.ForRematch(state, 2);

            Assert.IsNotNull(setup);
            Assert.AreEqual(state.Game.Format, setup.Format);
            Assert.AreEqual(state.Game.DeckType, setup.DeckType);
            CollectionAssert.AreEqual(new[] { 18 }, setup.OpponentUserIds);
            Assert.AreEqual("BotSage", setup.OpponentNames[18]);
            Assert.AreEqual(state.Game.WinsNeeded, setup.WinsNeeded);
            Assert.IsFalse(setup.BestOfThree);
        }

        [Test]
        public void AGameInABestOfThree_IsRematchedAsOne()
        {
            var state = Finished();
            state.GameMatch = new MatchSummary { Status = "completed" };

            Assert.IsTrue(GameSetup.ForRematch(state, 2).BestOfThree);
        }

        [Test]
        public void ADeckTheAppCannotSetUp_HasNoRematch()
        {
            var state = Finished();
            state.Game.DeckType = "quick_draft";

            Assert.IsNull(GameSetup.ForRematch(state, 2));
        }
    }
}
