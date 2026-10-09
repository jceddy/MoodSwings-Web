using System;
using System.Collections;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;
using UnityEngine.UI;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Drives the real Main scene through the main menu, Friends and Settings
    /// against a small stateful fake of the server (no network), pressing the
    /// actual buttons and checking the requests that go out.
    /// </summary>
    public class PhaseTwoSceneTests
    {
        private const string NoFriendsOrInvites = "{\"status\":\"ok\",\"incoming\":[],\"outgoing\":[]}";

        private sealed class FakeServer
        {
            public List<string> Calls { get; } = new List<string>();
            public string FriendsJson = Fixture("friends");
            public string InvitesJson = Fixture("friends_invites_pending");
            public bool FailPreferenceSaves;
            public HttpResponse InviteResponse = MainSceneTests.Reply(201, "{\"status\":\"ok\",\"message\":\"Friend request sent.\",\"user\":{\"id\":9,\"username\":\"Dana\"}}");

            public HttpResponse Handle(HttpRequest request)
            {
                var path = request.Url.Substring(request.Url.IndexOf("/app/", StringComparison.Ordinal) + 4);
                Calls.Add($"{request.Method} {path} {request.Body}");

                switch (path)
                {
                    case "/me":
                        return MainSceneTests.Reply(200, Fixture("me"));
                    case "/friends":
                        return MainSceneTests.Reply(200, FriendsJson);
                    case "/friends/invites":
                        return MainSceneTests.Reply(200, InvitesJson);
                    case "/friends/invite":
                        return InviteResponse;
                    case "/friends/respond":
                        // Alice (user 5) accepted: she becomes a friend, and only the sent request remains.
                        FriendsJson = "{\"status\":\"ok\",\"friends\":[" +
                            "{\"id\":17,\"friend_id\":1,\"friend_username\":\"jceddy\",\"created_at\":\"x\",\"presence\":\"online\"}," +
                            "{\"id\":21,\"friend_id\":5,\"friend_username\":\"Alice\",\"created_at\":\"x\",\"presence\":\"offline\"}]}";
                        InvitesJson = "{\"status\":\"ok\",\"incoming\":[],\"outgoing\":[{\"id\":17,\"other_user_id\":12,\"other_username\":\"Cleo\",\"created_at\":\"x\"}]}";
                        return MainSceneTests.Reply(200, "{\"status\":\"ok\",\"message\":\"Friend request accepted.\"}");
                    case "/friends/remove":
                        FriendsJson = "{\"status\":\"ok\",\"friends\":[]}";
                        return MainSceneTests.Reply(200, "{\"status\":\"ok\",\"message\":\"Friend removed.\"}");
                }

                if (path.StartsWith("/user/") && path.EndsWith("-preference"))
                {
                    return FailPreferenceSaves
                        ? MainSceneTests.Reply(500, "{\"status\":\"error\",\"message\":\"The server hiccuped.\"}")
                        : MainSceneTests.Reply(200, "{\"status\":\"ok\"}");
                }

                return MainSceneTests.Reply(404, "{\"status\":\"error\"}");
            }
        }

        private static string Fixture(string name) =>
            File.ReadAllText(Path.Combine(Application.dataPath, "Tests", "Fixtures", name + ".json"));

        /// <summary>
        /// The visible button with this text. When several match (every request
        /// row has its own Accept), the top-most one: Unity gives no stable
        /// order for FindObjectsByType, and "first row" is what a player means.
        /// </summary>
        internal static Button FindButton(string text) =>
            UnityEngine.Object.FindObjectsByType<Button>(FindObjectsInactive.Exclude)
                .Where(b => b.GetComponentInChildren<Text>()?.text == text)
                .OrderByDescending(b => b.transform.position.y)
                .FirstOrDefault();

        internal static Toggle FindToggle(string label) =>
            UnityEngine.Object.FindObjectsByType<Toggle>(FindObjectsInactive.Exclude)
                .FirstOrDefault(t => t.GetComponentInChildren<Text>()?.text == label);

        internal static IEnumerator Frames(int count = 6)
        {
            for (var i = 0; i < count; i++)
            {
                yield return null;
            }
        }

        internal static IEnumerator Click(string buttonText)
        {
            var button = FindButton(buttonText);
            Assert.IsNotNull(button, $"No visible button '{buttonText}'");
            button.onClick.Invoke();
            yield return Frames();
        }

        private static IEnumerator SignedInAtHome(FakeServer server)
        {
            yield return MainSceneTests.Launch(server.Handle, rememberedSession: "tok");
            yield return MainSceneTests.WaitFor<HomeScreen>();
        }

        private static IEnumerator OpenFriends(FakeServer server)
        {
            yield return SignedInAtHome(server);
            yield return Click("Friends  (2 new)");
            yield return MainSceneTests.WaitFor<FriendsScreen>();
        }

        private static IEnumerator OpenSettings(FakeServer server)
        {
            yield return SignedInAtHome(server);
            yield return Click("Settings");
            yield return MainSceneTests.WaitFor<SettingsScreen>();
        }

        [UnityTest]
        public IEnumerator Home_ShowsHowManyFriendRequestsAreWaiting()
        {
            var server = new FakeServer();
            yield return SignedInAtHome(server);

            Assert.AreEqual("Friends  (2 new)", MainSceneTests.Screen<HomeScreen>().FriendsButtonText);
            StringAssert.Contains("bshaftoe", MainSceneTests.Screen<HomeScreen>().GreetingText);
            ScreenshotHelper.Capture("home-menu");
        }

        [UnityTest]
        public IEnumerator Home_WithNoRequests_ShowsAPlainFriendsButton()
        {
            var server = new FakeServer { InvitesJson = NoFriendsOrInvites };
            yield return SignedInAtHome(server);

            Assert.AreEqual("Friends", MainSceneTests.Screen<HomeScreen>().FriendsButtonText);
        }

        [UnityTest]
        public IEnumerator Friends_ListsRequestsSentRequestsAndFriends()
        {
            var server = new FakeServer();
            yield return OpenFriends(server);

            // 3 section titles + 2 incoming + 1 outgoing + 1 friend.
            Assert.AreEqual(7, MainSceneTests.Screen<FriendsScreen>().RowCount);
            Assert.IsNotNull(FindButton("Accept"));
            Assert.IsNotNull(FindButton("Decline"));
            Assert.IsNotNull(FindButton("Remove"));
            ScreenshotHelper.Capture("friends");
        }

        [UnityTest]
        public IEnumerator Friends_AcceptingARequest_TellsTheServerAndRefreshes()
        {
            var server = new FakeServer();
            yield return OpenFriends(server);

            yield return Click("Accept");

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("POST /friends/respond") && c.Contains("\"user_id\":5") && c.Contains("\"action\":\"accept\"")),
                string.Join("\n", server.Calls));
            // Now: sent-requests title + 1, friends title + 2.
            Assert.AreEqual(5, MainSceneTests.Screen<FriendsScreen>().RowCount);
            Assert.AreEqual(0, AppServices.Friends.IncomingCount);
        }

        [UnityTest]
        public IEnumerator Friends_SendingARequest_ShowsTheServersAnswer()
        {
            var server = new FakeServer();
            yield return OpenFriends(server);
            var field = MainSceneTests.Screen<FriendsScreen>().GetComponentInChildren<InputField>();
            field.text = "  Dana ";

            yield return Click("Send request");

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("POST /friends/invite") && c.Contains("\"username_or_email\":\"Dana\"")));
            Assert.AreEqual("Friend request sent.", MainSceneTests.Screen<FriendsScreen>().StatusText);
            Assert.AreEqual(string.Empty, field.text, "cleared after a successful send");
        }

        [UnityTest]
        public IEnumerator Friends_SendingToSomeoneWhoDoesntExist_ShowsWhy()
        {
            var server = new FakeServer
            {
                InviteResponse = MainSceneTests.Reply(404, "{\"status\":\"error\",\"message\":\"No user found with that username or email.\"}"),
            };
            yield return OpenFriends(server);
            var field = MainSceneTests.Screen<FriendsScreen>().GetComponentInChildren<InputField>();
            field.text = "nobody";

            yield return Click("Send request");

            Assert.AreEqual("No user found with that username or email.", MainSceneTests.Screen<FriendsScreen>().StatusText);
            Assert.AreEqual("nobody", field.text, "kept so it can be corrected");
            ScreenshotHelper.Capture("friends-send-error");
        }

        [UnityTest]
        public IEnumerator Friends_RemovingAFriend_NeedsASecondClick()
        {
            var server = new FakeServer();
            yield return OpenFriends(server);

            yield return Click("Remove");
            Assert.IsFalse(server.Calls.Any(c => c.StartsWith("POST /friends/remove")), "the first click only arms it");
            Assert.IsNotNull(FindButton("Sure?"));
            ScreenshotHelper.Capture("friends-remove-confirm");

            yield return Click("Sure?");

            Assert.IsTrue(server.Calls.Any(c => c.StartsWith("POST /friends/remove") && c.Contains("\"user_id\":1")));
            Assert.AreEqual(0, AppServices.Friends.Friends.Count);
        }

        [UnityTest]
        public IEnumerator Friends_BackReturnsToTheMenu()
        {
            var server = new FakeServer();
            yield return OpenFriends(server);

            yield return Click("< Back");
            yield return MainSceneTests.WaitFor<HomeScreen>();

            Assert.IsInstanceOf<HomeScreen>(UnityEngine.Object.FindAnyObjectByType<ScreenRouter>().Current);
        }

        [UnityTest]
        public IEnumerator Settings_ShowsTheSavedValues_AndSavesAFlippedToggle()
        {
            var server = new FakeServer();
            yield return OpenSettings(server);

            var pause = FindToggle("Pause at the start of your turn");
            Assert.IsNotNull(pause);
            Assert.IsFalse(pause.isOn, "the captured account has this off");
            Assert.IsTrue(FindToggle("Show custom card/effect formats").isOn, "and this on");
            ScreenshotHelper.Capture("settings");

            pause.isOn = true;
            yield return Frames();

            Assert.IsTrue(server.Calls.Any(c => c == "POST /user/pause-before-own-turn-preference {\"pause_before_own_turn\":true}"),
                string.Join("\n", server.Calls));
            Assert.AreEqual(true, AppServices.Auth.CurrentUser.PauseBeforeOwnTurn);
            Assert.AreEqual("Saved.", MainSceneTests.Screen<SettingsScreen>().StatusText);
            Assert.IsTrue(pause.isOn);
        }

        [UnityTest]
        public IEnumerator Settings_AFailedSave_FlipsTheToggleBackAndSaysWhy()
        {
            var server = new FakeServer { FailPreferenceSaves = true };
            yield return OpenSettings(server);
            var pause = FindToggle("Pause at the start of your turn");

            pause.isOn = true;
            yield return Frames();

            Assert.IsFalse(pause.isOn, "rolled back");
            Assert.AreEqual("The server hiccuped.", MainSceneTests.Screen<SettingsScreen>().StatusText);
            Assert.AreNotEqual(true, AppServices.Auth.CurrentUser.PauseBeforeOwnTurn);
            ScreenshotHelper.Capture("settings-save-error");
        }

        [UnityTest]
        public IEnumerator Settings_ReadsTheLatestValuesFromTheServerEachTimeItOpens()
        {
            var server = new FakeServer();
            yield return OpenSettings(server);

            yield return Click("< Back");
            yield return MainSceneTests.WaitFor<HomeScreen>();
            yield return Click("Settings");
            yield return MainSceneTests.WaitFor<SettingsScreen>();

            // /login doesn't carry the preference flags, so every open re-reads /me:
            // once for the splash's session check, then once per Settings open.
            Assert.AreEqual(3, server.Calls.Count(c => c == "GET /me "), string.Join(", ", server.Calls));
        }

        [UnityTest]
        public IEnumerator LoggingOut_ForgetsTheFriends()
        {
            var server = new FakeServer();
            yield return SignedInAtHome(server);
            Assert.Greater(AppServices.Friends.Friends.Count, 0);

            yield return Click("Log out");
            yield return MainSceneTests.WaitFor<LoginScreen>();

            Assert.AreEqual(0, AppServices.Friends.Friends.Count);
            Assert.AreEqual(0, AppServices.Friends.IncomingCount);
        }
    }
}
