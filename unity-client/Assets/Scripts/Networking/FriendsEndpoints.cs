using System.Threading;
using System.Threading.Tasks;

namespace MoodSwings.Networking
{
    public static class FriendsEndpoints
    {
        public static Task<ApiResult<FriendsResponse>> ListFriendsAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<FriendsResponse>("/friends", cancellationToken);
        }

        public static Task<ApiResult<FriendInvitesResponse>> ListFriendInvitesAsync(
            this ApiClient api, CancellationToken cancellationToken = default)
        {
            return api.GetAsync<FriendInvitesResponse>("/friends/invites", cancellationToken);
        }

        /// <summary>
        /// POST /friends/invite. The server accepts a username or an email
        /// address; 404 = no such user, 409 = yourself or already friends/pending.
        /// </summary>
        public static Task<ApiResult<SendInviteResponse>> SendFriendInviteAsync(
            this ApiClient api, string usernameOrEmail, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<SendInviteResponse>(
                "/friends/invite", new { username_or_email = usernameOrEmail }, cancellationToken);
        }

        /// <param name="action">"accept", "decline" or "block".</param>
        public static Task<ApiResult<ApiEnvelope>> RespondToFriendInviteAsync(
            this ApiClient api, int userId, string action, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>(
                "/friends/respond", new { user_id = userId, action }, cancellationToken);
        }

        public static Task<ApiResult<ApiEnvelope>> RemoveFriendAsync(
            this ApiClient api, int userId, CancellationToken cancellationToken = default)
        {
            return api.PostAsync<ApiEnvelope>("/friends/remove", new { user_id = userId }, cancellationToken);
        }
    }
}
