using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class LeagueEndpoints
    {
        public static Task<ApiResult<LeagueStatus>> GetLeagueStatusAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<LeagueStatus>("/league", cancellationToken);
        }

        /// <summary>GET /league/leaderboard, for the running event or (prior) the one that last ended.</summary>
        public static Task<ApiResult<LeagueLeaderboardResponse>> GetLeagueLeaderboardAsync(
            this ApiClient api, bool prior, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<LeagueLeaderboardResponse>(prior ? "/league/leaderboard?event=prior" : "/league/leaderboard", cancellationToken);
        }

        public static Task<ApiResult<LeagueCollectionResponse>> GetLeagueCollectionAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<LeagueCollectionResponse>("/league/collection", cancellationToken);
        }

        /// <summary>Joins the event (the first time grants a starter deck) and enters the queue; no deck is chosen here.</summary>
        public static Task<ApiResult<LeagueJoinResponse>> JoinLeagueAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<LeagueJoinResponse>("/league/join", new Dictionary<string, object>(), cancellationToken);
        }

        public static Task<ApiResult<ApiEnvelope>> LeaveLeagueQueueAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/league/queue/leave", new Dictionary<string, object>(), cancellationToken);
        }
    }
}
