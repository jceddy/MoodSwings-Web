using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The puzzle collection and a puzzle's board. The list is written the way GET /puzzles documents it, with made-up
    /// puzzles: no puzzle's own content belongs in tests.
    /// </summary>
    public class PuzzleTests
    {
        private const string TwoPuzzles =
            @"{""status"":""ok"",""puzzles"":[
                {""id"":1,""slug"":""first"",""title"":""First Test Puzzle"",""description"":""Do the first thing."",""difficulty"":""easy"",
                 ""max_plays"":null,""solved"":true,""first_solved_at"":""2026-09-01 10:00:00"",""best_plays"":2,""solve_count"":3},
                {""id"":2,""slug"":""second"",""title"":""Second Test Puzzle"",""description"":""Do the second thing."",""difficulty"":""hard"",
                 ""max_plays"":3,""solved"":false,""first_solved_at"":null,""best_plays"":null,""solve_count"":0}]}";

        private FakeHttpTransport _transport;
        private PuzzleFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _flow = new PuzzleFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        [Test]
        public void TheList_ParsesWithNullsForWhatWasNeverDone()
        {
            _transport.Enqueue(200, TwoPuzzles);

            Assert.IsTrue(_flow.RefreshAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/puzzles", _transport.LastRequest.Url);
            Assert.AreEqual(2, _flow.Puzzles.Count);
            Assert.AreEqual(1, _flow.SolvedCount);
            Assert.AreEqual(2, _flow.Puzzles[0].BestPlays);
            Assert.IsNull(_flow.Puzzles[1].BestPlays);
            Assert.AreEqual(3, _flow.Puzzles[1].MaxPlays);
        }

        [Test]
        public void RefreshingRaisesChanged_AndAFailureKeepsWhatWasThere()
        {
            var changes = 0;
            _flow.Changed += () => changes++;
            _transport.Enqueue(200, TwoPuzzles);
            _flow.RefreshAsync().GetAwaiter().GetResult();

            _transport.EnqueueNetworkError("down");
            var failed = _flow.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(failed.Ok);
            StringAssert.Contains("Can't reach the server", failed.Message);
            Assert.AreEqual(2, _flow.Puzzles.Count);
            Assert.AreEqual(1, changes);
        }

        [Test]
        public void StartingAPuzzle_PostsItsIdAndReturnsTheNewGame()
        {
            _transport.Enqueue(200, TwoPuzzles);
            _flow.RefreshAsync().GetAwaiter().GetResult();
            _transport.Enqueue(201, @"{""status"":""ok"",""game_id"":912}");

            var result = _flow.StartAsync(_flow.Puzzles[1]).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(912, result.GameId);
            Assert.AreEqual("https://example.test/app/puzzles/attempt", _transport.LastRequest.Url);
            StringAssert.Contains("\"puzzle_id\":2", _transport.LastRequest.Body);
        }

        [Test]
        public void APuzzleTheServerWontStart_ExplainsWhy()
        {
            _transport.Enqueue(400, @"{""status"":""error"",""message"":""No such puzzle.""}");

            var result = _flow.StartAsync(new PuzzleInfo { Id = 99 }).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("No such puzzle.", result.Message);
        }

        [Test]
        public void TheListClears_WhenSomeoneLogsOut()
        {
            _transport.Enqueue(200, TwoPuzzles);
            _flow.RefreshAsync().GetAwaiter().GetResult();

            _flow.Clear();

            Assert.AreEqual(0, _flow.Puzzles.Count);
        }

        [Test]
        public void EachPuzzle_SaysHowYouHaveDone_AndItsLimit()
        {
            var solved = JsonConvert.DeserializeObject<PuzzlesResponse>(TwoPuzzles).Puzzles;

            Assert.AreEqual("Easy  -  solved (best 2 plays, solved 3 times)", PuzzleDisplay.Progress(solved[0]));
            Assert.AreEqual("Hard  -  not solved yet", PuzzleDisplay.Progress(solved[1]));
            Assert.IsNull(PuzzleDisplay.Limit(solved[0]));
            Assert.AreEqual("Within 3 plays, in a single turn", PuzzleDisplay.Limit(solved[1]));
            Assert.AreEqual("Within 1 play, in a single turn", PuzzleDisplay.Limit(new PuzzleInfo { MaxPlays = 1 }));
            Assert.AreEqual("Medium  -  solved (best 1 play)", PuzzleDisplay.Progress(new PuzzleInfo { Difficulty = "medium", Solved = true, BestPlays = 1, SolveCount = 1 }));
        }

        // --- a puzzle's board ---------------------------------------------------------------------------

        private static GameState Puzzle(int? plays = null, string status = "in_progress")
        {
            var state = BoardFixtures.Load(405);
            state.Round.PendingDecision = null;
            state.Game.Format = "puzzle";
            state.Game.Status = status;
            state.Game.PuzzleDescription = "Do the first thing.";
            state.Game.PuzzlePlaysMade = plays;
            return state;
        }

        [Test]
        public void APuzzleBoard_IsPlayable_AndHasNoRoundsToWin()
        {
            var state = Puzzle(plays: 2);
            state.You.IsYourTurn = true;
            state.Round.CurrentTurnGamePlayerId = state.You.GamePlayerId;

            Assert.IsNull(BoardDisplay.UnsupportedReason(state));
            Assert.IsTrue(BoardDisplay.CanAct(state));
            Assert.AreEqual("Puzzle  -  2 plays so far", BoardDisplay.RoundLine(state));
            Assert.AreEqual("Puzzle", BoardDisplay.RoundLine(Puzzle()));
            Assert.AreEqual("Your turn", BoardDisplay.TurnBanner(state));
        }

        [Test]
        public void ASolvedPuzzle_SaysHowManyPlaysItTook()
        {
            Assert.AreEqual("Puzzle solved in 3 plays!", BoardDisplay.TurnBanner(Puzzle(3, "completed")));
            Assert.AreEqual("Puzzle solved in 1 play!", BoardDisplay.TurnBanner(Puzzle(1, "completed")));
            Assert.AreEqual("Puzzle solved!", BoardDisplay.TurnBanner(Puzzle(null, "completed")));
        }

        [Test]
        public void ThereIsNoResigningAPuzzle_JustLeaveIt()
        {
            Assert.IsFalse(BoardDisplay.CanResign(Puzzle()));
        }

        [Test]
        public void TheServersPuzzleFields_Parse()
        {
            var state = JsonConvert.DeserializeObject<GameState>(
                @"{""game"":{""format"":""puzzle"",""puzzle_description"":""Do the thing."",""puzzle_hint"":""Mind the trap."",""puzzle_plays_made"":4}}");

            Assert.AreEqual("Do the thing.", state.Game.PuzzleDescription);
            Assert.AreEqual("Mind the trap.", state.Game.PuzzleHint);
            Assert.AreEqual(4, state.Game.PuzzlePlaysMade);
        }

        [Test]
        public void SolvingAPuzzle_IsAWinCue_WithTheSolveText()
        {
            var before = Puzzle(2);
            var after = Puzzle(3, "completed");
            after.Game.WinnerUsernames = new System.Collections.Generic.List<string> { "bshaftoe" };

            var cue = BoardCues.Between(before, after).Single();

            Assert.AreEqual(CueKind.GameWon, cue.Kind);
            Assert.AreEqual("Puzzle solved in 3 plays!", cue.Text);
        }
    }
}
