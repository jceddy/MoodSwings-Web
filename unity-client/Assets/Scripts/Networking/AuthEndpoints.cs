using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class AuthEndpoints
    {
        /// <summary>POST /login. On success the session cookie is captured by the client.</summary>
        public static Task<ApiResult<UserResponse>> LoginAsync(
            this ApiClient api, string username, string password, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<UserResponse>("/login", new { username, password }, cancellationToken);
        }

        /// <summary>POST /logout. The local session is cleared regardless of the server's answer.</summary>
        public static async Task LogoutAsync(this ApiClient api, CancellationToken cancellationToken = default)
        {
            await api.PostAsync<ApiEnvelope>("/logout", null, cancellationToken);
            api.ClearSession();
        }

        /// <summary>
        /// POST /resend-verification. The server answers 200 with the same
        /// message whether or not the address exists (so it can't be used to
        /// probe for registered emails); only a malformed address is a 400.
        /// </summary>
        public static Task<ApiResult<ApiEnvelope>> ResendVerificationAsync(
            this ApiClient api, string email, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/resend-verification", new { email }, cancellationToken);
        }

        /// <summary>
        /// GET /me -- "am I still logged in" check on startup (the cookie can
        /// expire silently between launches), same use as web-static's
        /// getCurrentUser().
        /// </summary>
        public static Task<ApiResult<UserResponse>> GetMeAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<UserResponse>("/me", cancellationToken);
        }
    }
}
