using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>
    /// The puzzle collection: standalone solitaire puzzles, each a fixed hand and board with a goal. Starting one
    /// makes a one-seat game (or two, with a fixed opponent board) that plays on the ordinary board. UI-free.
    /// </summary>
    public sealed class PuzzleFlow
    {
        private readonly ApiClient _api;

        public PuzzleFlow(ApiClient api)
        {
            _api = api;
        }

        /// <summary>Easiest first, as the server lists them.</summary>
        public IReadOnlyList<PuzzleInfo> Puzzles { get; private set; } = new List<PuzzleInfo>();

        public event Action Changed;

        public async Task<LobbyResult> RefreshAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.ListPuzzlesAsync(cancellationToken);
            if (!result.Ok)
            {
                return new LobbyResult { Message = result.UserMessage("Couldn't load the puzzles.") };
            }

            Puzzles = result.Value.Puzzles;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Starts a fresh attempt (also how "try again" works); the new game's id is in the result.</summary>
        public async Task<LobbyResult> StartAsync(PuzzleInfo puzzle, CancellationToken cancellationToken = default)
        {
            var result = await _api.StartPuzzleAttemptAsync(puzzle.Id, cancellationToken);
            return result.Ok
                ? new LobbyResult { Ok = true, GameId = result.Value.GameId }
                : new LobbyResult { Message = result.UserMessage("Couldn't start that puzzle.") };
        }

        public int SolvedCount => Puzzles.Count(p => p.Solved);

        public void Clear()
        {
            Puzzles = new List<PuzzleInfo>();
            Changed?.Invoke();
        }
    }

    /// <summary>How a puzzle reads in the list.</summary>
    public static class PuzzleDisplay
    {
        public static string DifficultyName(string difficulty) =>
            string.IsNullOrEmpty(difficulty) ? string.Empty : char.ToUpperInvariant(difficulty[0]) + difficulty.Substring(1);

        /// <summary>"Easy  -  solved in 2 plays (3 times)", or "Medium  -  not solved yet".</summary>
        public static string Progress(PuzzleInfo puzzle)
        {
            var difficulty = DifficultyName(puzzle.Difficulty);
            if (!puzzle.Solved)
            {
                return difficulty + "  -  not solved yet";
            }

            var best = puzzle.BestPlays.HasValue ? $"best {puzzle.BestPlays} {(puzzle.BestPlays == 1 ? "play" : "plays")}" : "solved";
            var times = puzzle.SolveCount > 1 ? $", solved {puzzle.SolveCount} times" : string.Empty;
            return $"{difficulty}  -  solved ({best}{times})";
        }

        /// <summary>"Within 3 plays, in a single turn" for a puzzle with a play limit; null otherwise.</summary>
        public static string Limit(PuzzleInfo puzzle) =>
            puzzle.MaxPlays.HasValue
                ? $"Within {puzzle.MaxPlays} {(puzzle.MaxPlays == 1 ? "play" : "plays")}, in a single turn"
                : null;
    }
}
