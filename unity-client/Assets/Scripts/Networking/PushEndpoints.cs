using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class PushEndpoints
    {
        /// <summary>POST /notifications/device-token -- registers this install's push token (platform "android" or "ios") for the signed-in user.</summary>
        public static Task<ApiResult<ApiEnvelope>> RegisterDeviceTokenAsync(
            this ApiClient api, string token, string platform, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/notifications/device-token",
                new Dictionary<string, object> { ["token"] = token, ["platform"] = platform },
                cancellationToken);
        }

        /// <summary>POST /notifications/device-token/remove -- stops pushing to this token (on sign-out, or when push is turned off here).</summary>
        public static Task<ApiResult<ApiEnvelope>> RemoveDeviceTokenAsync(
            this ApiClient api, string token, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/notifications/device-token/remove", new Dictionary<string, object> { ["token"] = token }, cancellationToken);
        }
    }
}
