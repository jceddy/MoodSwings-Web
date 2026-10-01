using System.Linq;
using MoodSwings.Core;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    public class FriendsFlowTests
    {
        private FakeHttpTransport _transport;
        private FriendsFlow _friends;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _friends = new FriendsFlow(new ApiClient(new ApiConfig("https://example.test"), _transport));
        }

        /// <summary>Queues the two responses a refresh makes: /friends, then /friends/invites.</summary>
        private void EnqueueRefresh(string friendsJson, string invitesJson)
        {
            _transport.Enqueue(200, friendsJson);
            _transport.Enqueue(200, invitesJson);
        }

        private static string Friend(int friendId, string name, string presence) =>
            $"{{\"id\":{friendId + 100},\"friend_id\":{friendId},\"friend_username\":\"{name}\",\"created_at\":\"2026-07-24 21:40:56\",\"presence\":\"{presence}\"}}";

        [Test]
        public void Refresh_LoadsTheRealCapturedFriendsAndPendingRequests()
        {
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites_pending"));

            var result = _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(1, _friends.Friends.Count);
            Assert.AreEqual("jceddy", _friends.Friends[0].Username);
            Assert.AreEqual(1, _friends.Friends[0].UserId);
            Assert.AreEqual(17, _friends.Friends[0].FriendshipId);
            Assert.IsTrue(_friends.Friends[0].IsOnline);
            Assert.AreEqual(2, _friends.IncomingCount);
            Assert.AreEqual("Alice", _friends.Incoming[0].OtherUsername);
            Assert.AreEqual(5, _friends.Incoming[0].OtherUserId);
            Assert.AreEqual("Cleo", _friends.Outgoing.Single().OtherUsername);
            Assert.AreEqual("https://example.test/app/friends", _transport.Requests[0].Url);
            Assert.AreEqual("https://example.test/app/friends/invites", _transport.Requests[1].Url);
        }

        [Test]
        public void Refresh_OrdersOnlineFirst_ThenAlphabetically_IgnoringCase()
        {
            var friends = "{\"status\":\"ok\",\"friends\":[" + string.Join(",",
                Friend(1, "zed", "offline"), Friend(2, "Bea", "online"), Friend(3, "amy", "offline"),
                Friend(4, "Cal", "hidden"), Friend(5, "al", "online")) + "]}";
            EnqueueRefresh(friends, "{\"status\":\"ok\",\"incoming\":[],\"outgoing\":[]}");

            _friends.RefreshAsync().GetAwaiter().GetResult();

            CollectionAssert.AreEqual(
                new[] { "al", "Bea", "amy", "Cal", "zed" }, _friends.Friends.Select(f => f.Username).ToArray());
        }

        [Test]
        public void HiddenPresence_IsNotOnline()
        {
            EnqueueRefresh("{\"status\":\"ok\",\"friends\":[" + Friend(1, "x", "hidden") + "]}", "{\"status\":\"ok\"}");

            _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(_friends.Friends[0].IsOnline);
            Assert.AreEqual("hidden", _friends.Friends[0].Presence);
        }

        [Test]
        public void Refresh_MissingListsInTheResponse_BecomeEmptyNotNull()
        {
            EnqueueRefresh("{\"status\":\"ok\"}", "{\"status\":\"ok\"}");

            var result = _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual(0, _friends.Friends.Count);
            Assert.AreEqual(0, _friends.Incoming.Count);
        }

        [Test]
        public void Refresh_RaisesChanged()
        {
            var raised = 0;
            _friends.Changed += () => raised++;
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites"));

            _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.AreEqual(1, raised);
        }

        [Test]
        public void Refresh_WhenOffline_FailsWithAMessage_AndKeepsWhatItHad()
        {
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites_pending"));
            _friends.RefreshAsync().GetAwaiter().GetResult();
            _transport.EnqueueNetworkError("offline");
            _transport.EnqueueNetworkError("offline");

            var result = _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("Can't reach the server", result.Message);
            Assert.AreEqual(1, _friends.Friends.Count);
            Assert.AreEqual(2, _friends.IncomingCount);
        }

        [Test]
        public void Refresh_WhenOnlyOneOfTheTwoCallsFails_ReplacesNothing()
        {
            _transport.Enqueue(200, TestFixtures.Read("friends"));
            _transport.Enqueue(500, "{\"status\":\"error\"}");

            var result = _friends.RefreshAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual(0, _friends.Friends.Count);
        }

        [Test]
        public void SendInvite_PostsTheTrimmedText_ThenRefreshes()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\",\"message\":\"Friend request sent.\",\"user\":{\"id\":12,\"username\":\"Cleo\"}}");
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites_pending"));

            var result = _friends.SendInviteAsync("  Cleo ").GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("Friend request sent.", result.Message);
            Assert.AreEqual("https://example.test/app/friends/invite", _transport.Requests[0].Url);
            Assert.AreEqual("POST", _transport.Requests[0].Method);
            StringAssert.Contains("\"username_or_email\":\"Cleo\"", _transport.Requests[0].Body);
            Assert.AreEqual(3, _transport.Requests.Count, "invite, then the refresh's two GETs");
            Assert.AreEqual(1, _friends.Outgoing.Count);
        }

        [TestCase("")]
        [TestCase("   ")]
        [TestCase(null)]
        public void SendInvite_WithNothingTyped_MakesNoRequest(string text)
        {
            var result = _friends.SendInviteAsync(text).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("Enter a username or email address.", result.Message);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [TestCase(404, "No user found with that username or email.")]
        [TestCase(409, "You're already friends with that user.")]
        public void SendInvite_ShowsTheServersReason(int status, string message)
        {
            _transport.Enqueue(status, $"{{\"status\":\"error\",\"message\":\"{message}\"}}");

            var result = _friends.SendInviteAsync("someone").GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual(message, result.Message);
            Assert.AreEqual(1, _transport.Requests.Count, "a failed invite doesn't refresh");
        }

        [TestCase("accept")]
        [TestCase("decline")]
        public void Respond_PostsTheSendersUserId_ThenRefreshes(string action)
        {
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites_pending"));
            _friends.RefreshAsync().GetAwaiter().GetResult();
            _transport.Requests.Clear();
            _transport.Enqueue(200, "{\"status\":\"ok\",\"message\":\"Done.\"}");
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites"));

            var result = _friends.RespondAsync(_friends.Incoming[0], action).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/friends/respond", _transport.Requests[0].Url);
            StringAssert.Contains("\"user_id\":5", _transport.Requests[0].Body);
            StringAssert.Contains($"\"action\":\"{action}\"", _transport.Requests[0].Body);
            Assert.AreEqual(0, _friends.IncomingCount, "refreshed state has no pending requests");
        }

        [Test]
        public void Remove_PostsTheFriendsUserId_NotTheFriendshipId()
        {
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites"));
            _friends.RefreshAsync().GetAwaiter().GetResult();
            _transport.Requests.Clear();
            _transport.Enqueue(200, "{\"status\":\"ok\",\"message\":\"Friend removed.\"}");
            EnqueueRefresh("{\"status\":\"ok\",\"friends\":[]}", TestFixtures.Read("friends_invites"));

            var result = _friends.RemoveAsync(_friends.Friends[0]).GetAwaiter().GetResult();

            Assert.IsTrue(result.Ok);
            Assert.AreEqual("https://example.test/app/friends/remove", _transport.Requests[0].Url);
            StringAssert.Contains("\"user_id\":1", _transport.Requests[0].Body);
            StringAssert.DoesNotContain("\"user_id\":17", _transport.Requests[0].Body);
            Assert.AreEqual(0, _friends.Friends.Count);
        }

        [Test]
        public void Remove_Failing_KeepsTheFriend()
        {
            EnqueueRefresh(TestFixtures.Read("friends"), TestFixtures.Read("friends_invites"));
            _friends.RefreshAsync().GetAwaiter().GetResult();
            _transport.Enqueue(404, "{\"status\":\"error\",\"message\":\"Friendship not found.\"}");

            var result = _friends.RemoveAsync(_friends.Friends[0]).GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.AreEqual("Friendship not found.", result.Message);
            Assert.AreEqual(1, _friends.Friends.Count);
        }
    }
}
