using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class PreferencesEndpoints
    {
        /// <summary>
        /// Every /user/*-preference route is write-only (the current values
        /// come back on GET /me), takes a JSON object with one route-specific
        /// key, and answers {"status":"ok"}; a missing key is a 400.
        /// </summary>
        public static Task<ApiResult<ApiEnvelope>> SetPreferenceAsync(
            this ApiClient api, string route, string jsonKey, object value, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(route, new Dictionary<string, object> { [jsonKey] = value }, cancellationToken);
        }
    }
}
