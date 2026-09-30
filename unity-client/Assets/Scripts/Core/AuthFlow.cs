using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    public enum LoginOutcome
    {
        Success,
        InvalidCredentials,

        /// <summary>403 -- correct credentials, but the account's email isn't verified yet.</summary>
        EmailNotVerified,

        /// <summary>Any other refusal, including client-side validation; Message says why.</summary>
        Rejected,

        Maintenance,
        NetworkError,
        ServerError,
    }

    public sealed class LoginResult
    {
        public LoginOutcome Outcome { get; set; }

        /// <summary>The server's message (or ours, for client-side validation); may be null.</summary>
        public string Message { get; set; }

        public User User { get; set; }
    }

    public enum ResumeOutcome
    {
        /// <summary>Nothing remembered -- show the login screen.</summary>
        NoSession,

        /// <summary>A remembered session is still valid; CurrentUser is set.</summary>
        Resumed,

        /// <summary>A remembered session was rejected (expired/revoked) and has been cleared.</summary>
        Expired,

        /// <summary>Couldn't find out -- offline, server error, or maintenance. The session is kept.</summary>
        Unavailable,
    }

    public sealed class ResumeResult
    {
        public ResumeOutcome Outcome { get; set; }

        public string Message { get; set; }

        public User User { get; set; }
    }

    /// <summary>
    /// Everything the splash/login screens need to decide, kept free of UI
    /// so it can be tested against a fake transport: log in, resume a
    /// remembered session, resend a verification email, log out, plus the
    /// "remember me"/last-username preferences.
    /// </summary>
    public sealed class AuthFlow
    {
        private const string LastUsernameKey = "login.last_username";
        private const string RememberMeKey = "login.remember_me";

        private readonly ApiClient _api;
        private readonly SecureSessionStore _sessionStore;
        private readonly IKeyValueStore _preferences;

        public AuthFlow(ApiClient api, SecureSessionStore sessionStore, IKeyValueStore preferences)
        {
            _api = api;
            _sessionStore = sessionStore;
            _preferences = preferences;
        }

        public User CurrentUser { get; private set; }

        /// <summary>The username to pre-fill; null if the user opted out of being remembered.</summary>
        public string LastUsername => _preferences.GetString(LastUsernameKey);

        /// <summary>Defaults to true until the user unticks it once.</summary>
        public bool RememberMe => _preferences.GetString(RememberMeKey) != "0";

        public async Task<LoginResult> LoginAsync(
            string username, string password, bool remember, CancellationToken cancellationToken = default)
        {
            username = (username ?? string.Empty).Trim();
            if (username.Length == 0 || string.IsNullOrEmpty(password))
            {
                return new LoginResult
                {
                    Outcome = LoginOutcome.Rejected,
                    Message = "Enter your username and password.",
                };
            }

            // Decided before the request: ApiClient saves the cookie as the
            // response arrives, and Persist controls whether that hits disk.
            _sessionStore.Persist = remember;

            var result = await _api.LoginAsync(username, password, cancellationToken);
            if (result.Ok)
            {
                CurrentUser = result.Value.User;
                _preferences.SetString(RememberMeKey, remember ? "1" : "0");
                if (remember)
                {
                    _preferences.SetString(LastUsernameKey, username);
                }
                else
                {
                    _preferences.Delete(LastUsernameKey);
                }

                return new LoginResult { Outcome = LoginOutcome.Success, User = CurrentUser };
            }

            return new LoginResult { Outcome = Classify(result), Message = result.Message };
        }

        /// <summary>
        /// On launch: if a session was remembered, confirm it's still valid
        /// (the cookie can expire silently between launches).
        /// </summary>
        public async Task<ResumeResult> ResumeAsync(CancellationToken cancellationToken = default)
        {
            if (!_api.HasSession)
            {
                return new ResumeResult { Outcome = ResumeOutcome.NoSession };
            }

            var result = await _api.GetMeAsync(cancellationToken);
            if (result.Ok)
            {
                CurrentUser = result.Value.User;
                return new ResumeResult { Outcome = ResumeOutcome.Resumed, User = CurrentUser };
            }

            if (result.Failure == ApiFailureKind.Unauthorized)
            {
                return new ResumeResult { Outcome = ResumeOutcome.Expired, Message = result.Message };
            }

            return new ResumeResult { Outcome = ResumeOutcome.Unavailable, Message = result.Message };
        }

        public Task<ApiResult<ApiEnvelope>> ResendVerificationAsync(string email, CancellationToken cancellationToken = default)
        {
            return _api.ResendVerificationAsync((email ?? string.Empty).Trim(), cancellationToken);
        }

        public async Task LogoutAsync(CancellationToken cancellationToken = default)
        {
            await _api.LogoutAsync(cancellationToken);
            CurrentUser = null;
        }

        private static LoginOutcome Classify(ApiResult<UserResponse> result)
        {
            switch (result.Failure)
            {
                case ApiFailureKind.Unauthorized:
                    return LoginOutcome.InvalidCredentials;
                case ApiFailureKind.Rejected:
                    return result.HttpStatus == 403 ? LoginOutcome.EmailNotVerified : LoginOutcome.Rejected;
                case ApiFailureKind.Maintenance:
                    return LoginOutcome.Maintenance;
                case ApiFailureKind.Network:
                    return LoginOutcome.NetworkError;
                default:
                    return LoginOutcome.ServerError;
            }
        }
    }
}
