using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    public enum CueKind
    {
        /// <summary>A mood entered play.</summary>
        CardPlayed,

        /// <summary>The turn just became the viewer's.</summary>
        YourTurn,

        /// <summary>A card effect started waiting on the viewer's answer.</summary>
        DecisionForYou,

        /// <summary>A round was scored and the viewer won it.</summary>
        RoundWon,

        /// <summary>A round was scored and someone else won it.</summary>
        RoundLost,

        /// <summary>A round was scored with no winner, or the viewer is only watching.</summary>
        RoundOver,

        GameWon,

        GameLost,

        /// <summary>The game ended and the viewer is only watching.</summary>
        GameOver,

        /// <summary>Someone else said something.</summary>
        Chat,
    }

    /// <summary>Something that just happened on the board, worth a sound, a buzz or a moment of animation.</summary>
    public sealed class BoardCue
    {
        public CueKind Kind { get; set; }

        /// <summary>CardPlayed: which mood.</summary>
        public int CardId { get; set; }

        /// <summary>CardPlayed: whose seat it entered.</summary>
        public int PlayerId { get; set; }

        /// <summary>For the end of a round or game: what to tell the player.</summary>
        public string Text { get; set; }
    }

    /// <summary>
    /// Works out what happened between two looks at the same game, so the board can react
    /// to it (a card slides in, a chime for your turn) instead of just redrawing. UI-free.
    /// </summary>
    public static class BoardCues
    {
        /// <summary>
        /// The cues for the change from <paramref name="before"/> to <paramref name="after"/>.
        /// Nothing when there is no earlier look (the first draw of a game is not an event) or
        /// the two aren't the same game.
        /// </summary>
        public static List<BoardCue> Between(GameState before, GameState after)
        {
            var cues = new List<BoardCue>();
            if (before == null || after == null || before.Game.Id != after.Game.Id)
            {
                return cues;
            }

            var viewer = BoardDisplay.Viewer(after);
            var known = new HashSet<int>(before.InPlay.Select(c => c.CardId));
            foreach (var mood in after.InPlay.Where(c => !known.Contains(c.CardId)))
            {
                cues.Add(new BoardCue
                {
                    Kind = CueKind.CardPlayed,
                    CardId = mood.CardId,
                    PlayerId = mood.OwnerGamePlayerId ?? 0,
                });
            }

            var gameEnded = before.Game.Status != "completed" && after.Game.Status == "completed";
            if (gameEnded)
            {
                cues.Add(GameEndCue(after, viewer));
            }
            else if (after.Round.RoundNumber > before.Round.RoundNumber && before.Round.RoundNumber > 0)
            {
                cues.Add(RoundEndCue(before, after, viewer));
            }

            if (viewer != null && after.Game.Status == "in_progress")
            {
                var wasMyTurn = before.Round.CurrentTurnGamePlayerId == viewer.GamePlayerId;
                var isMyTurn = after.Round.CurrentTurnGamePlayerId == viewer.GamePlayerId;
                if (isMyTurn && !wasMyTurn)
                {
                    cues.Add(new BoardCue { Kind = CueKind.YourTurn });
                }

                var decision = after.Round.PendingDecision;
                var previous = before.Round.PendingDecision;
                var isNew = previous == null
                    || previous.DecisionType != decision?.DecisionType
                    || previous.PlayedCardId != decision?.PlayedCardId
                    || !previous.IsYou;
                if (decision != null && decision.IsYou && isNew)
                {
                    cues.Add(new BoardCue { Kind = CueKind.DecisionForYou });
                }
            }

            var newestBefore = before.ChatMessages.Count == 0 ? 0 : before.ChatMessages.Max(m => m.Id);
            var fromOthers = after.ChatMessages.Where(m => m.Id > newestBefore && (viewer == null || m.SenderUsername != viewer.Username));
            if (fromOthers.Any())
            {
                cues.Add(new BoardCue { Kind = CueKind.Chat });
            }

            return cues;
        }

        private static BoardCue GameEndCue(GameState after, BoardPlayer viewer)
        {
            if (BoardDisplay.IsPuzzle(after))
            {
                return new BoardCue { Kind = CueKind.GameWon, Text = BoardDisplay.TurnBanner(after) };
            }

            var winners = after.Game.WinnerUsernames.Count == 0 ? "Nobody" : string.Join(" and ", after.Game.WinnerUsernames);
            if (viewer == null)
            {
                return new BoardCue { Kind = CueKind.GameOver, Text = $"{winners} won the game." };
            }

            return after.Game.WinnerUsernames.Contains(viewer.Username)
                ? new BoardCue { Kind = CueKind.GameWon, Text = "You won the game!" }
                : new BoardCue { Kind = CueKind.GameLost, Text = $"{winners} won the game." };
        }

        private static BoardCue RoundEndCue(GameState before, GameState after, BoardPlayer viewer)
        {
            if (after.Teams != null && before.Teams != null)
            {
                return TeamRoundEndCue(before, after, viewer);
            }

            // Whoever's tally of rounds won went up won it (a tie leaves everyone's where it was).
            var winners = after.Players
                .Where(p => p.TotalWins > (BoardDisplay.PlayerById(before, p.GamePlayerId)?.TotalWins ?? 0))
                .ToList();

            if (winners.Count == 0)
            {
                return new BoardCue { Kind = CueKind.RoundOver, Text = "The round ended with no winner." };
            }

            var viewerWon = viewer != null && winners.Any(w => w.GamePlayerId == viewer.GamePlayerId);
            if (viewer == null)
            {
                return new BoardCue { Kind = CueKind.RoundOver, Text = $"{Names(winners)} won the round." };
            }

            return viewerWon
                ? new BoardCue { Kind = CueKind.RoundWon, Text = "You won the round!" }
                : new BoardCue { Kind = CueKind.RoundLost, Text = $"{Names(winners)} won the round." };
        }

        // In team play the teams win rounds, not the players.
        private static BoardCue TeamRoundEndCue(GameState before, GameState after, BoardPlayer viewer)
        {
            var winningTeams = after.Teams
                .Where(t => t.TotalWins > (before.Teams.FirstOrDefault(b => b.TeamId == t.TeamId)?.TotalWins ?? 0))
                .ToList();
            if (winningTeams.Count == 0)
            {
                return new BoardCue { Kind = CueKind.RoundOver, Text = "The round ended with no winner." };
            }

            var winner = winningTeams[0];
            if (viewer == null)
            {
                return new BoardCue { Kind = CueKind.RoundOver, Text = $"{BoardDisplay.TeamLabel(after, winner)} won the round." };
            }

            return winner.GamePlayerIds.Contains(viewer.GamePlayerId)
                ? new BoardCue { Kind = CueKind.RoundWon, Text = "Your team won the round!" }
                : new BoardCue { Kind = CueKind.RoundLost, Text = $"{BoardDisplay.TeamLabel(after, winner)} won the round." };
        }

        private static string Names(List<BoardPlayer> players) => string.Join(" and ", players.Select(p => p.Username));
    }
}
