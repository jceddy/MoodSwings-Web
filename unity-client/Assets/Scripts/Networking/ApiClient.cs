using System;
using System.Collections;
using System.Text;
using UnityEngine;
using UnityEngine.Networking;

namespace MoodSwings.Networking
{
    /// <summary>
    /// Thin wrapper over the same JSON HTTP API php-app/public/index.php
    /// already serves to web-static/'s own app.js -- every response is a
    /// JSON object with a "status" key ("ok"/"error"), and auth is a
    /// server-issued cookie (AuthService::COOKIE_NAME, "session_token"),
    /// not a bearer token. UnityWebRequest doesn't persist cookies across
    /// requests the way a browser does, so this class captures the
    /// Set-Cookie response header itself on login and replays it as a
    /// plain Cookie request header on every later call -- see
    /// SetRequestHeader("Cookie", ...) below.
    ///
    /// This is a starting point, not a full client: only Login/Logout/Me
    /// are wired up. Extend with the same Get/PostJson helpers for
    /// whatever routes the game actually needs (see php-app/public/index.php
    /// for the full route list, and php-app/README.md for what each one
    /// expects/returns).
    /// </summary>
    public class ApiClient : MonoBehaviour
    {
        // Same cookie name AuthService::COOKIE_NAME defines server-side --
        // duplicated here since Unity C# can't reference the PHP constant.
        private const string SessionCookieName = "session_token";

        [Tooltip("e.g. https://moodswings-dev.jceddy.com -- no trailing slash. Point this at the dev domain while testing, the production domain for a real build.")]
        [SerializeField]
        private string baseUrl = "https://moodswings-dev.jceddy.com";

        private string _sessionCookie;

        public bool IsLoggedIn => !string.IsNullOrEmpty(_sessionCookie);

        [Serializable]
        public class LoginResponse
        {
            public string status;
            public string message; // present only when status == "error"
            public UserPayload user; // present only when status == "ok"
        }

        [Serializable]
        public class UserPayload
        {
            public int id;
            public string username;
        }

        public void Login(string username, string password, Action<bool, string> onComplete)
        {
            StartCoroutine(LoginCoroutine(username, password, onComplete));
        }

        private IEnumerator LoginCoroutine(string username, string password, Action<bool, string> onComplete)
        {
            var body = JsonUtility.ToJson(new LoginRequest { username = username, password = password });

            using var request = new UnityWebRequest($"{baseUrl}/login", UnityWebRequest.kHttpVerbPOST);
            request.uploadHandler = new UploadHandlerRaw(Encoding.UTF8.GetBytes(body));
            request.downloadHandler = new DownloadHandlerBuffer();
            request.SetRequestHeader("Content-Type", "application/json");

            yield return request.SendWebRequest();

            if (request.result != UnityWebRequest.Result.Success)
            {
                onComplete?.Invoke(false, request.error);
                yield break;
            }

            CaptureSessionCookie(request);

            var parsed = JsonUtility.FromJson<LoginResponse>(request.downloadHandler.text);
            if (parsed.status != "ok")
            {
                onComplete?.Invoke(false, parsed.message ?? "Login failed");
                yield break;
            }

            onComplete?.Invoke(true, null);
        }

        public void Logout(Action onComplete)
        {
            StartCoroutine(LogoutCoroutine(onComplete));
        }

        private IEnumerator LogoutCoroutine(Action onComplete)
        {
            using var request = new UnityWebRequest($"{baseUrl}/logout", UnityWebRequest.kHttpVerbPOST);
            request.downloadHandler = new DownloadHandlerBuffer();
            AttachSessionCookie(request);

            yield return request.SendWebRequest();

            _sessionCookie = null;
            onComplete?.Invoke();
        }

        /// <summary>
        /// GET /me -- confirms the current session is still valid and
        /// returns the logged-in user. Used the same way app.js uses it:
        /// as a "am I still logged in" check on startup, since the cookie
        /// itself can expire silently between sessions.
        /// </summary>
        public void GetMe(Action<bool, UserPayload> onComplete)
        {
            StartCoroutine(GetMeCoroutine(onComplete));
        }

        private IEnumerator GetMeCoroutine(Action<bool, UserPayload> onComplete)
        {
            using var request = UnityWebRequest.Get($"{baseUrl}/me");
            AttachSessionCookie(request);

            yield return request.SendWebRequest();

            if (request.result != UnityWebRequest.Result.Success)
            {
                onComplete?.Invoke(false, null);
                yield break;
            }

            var parsed = JsonUtility.FromJson<LoginResponse>(request.downloadHandler.text);
            onComplete?.Invoke(parsed.status == "ok", parsed.user);
        }

        private void AttachSessionCookie(UnityWebRequest request)
        {
            if (!string.IsNullOrEmpty(_sessionCookie))
            {
                request.SetRequestHeader("Cookie", $"{SessionCookieName}={_sessionCookie}");
            }
        }

        private void CaptureSessionCookie(UnityWebRequest request)
        {
            // Set-Cookie looks like "session_token=<value>; Path=/; HttpOnly;
            // Secure; SameSite=Lax; Expires=...". Only the name=value pair
            // before the first ';' is needed to replay it as a plain Cookie
            // header on later requests.
            var setCookie = request.GetResponseHeader("Set-Cookie");
            if (string.IsNullOrEmpty(setCookie))
            {
                return;
            }

            var prefix = $"{SessionCookieName}=";
            var start = setCookie.IndexOf(prefix, StringComparison.Ordinal);
            if (start < 0)
            {
                return;
            }

            start += prefix.Length;
            var end = setCookie.IndexOf(';', start);
            _sessionCookie = end < 0 ? setCookie[start..] : setCookie[start..end];
        }

        [Serializable]
        private class LoginRequest
        {
            public string username;
            public string password;
        }
    }
}
