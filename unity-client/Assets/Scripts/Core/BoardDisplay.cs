using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;

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
        /// The small line under the banner: the round, what you're doing here (watching), the
        /// ready check or the action clock when there is one, and a warning when the app can't
        /// play this kind of game yet.
        /// </summary>
        public static string HeaderLine(GameState state, bool spectating, DateTime nowUtc)
        {
            var parts = new List<string> { RoundLine(state) };
            if (spectating)
            {
                parts.Add("watching");
            }
            else if (UnsupportedReason(state) != null)
            {
                parts.Add("can't be played in the app yet");
            }

            if (state.Game.Status == "waiting" && state.Game.SynchronousMode)
            {
                parts.Add(ReadyCheckLine(state));
            }

            var clock = ClockLine(state, nowUtc);
            if (clock != null)
            {
                parts.Add(clock);
            }

            return string.Join("  -  ", parts.Where(part => !string.IsNullOrEmpty(part)));
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

            if (state.Game.Status == "waiting")
            {
                return state.Game.SynchronousMode ? "Waiting for everyone to be ready" : "Starting the game...";
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

        // --- what the viewer can do ----------------------------------------------------------

        private static readonly HashSet<string> DraftDeckTypes = new HashSet<string>
        {
            "custom_duel", "quick_draft", "chaos_draft", "winston_draft", "grid_draft",
            "rotisserie_draft", "tiered_rotisserie_draft", "sealed_deck", "sealed_pool_of_the_day", "weekly_sealed_pool",
        };

        private static bool Present(JToken token) => token != null && token.Type != JTokenType.Null;

        /// <summary>
        /// Why this game can't be played from the app yet (null when it can): drafts,
        /// duels, team play and best-of-three matches arrive in later phases. The board
        /// still shows them; the actions are off.
        /// </summary>
        public static string UnsupportedReason(GameState state)
        {
            var game = state.Game;
            var unsupported = (!string.IsNullOrEmpty(game.Format) && game.Format != GameSetup.TraditionalFormat)
                || (game.DeckType != null && DraftDeckTypes.Contains(game.DeckType))
                || Present(state.FirstPlayerDecision)
                || Present(state.TeamDecision)
                || Present(state.InitialCardPass);
            return unsupported ? "This kind of game can't be played in the app yet - open it on the web to play." : null;
        }

        /// <summary>It's the viewer's turn and nothing stands in the way of playing or passing.</summary>
        public static bool CanAct(GameState state)
        {
            var viewer = Viewer(state);
            return viewer != null
                && !viewer.Resigned
                && state.Game.Status == "in_progress"
                && state.You.IsYourTurn
                && !state.You.TurnPendingAcknowledgment
                && state.Round.PendingDecision == null
                && UnsupportedReason(state) == null;
        }

        /// <summary>The turn is the viewer's but waits for them to acknowledge it ("pause before your turn").</summary>
        public static bool NeedsAdvanceTurn(GameState state)
        {
            var viewer = Viewer(state);
            return viewer != null
                && !viewer.Resigned
                && state.Game.Status == "in_progress"
                && state.You.IsYourTurn
                && state.You.TurnPendingAcknowledgment
                && state.Round.PendingDecision == null
                && UnsupportedReason(state) == null;
        }

        /// <summary>Resigning is possible any time in a game you're still in, except while a decision freezes the round.</summary>
        public static bool CanResign(GameState state)
        {
            var viewer = Viewer(state);
            return viewer != null
                && !viewer.Resigned
                && state.Game.Status == "in_progress"
                && state.Round.PendingDecision == null;
        }

        /// <summary>A synchronous game's ready check is waiting on the viewer.</summary>
        public static bool NeedsReady(GameState state)
        {
            var viewer = Viewer(state);
            return viewer != null
                && state.Game.Status == "waiting"
                && state.Game.SynchronousMode
                && !viewer.Ready
                && UnsupportedReason(state) == null;
        }

        /// <summary>Whether the app should start the game now: a plain game right away, a synchronous one once all are ready.</summary>
        public static bool ReadyToStart(GameState state) =>
            state.Game.Status == "waiting"
            && Viewer(state) != null
            && UnsupportedReason(state) == null
            && (!state.Game.SynchronousMode || state.Players.All(p => p.Ready));

        /// <summary>"Ready: Ann, Bob  -  Not yet: Cy", for the ready check.</summary>
        public static string ReadyCheckLine(GameState state)
        {
            var ready = state.Players.Where(p => p.Ready).Select(p => p.Username).ToList();
            var waiting = state.Players.Where(p => !p.Ready).Select(p => p.Username).ToList();
            var parts = new List<string>();
            if (ready.Count > 0)
            {
                parts.Add("Ready: " + string.Join(", ", ready));
            }

            if (waiting.Count > 0)
            {
                parts.Add("Not yet: " + string.Join(", ", waiting));
            }

            return string.Join("  -  ", parts);
        }

        /// <summary>The title of the box that asks the viewer to answer a pending decision.</summary>
        public static string DecisionTitle(PendingDecision decision)
        {
            var card = string.IsNullOrEmpty(decision.PlayedCardName) ? null : decision.PlayedCardName;
            switch (decision.DecisionType)
            {
                case "duplicity_repeat_offer": return $"Repeat {card ?? "this mood"}'s effect?";
                case "enthusiasm_extra_score": return "Enthusiasm's bonus";
                case "passion_score_opponent_mood": return "Passion's bonus";
                case "after_scoring_order": return "Order your after-scoring effects";
                default: return "Respond to " + (card ?? "a mood");
            }
        }

        /// <summary>The synchronous game's action clock, "Ann's clock: 23s", or null when there's none to show.</summary>
        public static string ClockLine(GameState state, DateTime nowUtc)
        {
            var seconds = ClockSeconds(state, nowUtc, out var onTheClock);
            return seconds.HasValue
                ? $"{(onTheClock != null ? onTheClock.Username : "Someone")}'s clock: {seconds.Value}s"
                : null;
        }

        /// <summary>Whether the clock is nearly out, for drawing it in a warning color.</summary>
        public static bool ClockIsUrgent(GameState state, DateTime nowUtc) =>
            ClockSeconds(state, nowUtc, out _) is int seconds && seconds <= 10;

        private static int? ClockSeconds(GameState state, DateTime nowUtc, out BoardPlayer onTheClock)
        {
            onTheClock = null;
            var game = state.Game;
            if (game.Status != "in_progress" || !game.SynchronousMode
                || string.IsNullOrEmpty(game.ActionDeadlineAt) || !game.ActionDeadlineGamePlayerId.HasValue)
            {
                return null;
            }

            onTheClock = PlayerById(state, game.ActionDeadlineGamePlayerId);

            // A player with a banked extension just burns it on a timeout, so a countdown would only alarm.
            if (onTheClock != null && onTheClock.TimeoutExtensionsBanked > 0)
            {
                return null;
            }

            // The server's timestamps are bare "yyyy-MM-dd HH:mm:ss" in UTC.
            if (!DateTime.TryParseExact(game.ActionDeadlineAt, "yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture,
                    DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out var deadline))
            {
                return null;
            }

            return Math.Max(0, (int)Math.Ceiling((deadline - nowUtc).TotalSeconds));
        }

        /// <summary>The repeated-board-state warning, when it's about the viewer.</summary>
        public static string LoopWarningText(GameState state)
        {
            var warning = state.Game.LoopWarning;
            if (warning == null || warning.GamePlayerId != state.You.GamePlayerId)
            {
                return null;
            }

            return $"This exact board state has repeated {warning.OccurrenceCount} times this turn. "
                + "Repeating it again will end your turn automatically.";
        }

        /// <summary>Effects currently changing the board or this round's scoring, one line each.</summary>
        public static List<string> EffectLines(GameState state) =>
            state.Round.BoardEffects.Concat(state.Round.ScoringEffects)
                .Select(e => e.Description)
                .Where(d => !string.IsNullOrEmpty(d))
                .ToList();

        /// <summary>The running score while a scoring-time decision is open, and any score swaps that will follow.</summary>
        public static List<string> ScoringPreviewLines(GameState state)
        {
            var preview = state.Round.ScoringPreview;
            var lines = new List<string>();
            if (preview == null)
            {
                return lines;
            }

            lines.AddRange(preview.Scores.Select(kv => $"{PlayerById(state, kv.Key)?.Username ?? "?"}: {kv.Value}"));
            if (preview.SneakinessSwaps.Count > 0)
            {
                lines.Add("Sneakiness will swap scores after scoring: " + string.Join(", ",
                    preview.SneakinessSwaps.Select(w =>
                        $"{PlayerById(state, w.GamePlayerId)?.Username ?? "?"} <-> {PlayerById(state, w.SwapsWithGamePlayerId)?.Username ?? "?"}")));
            }

            return lines;
        }

        /// <summary>What to tell the player after an action that ended a round or the game; null if it didn't.</summary>
        public static string OutcomeNotice(GameActionResponse response)
        {
            if (response == null)
            {
                return null;
            }

            if (response.GameCompleted)
            {
                return "Game complete!";
            }

            return response.RoundScored ? "Round scored - a new round has begun." : null;
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
