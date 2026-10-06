using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Networking
{
    public static class BoardEndpoints
    {
        /// <summary>
        /// GET /games/state -- the full view of a game you're seated in (403
        /// otherwise). Besides reading, the server uses these calls to advance
        /// bot turns and enforce timers, so a board that is open has to keep
        /// polling this; it isn't just a refresh.
        /// </summary>
        public static Task<ApiResult<GameState>> GetGameStateAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<GameState>($"/games/state?game_id={gameId}", cancellationToken);
        }

        /// <summary>GET /games/spectatable -- in-progress games your friends are seated in, which you can watch without a code.</summary>
        public static Task<ApiResult<GamesResponse>> ListWatchableGamesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<GamesResponse>("/games/spectatable", cancellationToken);
        }

        /// <summary>POST /games/spectate/resolve -- turns a spectate code a player shared into a game id (404 if there's no such game).</summary>
        public static Task<ApiResult<SpectateResolveResponse>> ResolveSpectateCodeAsync(
            this ApiClient api, string code, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<SpectateResolveResponse>("/games/spectate/resolve", new { code }, cancellationToken);
        }

        /// <summary>
        /// GET /games/spectate/state -- the same view without a hand of your
        /// own. A friend's game can be watched by id; anyone else's needs the
        /// spectate code its players can share.
        /// </summary>
        public static Task<ApiResult<GameState>> GetSpectatorStateAsync(
            this ApiClient api, int gameId, string code = null, CancellationToken cancellationToken = default)
        {
            var path = $"/games/spectate/state?game_id={gameId}";
            if (!string.IsNullOrEmpty(code))
            {
                path += "&code=" + Uri.EscapeDataString(code);
            }

            return api.GetAsync<GameState>(path, cancellationToken);
        }

        // --- acting in a game --------------------------------------------------------------
        // Each of these can fail with a 409 whose message says why ("It's not your turn.");
        // on success the server has also already run any bot turns it handed the turn to.

        /// <summary>POST /games/start -- deals the game once everyone is ready. Safe to repeat; a loser of the race gets a 409.</summary>
        public static Task<ApiResult<GameActionResponse>> StartGameAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>("/games/start", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/ready -- synchronous games' pre-game ready check. Idempotent.</summary>
        public static Task<ApiResult<GameActionResponse>> MarkReadyAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>("/games/ready", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/play -- plays a card from your hand with the answers to its choices (400 if a choice is invalid).</summary>
        public static Task<ApiResult<GameActionResponse>> PlayCardAsync(
            this ApiClient api, int gameId, int cardId, JObject choices, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/play", new { game_id = gameId, card_id = cardId, choices = choices ?? new JObject() }, cancellationToken);
        }

        /// <summary>POST /games/pass -- ends your turn without playing.</summary>
        public static Task<ApiResult<GameActionResponse>> PassTurnAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>("/games/pass", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/advance-turn -- acknowledges the start of your turn when "pause before own turn" is on.</summary>
        public static Task<ApiResult<GameActionResponse>> AdvanceTurnAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>("/games/advance-turn", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/resign -- leaves the game for good.</summary>
        public static Task<ApiResult<GameActionResponse>> ResignGameAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>("/games/resign", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/respond -- answers the pending decision a card effect is waiting on you for.</summary>
        public static Task<ApiResult<GameActionResponse>> RespondToDecisionAsync(
            this ApiClient api, int gameId, JObject choices, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/respond", new { game_id = gameId, choices = choices ?? new JObject() }, cancellationToken);
        }

        /// <summary>
        /// POST /games/draft/first-player-choice (the path predates matches outside drafts) -- the previous
        /// game's loser, in game 2 or 3 of a match, says whether they go first (true) or let the winner (false).
        /// </summary>
        public static Task<ApiResult<GameActionResponse>> ChoosePlayFirstAsync(
            this ApiClient api, int gameId, bool playFirst, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/draft/first-player-choice", new { game_id = gameId, play_first = playFirst }, cancellationToken);
        }

        /// <summary>
        /// POST /games/chat -- says something to the table, or (channel "team", Open Team Play only) to your
        /// partner. Chat comes back through the board's polling.
        /// </summary>
        public static Task<ApiResult<ApiEnvelope>> SendChatAsync(
            this ApiClient api, int gameId, string text, string channel = "table", CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/games/chat", new { game_id = gameId, channel, message_text = text }, cancellationToken);
        }

        /// <summary>
        /// POST /games/puzzle-hint-viewed -- say you're about to read the puzzle's hint, so the solve no longer
        /// counts for the "Puzzle Solver" achievement. Sent before the hint is shown.
        /// </summary>
        public static Task<ApiResult<ApiEnvelope>> MarkPuzzleHintViewedAsync(
            this ApiClient api, int gameId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/games/puzzle-hint-viewed", new { game_id = gameId }, cancellationToken);
        }

        /// <summary>POST /games/team-decision (propose) -- names which of the team's two members should act.</summary>
        public static Task<ApiResult<GameActionResponse>> ProposeTeamDecisionAsync(
            this ApiClient api, int gameId, int proposedGamePlayerId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/team-decision",
                new { game_id = gameId, action = "propose", proposed_game_player_id = proposedGamePlayerId },
                cancellationToken);
        }

        /// <summary>POST /games/team-decision (confirm) -- the other partner agrees (true) or sends it back (false).</summary>
        public static Task<ApiResult<GameActionResponse>> ConfirmTeamDecisionAsync(
            this ApiClient api, int gameId, bool approve, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/team-decision", new { game_id = gameId, action = "confirm", approve }, cancellationToken);
        }

        /// <summary>POST /games/initial-pass -- Closed Team Play: the two cards you pass to your partner, face down.</summary>
        public static Task<ApiResult<GameActionResponse>> SubmitInitialPassAsync(
            this ApiClient api, int gameId, IEnumerable<int> cardIds, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<GameActionResponse>(
                "/games/initial-pass", new { game_id = gameId, card_ids = cardIds.ToArray() }, cancellationToken);
        }
    }
}
