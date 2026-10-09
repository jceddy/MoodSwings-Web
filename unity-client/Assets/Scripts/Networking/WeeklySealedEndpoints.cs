using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class WeeklySealedEndpoints
    {
        public static Task<ApiResult<WeeklyQueueStatus>> GetWeeklyQueueAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<WeeklyQueueStatus>("/weekly-sealed-pool/queue", cancellationToken);
        }

        public static Task<ApiResult<WeeklyQueueJoinResponse>> JoinWeeklyQueueAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<WeeklyQueueJoinResponse>("/weekly-sealed-pool/queue", new Dictionary<string, object>(), cancellationToken);
        }

        public static Task<ApiResult<ApiEnvelope>> LeaveWeeklyQueueAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/weekly-sealed-pool/queue/leave", new Dictionary<string, object>(), cancellationToken);
        }

        /// <summary>GET /weekly-sealed-pool/standings, for this week or (prior) the last one.</summary>
        public static Task<ApiResult<WeeklyStandingsResponse>> GetWeeklyStandingsAsync(
            this ApiClient api, bool prior, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<WeeklyStandingsResponse>(prior ? "/weekly-sealed-pool/standings?week=prior" : "/weekly-sealed-pool/standings", cancellationToken);
        }
    }
}
