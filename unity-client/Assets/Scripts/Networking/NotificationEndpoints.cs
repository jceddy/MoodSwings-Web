using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class NotificationEndpoints
    {
        public static Task<ApiResult<NotificationPreferencesResponse>> GetNotificationPreferencesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<NotificationPreferencesResponse>("/notifications/preferences", cancellationToken);
        }

        /// <summary>POST /notifications/preferences -- always the whole set; answers with what was saved.</summary>
        public static Task<ApiResult<NotificationPreferencesResponse>> SaveNotificationPreferencesAsync(
            this ApiClient api, NotificationPreferences preferences, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<NotificationPreferencesResponse>("/notifications/preferences", preferences.ToBody(), cancellationToken);
        }

        public static Task<ApiResult<DiscordStatusResponse>> GetDiscordStatusAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<DiscordStatusResponse>("/discord/status", cancellationToken);
        }
    }
}
