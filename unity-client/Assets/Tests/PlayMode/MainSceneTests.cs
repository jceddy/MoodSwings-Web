using System;
using System.Collections;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.SceneManagement;
using UnityEngine.TestTools;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Loads the real Main scene against a scripted transport (no network)
    /// and walks the startup/login flows, capturing a screenshot of each
    /// state to look at.
    /// </summary>
    public class MainSceneTests
    {
        private const string UserJson = "{\"id\":7,\"username\":\"alice\",\"email\":\"a@example.com\",\"phone_number\":null}";

        internal sealed class ScriptedTransport : IHttpTransport
        {
            private readonly Func<HttpRequest, HttpResponse> _handler;

            public ScriptedTransport(Func<HttpRequest, HttpResponse> handler) => _handler = handler;

            public Task<HttpResponse> SendAsync(HttpRequest request, CancellationToken cancellationToken) =>
                Task.FromResult(_handler(request));
        }

        internal static HttpResponse Reply(int status, string body, string setCookie = null)
        {
            var response = new HttpResponse { StatusCode = status, Body = body };
            if (setCookie != null)
            {
                response.Headers["Set-Cookie"] = setCookie;
            }

            return response;
        }

        /// <summary>Answers /VERSION like the real site, everything else via the given handler.</summary>
        internal static Func<HttpRequest, HttpResponse> Site(Func<HttpRequest, HttpResponse> api)
        {
            return request => request.Url.EndsWith("/VERSION") ? Reply(200, "1.58.5\n") : api(request);
        }

        internal static IEnumerator Launch(Func<HttpRequest, HttpResponse> handler, string rememberedSession = null)
        {
            ScreenshotHelper.UseFullHdScreen();
            yield return null;

            var disk = new InMemoryKeyValueStore();
            var store = new SecureSessionStore(disk, new NullSecretProtector());
            if (rememberedSession != null)
            {
                store.Save(rememberedSession);
            }

            var api = new ApiClient(new ApiConfig("https://example.test", "MoodSwings/test"), new ScriptedTransport(Site(handler)), store);
            AppServices.Override(api, null, store, disk);

            yield return SceneManager.LoadSceneAsync("Main");
            yield return null;
        }

        internal static IEnumerator WaitFor<T>(float timeoutSeconds = 6f) where T : UiScreen
        {
            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            var waited = 0f;
            while (!(router.Current is T) && waited < timeoutSeconds)
            {
                waited += Time.unscaledDeltaTime;
                yield return null;
            }

            Assert.IsInstanceOf<T>(router.Current, "Timed out waiting for " + typeof(T).Name);

            // Let async follow-ups (footer version fetch, layout) land.
            for (var i = 0; i < 5; i++)
            {
                yield return null;
            }
        }

        internal static IEnumerator Await(Task task)
        {
            while (!task.IsCompleted)
            {
                yield return null;
            }

            task.GetAwaiter().GetResult();
            for (var i = 0; i < 3; i++)
            {
                yield return null;
            }
        }

        internal static T Screen<T>() where T : UiScreen => UnityEngine.Object.FindAnyObjectByType<T>(FindObjectsInactive.Include);

        internal static HttpResponse MeOk(HttpRequest request) =>
            request.Url.EndsWith("/app/me") ? Reply(200, "{\"status\":\"ok\",\"user\":" + UserJson + "}") : Reply(404, "{}");

        [UnityTest]
        public IEnumerator Splash_ShowsTheTitleWhileLoading()
        {
            yield return Launch(MeOk, rememberedSession: "tok");

            // The splash holds for at least 0.8s; look at it well inside that.
            for (var i = 0; i < 4; i++)
            {
                yield return null;
            }

            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            Assert.IsInstanceOf<SplashScreen>(router.Current);
            ScreenshotHelper.Capture("splash-loading");
        }

        [UnityTest]
        public IEnumerator NoRememberedSession_GoesStraightToLogin()
        {
            yield return Launch(_ => Reply(404, "{}"));
            yield return WaitFor<LoginScreen>();

            Assert.IsTrue(Screen<LoginScreen>().gameObject.activeInHierarchy);
            ScreenshotHelper.Capture("login");
        }

        [UnityTest]
        public IEnumerator RememberedSession_ResumesToHome()
        {
            yield return Launch(MeOk, rememberedSession: "tok");
            yield return WaitFor<HomeScreen>();

            StringAssert.Contains("alice", Screen<HomeScreen>().GreetingText);
            ScreenshotHelper.Capture("home");
        }

        [UnityTest]
        public IEnumerator ExpiredSession_GoesToLoginWithAMessage()
        {
            yield return Launch(_ => Reply(401, "{\"status\":\"error\",\"message\":\"Not authenticated\"}"), rememberedSession: "stale");
            yield return WaitFor<LoginScreen>();

            StringAssert.Contains("expired", Screen<LoginScreen>().ErrorText);
            ScreenshotHelper.Capture("login-expired");
        }

        [UnityTest]
        public IEnumerator Maintenance_ShowsTheMaintenanceScreenWithTheServersMessage()
        {
            yield return Launch(
                _ => Reply(503, "{\"status\":\"maintenance\",\"message\":\"Upgrading the database. Back in about 10 minutes.\"}"),
                rememberedSession: "tok");
            yield return WaitFor<MaintenanceScreen>();

            StringAssert.Contains("Back in about 10 minutes", Screen<MaintenanceScreen>().MessageText);
            ScreenshotHelper.Capture("maintenance");
        }

        [UnityTest]
        public IEnumerator Offline_StaysOnSplashWithARetry()
        {
            yield return Launch(_ => new HttpResponse { NetworkError = "Cannot resolve destination host" }, rememberedSession: "tok");
            yield return new WaitForSeconds(1.5f);
            yield return null;

            var router = UnityEngine.Object.FindAnyObjectByType<ScreenRouter>();
            Assert.IsInstanceOf<SplashScreen>(router.Current);
            ScreenshotHelper.Capture("splash-offline");
        }

        [UnityTest]
        public IEnumerator Login_Success_GoesToHome()
        {
            yield return Launch(request => request.Url.EndsWith("/app/login")
                ? Reply(200, "{\"status\":\"ok\",\"user\":" + UserJson + "}", "session_token=tok1; path=/")
                : Reply(404, "{}"));
            yield return WaitFor<LoginScreen>();

            yield return Await(Screen<LoginScreen>().SubmitAsync("alice", "pw", true));
            yield return WaitFor<HomeScreen>();

            StringAssert.Contains("alice", Screen<HomeScreen>().GreetingText);
            Assert.IsTrue(AppServices.Api.HasSession);
        }

        [UnityTest]
        public IEnumerator Login_BadCredentials_ShowsTheError()
        {
            yield return Launch(request => request.Url.EndsWith("/app/login")
                ? Reply(401, "{\"status\":\"error\",\"message\":\"Invalid username or password.\"}")
                : Reply(404, "{}"));
            yield return WaitFor<LoginScreen>();

            yield return Await(Screen<LoginScreen>().SubmitAsync("alice", "wrong", true));

            var login = Screen<LoginScreen>();
            Assert.AreEqual("Invalid username or password.", login.ErrorText);
            Assert.IsFalse(login.VerifyPanelVisible);
            ScreenshotHelper.Capture("login-bad-credentials");
        }

        [UnityTest]
        public IEnumerator Login_UnverifiedEmail_OffersToResendTheVerification()
        {
            yield return Launch(request => request.Url.EndsWith("/app/login")
                ? Reply(403, "{\"status\":\"error\",\"message\":\"Please verify your email address before logging in.\"}")
                : Reply(404, "{}"));
            yield return WaitFor<LoginScreen>();

            yield return Await(Screen<LoginScreen>().SubmitAsync("alice", "pw", true));

            var login = Screen<LoginScreen>();
            Assert.IsTrue(login.VerifyPanelVisible);
            StringAssert.Contains("verify your email", login.ErrorText);
            ScreenshotHelper.Capture("login-unverified");
        }

        [UnityTest]
        public IEnumerator Logout_ReturnsToLogin_AndForgetsTheSession()
        {
            yield return Launch(request => request.Url.EndsWith("/app/logout") ? Reply(200, "{\"status\":\"ok\"}") : MeOk(request), rememberedSession: "tok");
            yield return WaitFor<HomeScreen>();

            var logout = Screen<HomeScreen>().GetComponentsInChildren<UnityEngine.UI.Button>()
                .Single(b => b.GetComponentInChildren<UnityEngine.UI.Text>().text == "Log out");
            logout.onClick.Invoke();
            yield return WaitFor<LoginScreen>();

            Assert.IsFalse(AppServices.Api.HasSession);
        }
    }
}
