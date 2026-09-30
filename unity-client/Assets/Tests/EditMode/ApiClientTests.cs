using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class ApiClientTests
    {
        private const string LoginOk =
            "{\"status\":\"ok\",\"user\":{\"id\":7,\"username\":\"alice\",\"email\":\"a@example.com\",\"phone_number\":null}}";

        private FakeHttpTransport _transport;
        private InMemorySessionStore _store;
        private ApiClient _api;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _store = new InMemorySessionStore();
            _api = new ApiClient(new ApiConfig("https://example.test/"), _transport, _store);
        }

        [Test]
        public void Requests_GoToTheAppPrefix()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            _api.GetAsync<ApiEnvelope>("/health").GetAwaiter().GetResult();

            Assert.AreEqual("https://example.test/app/health", _transport.LastRequest.Url);
            Assert.AreEqual("GET", _transport.LastRequest.Method);
        }

        [Test]
        public void Login_ParsesUserAndCapturesSessionCookie()
        {
            _transport.Enqueue(200, LoginOk,
                "session_token=abc123; expires=Wed, 30 Sep 2026 12:00:00 GMT; Max-Age=3600; path=/; secure; HttpOnly; SameSite=Lax");

            var result = _api.LoginAsync("alice", "pw").GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("alice", result.Value.User.Username);
            Assert.AreEqual(7, result.Value.User.Id);
            Assert.IsNull(result.Value.User.PhoneNumber);
            Assert.IsTrue(_api.HasSession);
            Assert.AreEqual("abc123", _store.Load());
            Assert.AreEqual("POST", _transport.LastRequest.Method);
            StringAssert.Contains("\"username\":\"alice\"", _transport.LastRequest.Body);
            Assert.AreEqual("application/json", _transport.LastRequest.Headers["Content-Type"]);
        }

        [Test]
        public void LaterRequests_ReplayTheCookie()
        {
            _transport.Enqueue(200, LoginOk, "session_token=abc123; path=/");
            _transport.Enqueue(200, LoginOk);

            _api.LoginAsync("alice", "pw").GetAwaiter().GetResult();
            _api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual("session_token=abc123", _transport.LastRequest.Headers["Cookie"]);
        }

        [Test]
        public void StoredSession_IsLoadedOnConstruction()
        {
            _store.Save("persisted");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            _transport.Enqueue(200, LoginOk);

            api.GetMeAsync().GetAwaiter().GetResult();

            Assert.IsTrue(api.HasSession);
            Assert.AreEqual("session_token=persisted", _transport.LastRequest.Headers["Cookie"]);
        }

        [Test]
        public void RefreshedCookie_ReplacesTheStoredToken()
        {
            _store.Save("old");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            _transport.Enqueue(200, LoginOk, "session_token=new; path=/");

            api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual("new", _store.Load());
        }

        [Test]
        public void Login_BadCredentials_IsUnauthorizedButNotASessionExpiry()
        {
            _store.Save("stale");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            var expired = false;
            api.SessionExpired += () => expired = true;
            _transport.Enqueue(401, "{\"status\":\"error\",\"message\":\"Invalid username or password\"}");

            var result = api.LoginAsync("alice", "wrong").GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual(ApiFailureKind.Unauthorized, result.Failure);
            Assert.AreEqual("Invalid username or password", result.Message);
            Assert.IsFalse(expired);
            Assert.IsTrue(api.HasSession);
        }

        [Test]
        public void Unauthorized_OnAnAuthedRoute_ClearsTheSessionAndRaisesSessionExpired()
        {
            _store.Save("stale");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            var expired = false;
            api.SessionExpired += () => expired = true;
            _transport.Enqueue(401, "{\"status\":\"error\",\"message\":\"Not authenticated\"}");

            var result = api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Unauthorized, result.Failure);
            Assert.IsTrue(expired);
            Assert.IsFalse(api.HasSession);
            Assert.IsNull(_store.Load());
        }

        [Test]
        public void Maintenance_IsReportedAndRaised()
        {
            string raisedWith = null;
            _api.MaintenanceEntered += message => raisedWith = message;
            _transport.Enqueue(503, "{\"status\":\"maintenance\",\"message\":\"Back soon\"}");

            var result = _api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Maintenance, result.Failure);
            Assert.AreEqual("Back soon", result.Message);
            Assert.AreEqual("Back soon", raisedWith);
        }

        [Test]
        public void ErrorStatus_IsRejectedWithTheServersMessage()
        {
            _transport.Enqueue(409, "{\"status\":\"error\",\"message\":\"Username taken\"}");

            var result = _api.PostAsync<ApiEnvelope>("/register", new { username = "x" }).GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Rejected, result.Failure);
            Assert.AreEqual(409, result.HttpStatus);
            Assert.AreEqual("Username taken", result.Message);
        }

        [Test]
        public void ServerError_IsReportedAsServer()
        {
            _transport.Enqueue(500, "<html>oops</html>");

            var result = _api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Server, result.Failure);
        }

        [Test]
        public void NetworkError_IsReportedWithoutAnHttpStatus()
        {
            _transport.EnqueueNetworkError("Cannot resolve destination host");

            var result = _api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Network, result.Failure);
            Assert.AreEqual(0, result.HttpStatus);
            Assert.AreEqual("Cannot resolve destination host", result.Message);
        }

        [Test]
        public void NonJsonSuccessBody_IsInvalidResponse()
        {
            _transport.Enqueue(200, "<html>not json</html>");

            var result = _api.GetMeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.InvalidResponse, result.Failure);
        }

        [Test]
        public void Logout_ClearsTheLocalSessionEvenIfTheServerErrors()
        {
            _store.Save("abc");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            _transport.Enqueue(500, "{\"status\":\"error\"}");

            api.LogoutAsync().GetAwaiter().GetResult();

            Assert.IsFalse(api.HasSession);
            Assert.IsNull(_store.Load());
        }

        [Test]
        public void ServerClearingTheCookie_ClearsTheLocalSession()
        {
            _store.Save("abc");
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport, _store);
            _transport.Enqueue(200, "{\"status\":\"ok\"}", "session_token=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/");

            api.PostAsync<ApiEnvelope>("/logout").GetAwaiter().GetResult();

            Assert.IsFalse(api.HasSession);
        }
    }
}
