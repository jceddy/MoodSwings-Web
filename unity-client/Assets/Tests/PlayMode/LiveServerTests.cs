using System;
using System.Collections;
using System.Threading.Tasks;
using MoodSwings.Networking;
using NUnit.Framework;
using UnityEngine.TestTools;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Talks to the real dev server through the real UnityWebRequest
    /// transport -- the one thing the fake-transport tests can't prove
    /// (that the host accepts our User-Agent, and that UnityWebRequest
    /// honors a hand-set Cookie header). Opt-in:
    ///   MOODSWINGS_LIVE_TESTS=1                               -> unauthenticated checks
    ///   + MOODSWINGS_USER / MOODSWINGS_PASSWORD (throwaway dev account) -> real login
    /// </summary>
    public class LiveServerTests
    {
        private static ApiClient NewClient() =>
            new ApiClient(new ApiConfig(ApiConfig.DevSiteRoot, "MoodSwings/live-test"), new UnityWebRequestTransport(), new InMemorySessionStore());

        private static void RequireLive()
        {
            Assume.That(Environment.GetEnvironmentVariable("MOODSWINGS_LIVE_TESTS"), Is.EqualTo("1"), "Set MOODSWINGS_LIVE_TESTS=1 to run.");
        }

        private static IEnumerator Wait(Task task)
        {
            var deadline = DateTime.UtcNow.AddSeconds(40);
            while (!task.IsCompleted && DateTime.UtcNow < deadline)
            {
                yield return null;
            }

            Assert.IsTrue(task.IsCompleted, "Timed out");
            task.GetAwaiter().GetResult();
        }

        [UnityTest]
        public IEnumerator BadCredentials_ReachTheApp_AndAreRefused()
        {
            RequireLive();
            var task = NewClient().LoginAsync("nobody-" + Guid.NewGuid().ToString("N").Substring(0, 8), "wrong");

            yield return Wait(task);

            // 401 = the app itself answered. A 406 here would mean the
            // host's filter rejected our User-Agent.
            Assert.AreEqual(ApiFailureKind.Unauthorized, task.Result.Failure, task.Result.Message);
            Assert.AreEqual(401, task.Result.HttpStatus);
        }

        [UnityTest]
        public IEnumerator ServerVersion_IsReadable()
        {
            RequireLive();
            var task = NewClient().GetServerVersionAsync();

            yield return Wait(task);

            Assert.IsTrue(task.Result.Ok, task.Result.Message);
            StringAssert.IsMatch(@"^\d+\.\d+\.\d+$", task.Result.Value);
        }

        [UnityTest]
        public IEnumerator RealLogin_ThenMe_ProvesTheCookieIsReplayed_ThenLogout()
        {
            RequireLive();
            var username = Environment.GetEnvironmentVariable("MOODSWINGS_USER");
            var password = Environment.GetEnvironmentVariable("MOODSWINGS_PASSWORD");
            Assume.That(username, Is.Not.Null.And.Not.Empty, "Set MOODSWINGS_USER / MOODSWINGS_PASSWORD to run.");

            var api = NewClient();

            var login = api.LoginAsync(username, password);
            yield return Wait(login);
            Assert.IsTrue(login.Result.Ok, login.Result.Message);
            Assert.IsTrue(api.HasSession, "login should have captured the session cookie");

            // /me only succeeds if our hand-set Cookie header was honored.
            var me = api.GetMeAsync();
            yield return Wait(me);
            Assert.IsTrue(me.Result.Ok, me.Result.Message);
            StringAssert.AreEqualIgnoringCase(username, me.Result.Value.User.Username);

            yield return Wait(api.LogoutAsync());
            Assert.IsFalse(api.HasSession);

            var after = api.GetMeAsync();
            yield return Wait(after);
            Assert.AreEqual(ApiFailureKind.Unauthorized, after.Result.Failure);
        }
    }
}
