using System;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The main menu: who's signed in, and the way to everything else.
    /// Play is a placeholder until the lobby (Phase 3). Friends shows how
    /// many requests are waiting.
    /// </summary>
    public sealed class HomeScreen : UiScreen
    {
        private Text _greeting;
        private Text _friendsLabel;
        private bool _built;

        public string GreetingText => _greeting != null ? _greeting.text : null;

        public string FriendsButtonText => _friendsLabel != null ? _friendsLabel.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            var user = args as User ?? AppServices.Auth.CurrentUser;
            _greeting.text = user != null ? "Signed in as " + user.Username : "Signed in";

            AppServices.Friends.Changed += UpdateFriendsBadge;
            UpdateFriendsBadge();
            RefreshFriends();
        }

        public override void OnHidden()
        {
            AppServices.Friends.Changed -= UpdateFriendsBadge;
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

            var column = UiFactory.CenteredColumn(transform, 640f, 20f);
            UiFactory.TitleBlock(column, theme);

            _greeting = UiFactory.Label(column, string.Empty, 32, theme.textPrimary);
            UiFactory.Size(_greeting.gameObject, height: 70f);

            var play = UiFactory.Button(column, "Play  -  coming soon", theme, () => { });
            play.interactable = false;

            var friends = UiFactory.Button(column, "Friends", theme, () => Router.Show<FriendsScreen>(), primary: false);
            _friendsLabel = friends.GetComponentInChildren<Text>();

            UiFactory.Button(column, "Settings", theme, () => Router.Show<SettingsScreen>(), primary: false);
            UiFactory.Button(column, "Log out", theme, OnLogoutClicked, primary: false);
        }

        private void UpdateFriendsBadge()
        {
            var waiting = AppServices.Friends.IncomingCount;
            _friendsLabel.text = waiting > 0 ? $"Friends  ({waiting} new)" : "Friends";
        }

        /// <summary>Quietly looks for waiting requests so the badge is current; a failure just leaves it as it was.</summary>
        private async void RefreshFriends()
        {
            try
            {
                await AppServices.Friends.RefreshAsync();
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        private async void OnLogoutClicked()
        {
            try
            {
                await AppServices.Auth.LogoutAsync();
                AppServices.Friends.Clear();
                if (this == null)
                {
                    return;
                }

                Router.ClearHistory();
                Router.Show<LoginScreen>(null, addToHistory: false);
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }
    }
}
