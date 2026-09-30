using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class AuthFlowTests
    {
        private const string UserJson = "{\"id\":7,\"username\":\"alice\",\"email\":\"a@example.com\",\"phone_number\":null}";
        private const string LoginOk = "{\"status\":\"ok\",\"user\":" + UserJson + "}";

        /// <summary>One "app launch": a fresh client/store/flow over shared disk and protector.</summary>
        private sealed class Launch
        {
            public FakeHttpTransport Transport { get; } = new FakeHttpTransport();
            public SecureSessionStore Session { get; }
            public ApiClient Api { get; }
            public AuthFlow Auth { get; }

            public Launch(InMemoryKeyValueStore disk, FakeSecretProtector protector)
            {
                Session = new SecureSessionStore(disk, protector);
                Api = new ApiClient(new ApiConfig("https://example.test"), Transport, Session);
                Auth = new AuthFlow(Api, Session, disk);
            }
        }

        private InMemoryKeyValueStore _disk;
        private FakeSecretProtector _protector;

        [SetUp]
        public void SetUp()
        {
            _disk = new InMemoryKeyValueStore();
            _protector = new FakeSecretProtector();
        }

        private Launch NewLaunch() => new Launch(_disk, _protector);

        [Test]
        public void Login_Success_SetsTheCurrentUser()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");

            var result = app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.Success, result.Outcome);
            Assert.AreEqual("alice", app.Auth.CurrentUser.Username);
        }

        [Test]
        public void Login_TrimsTheUsername()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");

            app.Auth.LoginAsync("  alice ", "pw", true).GetAwaiter().GetResult();

            StringAssert.Contains("\"username\":\"alice\"", app.Transport.LastRequest.Body);
        }

        [TestCase("", "pw")]
        [TestCase("   ", "pw")]
        [TestCase("alice", "")]
        [TestCase("alice", null)]
        public void Login_WithMissingFields_IsRejectedWithoutARequest(string username, string password)
        {
            var app = NewLaunch();

            var result = app.Auth.LoginAsync(username, password, true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.Rejected, result.Outcome);
            Assert.AreEqual(0, app.Transport.Requests.Count);
        }

        [Test]
        public void Login_BadCredentials()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(401, "{\"status\":\"error\",\"message\":\"Invalid username or password.\"}");

            var result = app.Auth.LoginAsync("alice", "wrong", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.InvalidCredentials, result.Outcome);
            Assert.AreEqual("Invalid username or password.", result.Message);
            Assert.IsNull(app.Auth.CurrentUser);
        }

        [Test]
        public void Login_UnverifiedEmail_IsDistinguishedFromBadCredentials()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(403, "{\"status\":\"error\",\"message\":\"Please verify your email address before logging in.\"}");

            var result = app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.EmailNotVerified, result.Outcome);
            StringAssert.Contains("verify your email", result.Message);
        }

        [Test]
        public void Login_DuringMaintenance()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(503, "{\"status\":\"maintenance\",\"message\":\"Back soon\"}");

            var result = app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.Maintenance, result.Outcome);
        }

        [Test]
        public void Login_Offline()
        {
            var app = NewLaunch();
            app.Transport.EnqueueNetworkError("Cannot resolve destination host");

            var result = app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.NetworkError, result.Outcome);
        }

        [Test]
        public void Login_ServerError()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(500, "oops");

            var result = app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual(LoginOutcome.ServerError, result.Outcome);
        }

        [Test]
        public void RememberMe_SurvivesARestart_AndResumeWorks()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            var second = NewLaunch();
            second.Transport.Enqueue(200, LoginOk);
            var resume = second.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.Resumed, resume.Outcome);
            Assert.AreEqual("alice", resume.User.Username);
            Assert.AreEqual("session_token=tok1", second.Transport.LastRequest.Headers["Cookie"]);
        }

        [Test]
        public void NotRemembered_IsGoneAfterARestart()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", false).GetAwaiter().GetResult();

            var second = NewLaunch();
            var resume = second.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.NoSession, resume.Outcome);
            Assert.AreEqual(0, second.Transport.Requests.Count);
        }

        [Test]
        public void Preferences_AreRemembered_AndClearedWhenOptingOut()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            Assert.AreEqual("alice", NewLaunch().Auth.LastUsername);
            Assert.IsTrue(NewLaunch().Auth.RememberMe);

            var second = NewLaunch();
            second.Transport.Enqueue(200, LoginOk, "session_token=tok2; path=/");
            second.Auth.LoginAsync("alice", "pw", false).GetAwaiter().GetResult();

            Assert.IsNull(NewLaunch().Auth.LastUsername);
            Assert.IsFalse(NewLaunch().Auth.RememberMe);
        }

        [Test]
        public void Preferences_DefaultToRememberMeWithNoUsername()
        {
            var app = NewLaunch();

            Assert.IsTrue(app.Auth.RememberMe);
            Assert.IsNull(app.Auth.LastUsername);
        }

        [Test]
        public void Resume_WithNothingRemembered_MakesNoRequest()
        {
            var app = NewLaunch();

            var resume = app.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.NoSession, resume.Outcome);
            Assert.AreEqual(0, app.Transport.Requests.Count);
        }

        [Test]
        public void Resume_WithAnExpiredSession_ClearsIt()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            var second = NewLaunch();
            second.Transport.Enqueue(401, "{\"status\":\"error\",\"message\":\"Not authenticated\"}");
            var resume = second.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.Expired, resume.Outcome);
            Assert.AreEqual(ResumeOutcome.NoSession, NewLaunch().Auth.ResumeAsync().GetAwaiter().GetResult().Outcome);
        }

        [Test]
        public void Resume_WhenOffline_KeepsTheSession()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            var second = NewLaunch();
            second.Transport.EnqueueNetworkError("offline");
            var resume = second.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.Unavailable, resume.Outcome);
            Assert.IsTrue(second.Api.HasSession);
            Assert.AreEqual("tok1", NewLaunch().Session.Load());
        }

        [Test]
        public void Resume_DuringMaintenance_KeepsTheSession()
        {
            var first = NewLaunch();
            first.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            first.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();

            var second = NewLaunch();
            second.Transport.Enqueue(503, "{\"status\":\"maintenance\",\"message\":\"Back soon\"}");
            var resume = second.Auth.ResumeAsync().GetAwaiter().GetResult();

            Assert.AreEqual(ResumeOutcome.Unavailable, resume.Outcome);
            Assert.IsTrue(second.Api.HasSession);
        }

        [Test]
        public void Logout_ForgetsTheUserAndTheSession()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(200, LoginOk, "session_token=tok1; path=/");
            app.Auth.LoginAsync("alice", "pw", true).GetAwaiter().GetResult();
            app.Transport.Enqueue(200, "{\"status\":\"ok\"}");

            app.Auth.LogoutAsync().GetAwaiter().GetResult();

            Assert.IsNull(app.Auth.CurrentUser);
            Assert.IsFalse(app.Api.HasSession);
            Assert.AreEqual(ResumeOutcome.NoSession, NewLaunch().Auth.ResumeAsync().GetAwaiter().GetResult().Outcome);
        }

        [Test]
        public void ResendVerification_PostsTheTrimmedEmail()
        {
            var app = NewLaunch();
            app.Transport.Enqueue(200, "{\"status\":\"ok\",\"message\":\"If an account with that email exists...\"}");

            var result = app.Auth.ResendVerificationAsync(" a@example.com ").GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/resend-verification", app.Transport.LastRequest.Url);
            StringAssert.Contains("\"email\":\"a@example.com\"", app.Transport.LastRequest.Body);
        }
    }
}
