using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>Turns a game state into the short texts the board shows, from the viewer's point of view.</summary>
    public static class BoardDisplay
    {
        public static BoardPlayer PlayerById(GameState state, int? gamePlayerId) =>
            gamePlayerId.HasValue ? state.Players.FirstOrDefault(p => p.GamePlayerId == gamePlayerId.Value) : null;

        /// <summary>The viewer's own seat, or null for a spectator.</summary>
        public static BoardPlayer Viewer(GameState state) => PlayerById(state, state.You?.GamePlayerId);

        public static bool IsSpectator(GameState state) => Viewer(state) == null;

        /// <summary>The moods sitting in front of one player.</summary>
        public static List<BoardCard> MoodsOf(GameState state, int gamePlayerId) =>
            state.InPlay.Where(c => c.OwnerGamePlayerId == gamePlayerId).ToList();

        /// <summary>"Round 2  -  First to 3 wins".</summary>
        public static string RoundLine(GameState state)
        {
            var round = state.Round.RoundNumber > 0 ? "Round " + state.Round.RoundNumber : "Not started";
            return state.Game.WinsNeeded > 0 ? $"{round}  -  First to {state.Game.WinsNeeded} wins" : round;
        }

        /// <summary>
        /// What's happening right now: whose turn it is, or who a card effect is
        /// waiting on, or the result if the game is over.
        /// </summary>
        public static string TurnBanner(GameState state)
        {
            var viewer = Viewer(state);

            if (state.Game.Status == "completed")
            {
                if (state.Game.WinnerUsernames.Count == 0)
                {
                    return "Game over";
                }

                var viewerWon = viewer != null && state.Game.WinnerUsernames.Contains(viewer.Username);
                return viewerWon ? "You won!" : "Game over  -  " + string.Join(", ", state.Game.WinnerUsernames) + " won";
            }

            var decision = state.Round.PendingDecision;
            if (decision != null)
            {
                if (decision.IsYou)
                {
                    return string.IsNullOrEmpty(decision.PlayedCardName)
                        ? "Your response is needed"
                        : $"Your response is needed  -  {decision.PlayedCardName}";
                }

                var waitingOn = PlayerById(state, decision.TargetGamePlayerId) ?? PlayerById(state, decision.InitiatingGamePlayerId);
                var who = waitingOn != null ? waitingOn.Username : "another player";
                return string.IsNullOrEmpty(decision.PlayedCardName)
                    ? $"Waiting for {who} to respond"
                    : $"Waiting for {who} to respond  -  {decision.PlayedCardName}";
            }

            var current = PlayerById(state, state.Round.CurrentTurnGamePlayerId);
            if (current == null)
            {
                return Prettify(state.Game.Status);
            }

            if (viewer != null && current.GamePlayerId == viewer.GamePlayerId)
            {
                return state.Round.PlaysRemaining > 1 ? $"Your turn  -  {state.Round.PlaysRemaining} plays left" : "Your turn";
            }

            return current.Username + "'s turn";
        }

        /// <summary>Whether to draw attention to the banner: it's on the viewer.</summary>
        public static bool BannerNeedsViewer(GameState state)
        {
            if (state.Game.Status == "completed")
            {
                return false;
            }

            var decision = state.Round.PendingDecision;
            if (decision != null)
            {
                return decision.IsYou;
            }

            var viewer = Viewer(state);
            return viewer != null && state.Round.CurrentTurnGamePlayerId == viewer.GamePlayerId;
        }

        /// <summary>"3 pts  -  1 win", for a player's seat.</summary>
        public static string ScoreLine(BoardPlayer player) =>
            $"{player.TotalScore} {(player.TotalScore == 1 ? "pt" : "pts")}  -  {player.TotalWins} {(player.TotalWins == 1 ? "win" : "wins")}";

        private static string Prettify(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return string.Empty;
            }

            var spaced = value.Replace('_', ' ');
            return char.ToUpperInvariant(spaced[0]) + spaced.Substring(1);
        }
    }
}
