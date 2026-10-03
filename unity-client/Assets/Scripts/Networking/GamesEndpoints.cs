using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class GamesEndpoints
    {
        /// <summary>GET /games -- the games you're seated in that aren't over.</summary>
        public static Task<ApiResult<GamesResponse>> ListActiveGamesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<GamesResponse>("/games", cancellationToken);
        }

        /// <summary>GET /games/past -- finished games.</summary>
        public static Task<ApiResult<GamesResponse>> ListPastGamesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<GamesResponse>("/games/past", cancellationToken);
        }

        /// <summary>GET /config/synchronous-mode-enabled -- whether the server currently offers synchronous games at all.</summary>
        public static Task<ApiResult<FeatureFlagResponse>> GetSynchronousModeEnabledAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<FeatureFlagResponse>("/config/synchronous-mode-enabled", cancellationToken);
        }

        public static Task<ApiResult<BotsResponse>> ListBotsAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<BotsResponse>("/games/bots", cancellationToken);
        }

        /// <summary>
        /// POST /games. <paramref name="body"/> is the create-game object
        /// (opponent_user_ids, format, deck_type, ...); 201 with the new
        /// game_id, 400 with a message when the setup is invalid. A game
        /// with no human wait (bots, or everyone seated at once) starts
        /// immediately.
        /// </summary>
        public static Task<ApiResult<CreateGameResponse>> CreateGameAsync(
            this ApiClient api, IDictionary<string, object> body, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<CreateGameResponse>("/games", body, cancellationToken);
        }
    }

    public static class PuzzlesEndpoints
    {
        /// <summary>GET /puzzles -- every puzzle, easiest first, with how you've done at each.</summary>
        public static Task<ApiResult<PuzzlesResponse>> ListPuzzlesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<PuzzlesResponse>("/puzzles", cancellationToken);
        }

        /// <summary>POST /puzzles/attempt -- starts a fresh attempt (also "try again"); 201 with the new game_id.</summary>
        public static Task<ApiResult<CreateGameResponse>> StartPuzzleAttemptAsync(
            this ApiClient api, int puzzleId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<CreateGameResponse>("/puzzles/attempt", new { puzzle_id = puzzleId }, cancellationToken);
        }
    }

    public enum OpenGameScope
    {
        /// <summary>Other people's listings you could join.</summary>
        Available,

        /// <summary>Listings you posted (?mine=1).</summary>
        Mine,

        /// <summary>Listings you've joined and are waiting on (?joined=1).</summary>
        Joined,
    }

    public static class OpenGamesEndpoints
    {
        public static Task<ApiResult<OpenGamesResponse>> ListOpenGamesAsync(
            this ApiClient api, OpenGameScope scope, CancellationToken cancellationToken = default)
        {
            var query = scope == OpenGameScope.Mine ? "?mine=1" : scope == OpenGameScope.Joined ? "?joined=1" : string.Empty;
            return api.GetAsync<OpenGamesResponse>("/open-games" + query, cancellationToken);
        }

        /// <summary>POST /open-games: <paramref name="body"/> is the create-game settings plus target_player_count.</summary>
        public static Task<ApiResult<PostOpenGameResponse>> PostOpenGameAsync(
            this ApiClient api, IDictionary<string, object> body, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<PostOpenGameResponse>("/open-games", body, cancellationToken);
        }

        public static Task<ApiResult<JoinOpenGameResponse>> JoinOpenGameAsync(
            this ApiClient api, int listingId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<JoinOpenGameResponse>("/open-games/join", new { id = listingId }, cancellationToken);
        }

        /// <summary>Withdraws you from a listing you joined.</summary>
        public static Task<ApiResult<ApiEnvelope>> LeaveOpenGameAsync(
            this ApiClient api, int listingId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/open-games/leave", new { id = listingId }, cancellationToken);
        }

        /// <summary>Takes down a listing you posted (403 if it isn't yours).</summary>
        public static Task<ApiResult<ApiEnvelope>> CancelOpenGameAsync(
            this ApiClient api, int listingId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/open-games/cancel", new { id = listingId }, cancellationToken);
        }
    }
}
