using System;
using System.Threading;
using System.Threading.Tasks;
using Newtonsoft.Json;

namespace MoodSwings.Networking
{
    /// <summary>
    /// Thin wrapper over the JSON HTTP API php-app/public/index.php serves
    /// (and web-static/js/app.js's apiRequest() consumes). Every response is
    /// a JSON object with a "status" key; auth is a server-issued cookie
    /// (AuthService::COOKIE_NAME, "session_token"), not a bearer token.
    /// Unity has no cookie jar, so this class captures the Set-Cookie header
    /// itself and replays it as a plain Cookie header on later requests.
    ///
    /// Plain C# rather than a MonoBehaviour so it can be unit-tested against
    /// a fake <see cref="IHttpTransport"/>. Endpoint-specific wrappers live
    /// in *Endpoints.cs extension classes (see AuthEndpoints) -- add new
    /// routes there, not here.
    /// </summary>
    public sealed class ApiClient
    {
        // Same name AuthService::COOKIE_NAME defines server-side.
        private const string SessionCookieName = "session_token";

        private static readonly JsonSerializerSettings JsonSettings = new JsonSerializerSettings
        {
            NullValueHandling = NullValueHandling.Ignore,
        };

        private readonly IHttpTransport _transport;
        private readonly ISessionStore _sessionStore;

        private string _sessionToken;

        /// <summary>
        /// Raised when the server reports maintenance mode. Web redirects
        /// to maintenance.html; here, the screen router is expected to show
        /// a maintenance screen with this message.
        /// </summary>
        public event Action<string> MaintenanceEntered;

        /// <summary>
        /// Raised when an authenticated call comes back 401 -- the cookie
        /// expired or was revoked. The stored session has already been
        /// cleared by the time this fires.
        /// </summary>
        public event Action SessionExpired;

        public ApiConfig Config { get; }

        public bool HasSession => !string.IsNullOrEmpty(_sessionToken);

        public ApiClient(ApiConfig config, IHttpTransport transport, ISessionStore sessionStore = null)
        {
            Config = config;
            _transport = transport;
            _sessionStore = sessionStore ?? new InMemorySessionStore();
            _sessionToken = _sessionStore.Load();
        }

        public Task<ApiResult<T>> GetAsync<T>(string path, CancellationToken cancellationToken = default)
        {
            return SendAsync<T>("GET", path, null, cancellationToken);
        }

        public Task<ApiResult<T>> PostAsync<T>(string path, object body = null, CancellationToken cancellationToken = default)
        {
            // Every POST route reads a JSON object body, even when it has
            // no fields.
            return SendAsync<T>("POST", path, body ?? new object(), cancellationToken);
        }

        public void ClearSession()
        {
            _sessionToken = null;
            _sessionStore.Clear();
        }

        /// <summary>
        /// GET a plain-text file from the site root rather than the API
        /// (e.g. "/VERSION"). No session, no JSON envelope.
        /// </summary>
        public async Task<ApiResult<string>> GetSiteTextAsync(string sitePath, CancellationToken cancellationToken = default)
        {
            var request = new HttpRequest { Method = "GET", Url = Config.SiteRoot + sitePath };
            request.Headers["User-Agent"] = Config.UserAgent;

            var response = await _transport.SendAsync(request, cancellationToken);
            if (response.NetworkError != null)
            {
                return ApiResult<string>.Fail(ApiFailureKind.Network, 0, response.NetworkError);
            }

            var status = (int)response.StatusCode;
            if (status >= 500)
            {
                return ApiResult<string>.Fail(ApiFailureKind.Server, status, null);
            }

            if (status >= 400)
            {
                return ApiResult<string>.Fail(ApiFailureKind.Rejected, status, null);
            }

            return ApiResult<string>.Success(status, (response.Body ?? string.Empty).Trim());
        }

        private async Task<ApiResult<T>> SendAsync<T>(string method, string path, object body, CancellationToken cancellationToken)
        {
            var request = new HttpRequest { Method = method, Url = Config.ApiBase + path };
            request.Headers["Accept"] = "application/json";
            request.Headers["User-Agent"] = Config.UserAgent;

            if (body != null)
            {
                request.Body = JsonConvert.SerializeObject(body, JsonSettings);
                request.Headers["Content-Type"] = "application/json";
            }

            var timezone = TimeZoneHeader.Current();
            if (timezone != null)
            {
                request.Headers["X-Timezone"] = timezone;
            }

            var hadSession = HasSession;
            if (hadSession)
            {
                request.Headers["Cookie"] = $"{SessionCookieName}={_sessionToken}";
            }

            var response = await _transport.SendAsync(request, cancellationToken);

            if (response.NetworkError != null)
            {
                return ApiResult<T>.Fail(ApiFailureKind.Network, 0, response.NetworkError);
            }

            CaptureSessionCookie(response);

            var status = (int)response.StatusCode;
            var envelope = ParseEnvelope(response.Body);

            if (status == 503 && envelope?.Status == "maintenance")
            {
                MaintenanceEntered?.Invoke(envelope.Message);
                return ApiResult<T>.Fail(ApiFailureKind.Maintenance, status, envelope.Message);
            }

            if (status == 401)
            {
                // /login answers 401 for bad credentials -- that's not an
                // expired session, so only other routes count as one.
                if (hadSession && path != "/login")
                {
                    ClearSession();
                    SessionExpired?.Invoke();
                }

                return ApiResult<T>.Fail(ApiFailureKind.Unauthorized, status, envelope?.Message);
            }

            if (status >= 500)
            {
                return ApiResult<T>.Fail(ApiFailureKind.Server, status, envelope?.Message);
            }

            if (status >= 400 || (envelope != null && envelope.Status == "error"))
            {
                return ApiResult<T>.Fail(ApiFailureKind.Rejected, status, envelope?.Message);
            }

            try
            {
                var value = JsonConvert.DeserializeObject<T>(response.Body ?? string.Empty, JsonSettings);
                if (value == null)
                {
                    return ApiResult<T>.Fail(ApiFailureKind.InvalidResponse, status, "Empty response body");
                }

                return ApiResult<T>.Success(status, value);
            }
            catch (JsonException e)
            {
                return ApiResult<T>.Fail(ApiFailureKind.InvalidResponse, status, e.Message);
            }
        }

        private static ApiEnvelope ParseEnvelope(string body)
        {
            if (string.IsNullOrEmpty(body))
            {
                return null;
            }

            try
            {
                return JsonConvert.DeserializeObject<ApiEnvelope>(body, JsonSettings);
            }
            catch (JsonException)
            {
                return null;
            }
        }

        private void CaptureSessionCookie(HttpResponse response)
        {
            // Set-Cookie looks like "session_token=<value>; expires=...;
            // path=/; secure; HttpOnly; SameSite=Lax". Only the name=value
            // pair matters for replaying it. /me and every authed route
            // re-send it to refresh the expiry, so this runs on every
            // response, not just /login.
            if (!response.Headers.TryGetValue("Set-Cookie", out var setCookie) || string.IsNullOrEmpty(setCookie))
            {
                return;
            }

            var prefix = SessionCookieName + "=";
            var start = setCookie.IndexOf(prefix, StringComparison.Ordinal);
            if (start < 0)
            {
                return;
            }

            start += prefix.Length;
            var end = setCookie.IndexOf(';', start);
            var value = end < 0 ? setCookie.Substring(start) : setCookie.Substring(start, end - start);

            // clearSessionCookie() on the server sends the cookie with an
            // empty value (PHP serializes it as "deleted").
            if (string.IsNullOrEmpty(value) || value == "deleted")
            {
                ClearSession();
                return;
            }

            _sessionToken = value;
            _sessionStore.Save(value);
        }
    }

    internal static class TimeZoneHeader
    {
        /// <summary>
        /// php-app stores the client's IANA timezone (X-Timezone) and
        /// rejects anything else. Windows reports its own ids
        /// ("Pacific Standard Time") rather than IANA ones, so send only
        /// what already looks IANA ("America/Los_Angeles") and omit the
        /// header otherwise. TODO: map Windows ids to IANA.
        /// </summary>
        public static string Current()
        {
            var id = TimeZoneInfo.Local.Id;
            return id != null && id.Contains("/") ? id : null;
        }
    }
}
