using System.Collections.Generic;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class PreferencesFlowTests
    {
        // Spelled out here, not derived from the catalog, so a typo in the
        // catalog can't pass by agreeing with itself. These are the routes
        // and request keys php-app/public/index.php defines.
        private static readonly object[] ExpectedPreferences =
        {
            new object[] { "/user/default-selections-mode-preference", "default_selections_mode_preference" },
            new object[] { "/user/auto-pass-on-empty-hand-preference", "auto_pass_on_empty_hand" },
            new object[] { "/user/auto-apply-scoring-bonuses-preference", "auto_apply_scoring_bonuses" },
            new object[] { "/user/pause-before-own-turn-preference", "pause_before_own_turn" },
            new object[] { "/user/allow-custom-content-preference", "allow_custom_content" },
            new object[] { "/user/presence-preference", "share_presence" },
            new object[] { "/user/matchmaking-discoverable-preference", "matchmaking_discoverable" },
        };

        private FakeHttpTransport _transport;
        private AuthFlow _auth;
        private PreferencesFlow _preferences;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            var api = new ApiClient(new ApiConfig("https://example.test"), _transport);
            var disk = new InMemoryKeyValueStore();
            _auth = new AuthFlow(api, new SecureSessionStore(disk, new FakeSecretProtector()), disk);
            _preferences = new PreferencesFlow(api, _auth);
        }

        private void SignInAsCapturedUser()
        {
            _transport.Enqueue(200, TestFixtures.Read("me"));
            Assert.IsTrue(_auth.RefreshUserAsync().GetAwaiter().GetResult().Ok);
            _transport.Requests.Clear();
        }

        private static IEnumerable<BoolPreference> All() =>
            PreferenceCatalog.GameDefaults.Concat(PreferenceCatalog.Privacy);

        [Test]
        public void TheCatalog_CoversEveryPreferenceTheServerHas_WithTheRightRouteAndKey()
        {
            var actual = All().Select(p => (p.Route, p.JsonKey)).ToList();
            var expected = ExpectedPreferences.Cast<object[]>().Select(e => ((string)e[0], (string)e[1])).ToList();

            CollectionAssert.AreEquivalent(expected, actual);
        }

        [Test]
        public void EveryPreference_HasALabelAndDescription()
        {
            foreach (var preference in All())
            {
                Assert.IsNotEmpty(preference.Label, preference.Route);
                Assert.IsNotEmpty(preference.Description, preference.Route);
            }
        }

        [Test]
        public void Get_ReadsTheCapturedUsersRealValues()
        {
            SignInAsCapturedUser();

            // From the captured /me response (Assets/Tests/Fixtures/me.json).
            Assert.IsTrue(_preferences.Get(Find("/user/allow-custom-content-preference")));
            Assert.IsFalse(_preferences.Get(Find("/user/pause-before-own-turn-preference")));
            Assert.IsFalse(_preferences.Get(Find("/user/presence-preference")));
            Assert.IsTrue(_preferences.Get(Find("/user/matchmaking-discoverable-preference")));
            Assert.AreEqual("above_play_area", _preferences.GetBoardLayout());
        }

        [Test]
        public void Get_WhenTheServerHasntSaidYet_FallsBackToTheDefaults()
        {
            // A user straight from /login carries no preference flags.
            _transport.Enqueue(200, "{\"status\":\"ok\",\"user\":{\"id\":7,\"username\":\"alice\"}}");
            _auth.RefreshUserAsync().GetAwaiter().GetResult();

            Assert.IsTrue(_preferences.Get(Find("/user/auto-pass-on-empty-hand-preference")), "defaults on, like the web");
            Assert.IsFalse(_preferences.Get(Find("/user/pause-before-own-turn-preference")));
            Assert.AreEqual("above_play_area", _preferences.GetBoardLayout());
        }

        [Test]
        public void Get_WithNobodySignedIn_UsesTheDefaults()
        {
            Assert.IsTrue(_preferences.Get(Find("/user/auto-apply-scoring-bonuses-preference")));
        }

        [TestCaseSource(nameof(ExpectedPreferences))]
        public void Set_PostsTheRightKeyToTheRightRoute(string route, string key)
        {
            SignInAsCapturedUser();
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            var result = _preferences.SetAsync(Find(route), true).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app" + route, _transport.LastRequest.Url);
            Assert.AreEqual("POST", _transport.LastRequest.Method);
            Assert.AreEqual("{\"" + key + "\":true}", _transport.LastRequest.Body);
        }

        [Test]
        public void Set_Success_UpdatesTheLocalUser()
        {
            SignInAsCapturedUser();
            var pause = Find("/user/pause-before-own-turn-preference");
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            _preferences.SetAsync(pause, true).GetAwaiter().GetResult();

            Assert.IsTrue(_preferences.Get(pause));
            Assert.AreEqual(true, _auth.CurrentUser.PauseBeforeOwnTurn);
        }

        [Test]
        public void Set_Failure_LeavesTheLocalUserAlone()
        {
            SignInAsCapturedUser();
            var pause = Find("/user/pause-before-own-turn-preference");
            _transport.Enqueue(500, "{\"status\":\"error\",\"message\":\"oops\"}");

            var result = _preferences.SetAsync(pause, true).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.IsFalse(_preferences.Get(pause), "the screen rolls back to this");
        }

        [Test]
        public void Set_Offline_LeavesTheLocalUserAlone()
        {
            SignInAsCapturedUser();
            var pause = Find("/user/pause-before-own-turn-preference");
            _transport.EnqueueNetworkError("offline");

            var result = _preferences.SetAsync(pause, true).GetAwaiter().GetResult();

            Assert.AreEqual(ApiFailureKind.Network, result.Failure);
            Assert.IsFalse(_preferences.Get(pause));
        }

        [Test]
        public void BoardLayout_PostsTheLayout_AndUpdatesTheLocalUser()
        {
            SignInAsCapturedUser();
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            var result = _preferences.SetBoardLayoutAsync(PreferenceCatalog.BoardLayoutBelowHand).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/user/board-layout-preference", _transport.LastRequest.Url);
            Assert.AreEqual("{\"board_layout_preference\":\"below_hand\"}", _transport.LastRequest.Body);
            Assert.AreEqual("below_hand", _preferences.GetBoardLayout());
        }

        [Test]
        public void BoardLayout_FailureKeepsTheOldLayout()
        {
            SignInAsCapturedUser();
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"nope\"}");

            _preferences.SetBoardLayoutAsync(PreferenceCatalog.BoardLayoutBelowHand).GetAwaiter().GetResult();

            Assert.AreEqual("above_play_area", _preferences.GetBoardLayout());
        }

        [Test]
        public void RefreshUser_Failure_KeepsTheCurrentUser()
        {
            SignInAsCapturedUser();
            _transport.EnqueueNetworkError("offline");

            var result = _auth.RefreshUserAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("bshaftoe", _auth.CurrentUser.Username);
        }

        private static BoolPreference Find(string route) => All().Single(p => p.Route == route);
    }
}
