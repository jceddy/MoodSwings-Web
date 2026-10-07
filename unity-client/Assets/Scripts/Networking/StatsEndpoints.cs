using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class StatsEndpoints
    {
        /// <summary>GET /user/stats -- your own lifetime game and match totals.</summary>
        public static Task<ApiResult<UserStatsResponse>> GetUserStatsAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<UserStatsResponse>("/user/stats", cancellationToken);
        }

        /// <summary>GET /user/achievements -- the whole catalog by category, with your progress at each.</summary>
        public static Task<ApiResult<AchievementsResponse>> GetAchievementsAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<AchievementsResponse>("/user/achievements", cancellationToken);
        }
    }
}
