using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>The outcome of a friends action, ready to show: Message is null when there's nothing to say.</summary>
    public sealed class FriendsActionResult
    {
        public bool Ok { get; set; }

        public string Message { get; set; }
    }

    /// <summary>
    /// The friends list and pending requests, plus the actions on them.
    /// Holds the last fetched state so screens (and the home menu's request
    /// badge) read from one place; raises <see cref="Changed"/> whenever it
    /// is replaced. Free of UI so it tests against a fake transport.
    /// </summary>
    public sealed class FriendsFlow
    {
        private readonly ApiClient _api;

        private List<Friend> _friends = new List<Friend>();
        private List<FriendInvite> _incoming = new List<FriendInvite>();
        private List<FriendInvite> _outgoing = new List<FriendInvite>();

        public FriendsFlow(ApiClient api)
        {
            _api = api;
        }

        /// <summary>Online friends first, then alphabetically.</summary>
        public IReadOnlyList<Friend> Friends => _friends;

        public IReadOnlyList<FriendInvite> Incoming => _incoming;

        public IReadOnlyList<FriendInvite> Outgoing => _outgoing;

        public int IncomingCount => _incoming.Count;

        public event Action Changed;

        /// <summary>Forgets everything -- on logout or an expired session, so the next account never sees the last one's friends.</summary>
        public void Clear()
        {
            _friends = new List<Friend>();
            _incoming = new List<FriendInvite>();
            _outgoing = new List<FriendInvite>();
            Changed?.Invoke();
        }

        /// <summary>Fetches the friends and the pending requests together; state is replaced only if both succeed.</summary>
        public async Task<FriendsActionResult> RefreshAsync(CancellationToken cancellationToken = default)
        {
            var friendsTask = _api.ListFriendsAsync(cancellationToken);
            var invitesTask = _api.ListFriendInvitesAsync(cancellationToken);
            var friends = await friendsTask;
            var invites = await invitesTask;

            if (!friends.Ok)
            {
                return Failed(friends.UserMessage("Couldn't load your friends."));
            }

            if (!invites.Ok)
            {
                return Failed(invites.UserMessage("Couldn't load your friend requests."));
            }

            _friends = friends.Value.Friends
                .OrderByDescending(f => f.IsOnline)
                .ThenBy(f => f.Username, StringComparer.OrdinalIgnoreCase)
                .ToList();
            _incoming = invites.Value.Incoming;
            _outgoing = invites.Value.Outgoing;
            Changed?.Invoke();
            return new FriendsActionResult { Ok = true };
        }

        public async Task<FriendsActionResult> SendInviteAsync(string usernameOrEmail, CancellationToken cancellationToken = default)
        {
            usernameOrEmail = (usernameOrEmail ?? string.Empty).Trim();
            if (usernameOrEmail.Length == 0)
            {
                return Failed("Enter a username or email address.");
            }

            var result = await _api.SendFriendInviteAsync(usernameOrEmail, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't send the friend request."));
            }

            await RefreshAsync(cancellationToken);
            return new FriendsActionResult { Ok = true, Message = result.Value.Message ?? "Friend request sent." };
        }

        /// <param name="action">"accept" or "decline".</param>
        public async Task<FriendsActionResult> RespondAsync(FriendInvite invite, string action, CancellationToken cancellationToken = default)
        {
            var result = await _api.RespondToFriendInviteAsync(invite.OtherUserId, action, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't answer the friend request."));
            }

            await RefreshAsync(cancellationToken);
            return new FriendsActionResult { Ok = true };
        }

        public async Task<FriendsActionResult> RemoveAsync(Friend friend, CancellationToken cancellationToken = default)
        {
            var result = await _api.RemoveFriendAsync(friend.UserId, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't remove that friend."));
            }

            await RefreshAsync(cancellationToken);
            return new FriendsActionResult { Ok = true };
        }

        private static FriendsActionResult Failed(string message) =>
            new FriendsActionResult { Ok = false, Message = message };
    }
}
