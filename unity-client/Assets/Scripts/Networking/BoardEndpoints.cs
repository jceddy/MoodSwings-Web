using System;
using System.Threading;
using System.Threading.Tasks;

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
    }
}
