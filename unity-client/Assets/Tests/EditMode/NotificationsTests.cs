using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>The notification switches and the Discord link status.</summary>
    public class NotificationsTests
    {
        private const string Saved =
            @"{""status"":""ok"",""preferences"":{""notify_your_turn"":true,""notify_friend_request"":false,""notify_game_finished"":true,
              ""notify_chat_message"":true,""notify_timeout_warning"":true,""notify_achievement_unlocked"":true,""disable_cooldown"":false}}";

        private FakeHttpTransport _transport;
        private NotificationsFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _flow = new NotificationsFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        private void Load(string discord = "{\"status\":\"ok\",\"linked\":false,\"discord_username\":null}")
        {
            _transport.Enqueue(200, Saved);
            _transport.Enqueue(200, discord);
            Assert.IsTrue(_flow.LoadAsync().GetAwaiter().GetResult().Ok);
        }

        [Test]
        public void TheSwitches_ReadTheSavedPreferences()
        {
            Load();

            Assert.IsFalse(_flow.Preferences.FriendRequest);
            var friend = NotificationsFlow.Switches.Single(s => s.Label == "I receive a friend request");
            Assert.IsFalse(_flow.IsOn(friend));
            Assert.IsTrue(_flow.IsOn(NotificationsFlow.Switches.Single(s => s.Label == "It's my turn")));
            Assert.AreEqual(7, NotificationsFlow.Switches.Length);
        }

        [Test]
        public void ASwitch_SavesTheWholeSet_NotJustItself()
        {
            Load();
            _transport.Enqueue(200, Saved.Replace("\"notify_game_finished\":true", "\"notify_game_finished\":false"));

            var result = _flow.SetAsync(NotificationsFlow.Switches.Single(s => s.Label == "One of my games finishes"), false).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            var post = _transport.Requests.Last();
            Assert.AreEqual("https://example.test/app/notifications/preferences", post.Url);
            Assert.AreEqual("POST", post.Method);
            var body = JObject.Parse(post.Body);
            Assert.AreEqual(7, body.Count, "every field goes: the server reads a missing one as its default");
            Assert.IsFalse((bool)body["notify_game_finished"]);
            Assert.IsFalse((bool)body["notify_friend_request"], "kept as it was");
            Assert.IsTrue((bool)body["notify_your_turn"]);
            Assert.IsFalse(_flow.Preferences.GameFinished);
        }

        [Test]
        public void AFailedSave_LeavesTheValuesAsTheyWere()
        {
            Load();
            _transport.Enqueue(500, "{\"status\":\"error\",\"message\":\"The server hiccuped.\"}");

            var result = _flow.SetAsync(NotificationsFlow.Switches.Single(s => s.Label == "It's my turn"), false).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("The server hiccuped.", result.Message);
            Assert.IsTrue(_flow.Preferences.YourTurn);
        }

        [Test]
        public void DiscordStatus_NamesTheLinkedAccount_OrSaysNotLinked()
        {
            Load("{\"status\":\"ok\",\"linked\":true,\"discord_username\":\"jed\"}");
            Assert.AreEqual("Discord: linked as jed", _flow.DiscordLine());

            _flow.Clear();
            Assert.IsNull(_flow.DiscordLine());
            Load();
            Assert.AreEqual("Discord: not linked", _flow.DiscordLine());
        }

        [Test]
        public void IfThePreferencesWontLoad_ItSaysSo_ButDiscordStillReads()
        {
            _transport.EnqueueNetworkError("down");
            _transport.Enqueue(200, "{\"status\":\"ok\",\"linked\":true,\"discord_username\":\"jed\"}");

            var result = _flow.LoadAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
            Assert.IsNull(_flow.Preferences);
        }
    }
}
