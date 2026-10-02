using System;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Friends: add by username or email, answer incoming requests, see who's
    /// online, remove a friend (two clicks, so it can't happen by accident).
    /// The list refreshes itself every <see cref="PollSeconds"/> while the
    /// screen is showing, since the server has no push channel.
    /// </summary>
    public sealed class FriendsScreen : UiScreen
    {
        private const float ColumnWidth = 900f;
        private const float PollSeconds = 15f;

        private InputField _addField;
        private Text _status;
        private RectTransform _list;
        private bool _built;
        private int _pollRun;
        private int _confirmingRemovalOfUserId;

        public string StatusText => _status != null ? _status.text : null;

        /// <summary>Everything currently in the scrolling list (section titles and rows), so tests can see what's on screen.</summary>
        public int RowCount => _list != null ? _list.childCount : 0;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _addField.text = string.Empty;
            SetStatus(string.Empty, isError: false);
            _confirmingRemovalOfUserId = 0;

            AppServices.Friends.Changed += Rebuild;
            Rebuild();
            Refresh();
            PollWhileShown();
        }

        public override void OnHidden()
        {
            AppServices.Friends.Changed -= Rebuild;
            _pollRun++;
        }

        private void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, "Friends", () => Router.Back());

            var column = UiFactory.Create("Column", transform);
            column.anchorMin = new Vector2(0.5f, 0f);
            column.anchorMax = new Vector2(0.5f, 1f);
            column.pivot = new Vector2(0.5f, 0.5f);
            column.offsetMin = new Vector2(-ColumnWidth / 2f, 40f);
            column.offsetMax = new Vector2(ColumnWidth / 2f, -130f);
            var layout = column.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 12f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            var addRow = UiFactory.Row(column, "AddRow", 16f, TextAnchor.MiddleCenter);
            _addField = UiFactory.Input(addRow.transform, "Add a friend by username or email", theme);
            UiFactory.Flexible(_addField.gameObject, width: 1f);
            _addField.onEndEdit.AddListener(_ =>
            {
                if (KeyInput.EnterPressed)
                {
                    OnSendClicked();
                }
            });
            var send = UiFactory.Button(addRow.transform, "Send request", theme, OnSendClicked);
            UiFactory.Size(send.gameObject, width: 260f);

            _status = UiFactory.Label(column, string.Empty, 26, theme.textMuted);
            UiFactory.Size(_status.gameObject, height: 40f);

            UiFactory.ScrollList(column, out _list);
            UiFactory.Flexible(_list.parent.parent.gameObject, height: 1f);
        }

        private void SetStatus(string message, bool isError)
        {
            var theme = AppServices.Theme;
            _status.text = message ?? string.Empty;
            _status.color = isError ? theme.danger : theme.textMuted;
        }

        private void Rebuild()
        {
            if (_list == null)
            {
                return;
            }

            // Destroy() waits for the end of the frame, so hide the old rows too
            // or the layout would still count them.
            foreach (Transform child in _list)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }

            var theme = AppServices.Theme;
            var friends = AppServices.Friends;

            if (friends.Incoming.Count > 0)
            {
                UiFactory.SectionTitle(_list, theme, $"Friend requests ({friends.Incoming.Count})");
                foreach (var invite in friends.Incoming)
                {
                    AddIncomingRow(theme, invite);
                }
            }

            if (friends.Outgoing.Count > 0)
            {
                UiFactory.SectionTitle(_list, theme, "Requests you sent");
                foreach (var invite in friends.Outgoing)
                {
                    var row = UiFactory.RowPanel(_list, theme);
                    var name = UiFactory.Label(row.transform, invite.OtherUsername, 30, theme.textPrimary, TextAnchor.MiddleLeft);
                    UiFactory.Flexible(name.gameObject, width: 1f);
                    UiFactory.Label(row.transform, "Waiting for a reply", 24, theme.textMuted, TextAnchor.MiddleRight);
                }
            }

            UiFactory.SectionTitle(_list, theme, $"Friends ({friends.Friends.Count})");
            if (friends.Friends.Count == 0)
            {
                var empty = UiFactory.Label(_list, "No friends yet. Send a request above.", 26, theme.textMuted);
                UiFactory.Size(empty.gameObject, height: 60f);
            }

            foreach (var friend in friends.Friends)
            {
                AddFriendRow(theme, friend);
            }
        }

        private void AddIncomingRow(UiTheme theme, FriendInvite invite)
        {
            var row = UiFactory.RowPanel(_list, theme);
            var name = UiFactory.Label(row.transform, invite.OtherUsername, 30, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Flexible(name.gameObject, width: 1f);

            var accept = UiFactory.Button(row.transform, "Accept", theme, () => RunAction(() => AppServices.Friends.RespondAsync(invite, "accept")));
            UiFactory.Size(accept.gameObject, width: 180f);
            var decline = UiFactory.Button(row.transform, "Decline", theme, () => RunAction(() => AppServices.Friends.RespondAsync(invite, "decline")), primary: false);
            UiFactory.Size(decline.gameObject, width: 180f);
        }

        private void AddFriendRow(UiTheme theme, Friend friend)
        {
            var row = UiFactory.RowPanel(_list, theme);

            var dot = UiFactory.Create("Presence", row.transform);
            dot.gameObject.AddComponent<Image>().color = friend.IsOnline ? new Color(0.35f, 0.80f, 0.45f) : theme.textMuted;
            UiFactory.Size(dot.gameObject, 20f, 20f);
            dot.gameObject.SetActive(friend.Presence != "hidden");

            var name = UiFactory.Label(row.transform, friend.Username, 30, theme.textPrimary, TextAnchor.MiddleLeft);
            UiFactory.Flexible(name.gameObject, width: 1f);

            var presence = friend.Presence == "online" ? "Online" : friend.Presence == "offline" ? "Offline" : string.Empty;
            UiFactory.Label(row.transform, presence, 24, theme.textMuted, TextAnchor.MiddleRight);

            var confirming = _confirmingRemovalOfUserId == friend.UserId;
            var remove = UiFactory.Button(row.transform, confirming ? "Sure?" : "Remove", theme, () =>
            {
                if (_confirmingRemovalOfUserId == friend.UserId)
                {
                    _confirmingRemovalOfUserId = 0;
                    RunAction(() => AppServices.Friends.RemoveAsync(friend));
                    return;
                }

                // First click only arms it; the second one removes.
                _confirmingRemovalOfUserId = friend.UserId;
                Rebuild();
            }, primary: false);
            UiFactory.Size(remove.gameObject, width: 180f);
        }

        private async void OnSendClicked()
        {
            try
            {
                var result = await AppServices.Friends.SendInviteAsync(_addField.text);
                if (this == null)
                {
                    return;
                }

                if (result.Ok)
                {
                    _addField.text = string.Empty;
                }

                SetStatus(result.Message, isError: !result.Ok);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void RunAction(Func<Task<FriendsActionResult>> action)
        {
            try
            {
                var result = await action();
                if (this == null)
                {
                    return;
                }

                SetStatus(result.Message, isError: !result.Ok);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void Refresh()
        {
            try
            {
                var result = await AppServices.Friends.RefreshAsync();
                if (this != null && !result.Ok)
                {
                    SetStatus(result.Message, isError: true);
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void PollWhileShown()
        {
            var run = ++_pollRun;
            try
            {
                while (true)
                {
                    await Awaitable.WaitForSecondsAsync(PollSeconds);
                    if (this == null || run != _pollRun)
                    {
                        return;
                    }

                    var result = await AppServices.Friends.RefreshAsync();
                    if (this == null || run != _pollRun)
                    {
                        return;
                    }

                    if (!result.Ok)
                    {
                        SetStatus(result.Message, isError: true);
                    }
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }
    }
}
