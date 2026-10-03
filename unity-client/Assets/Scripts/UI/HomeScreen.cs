using System;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The main menu: who's signed in, and the way to everything else.
    /// Play shows how many games are waiting on you; Friends how many
    /// requests are.
    /// </summary>
    public sealed class HomeScreen : UiScreen
    {
        private Text _greeting;
        private Text _playLabel;
        private Text _friendsLabel;
        private bool _built;

        public string GreetingText => _greeting != null ? _greeting.text : null;

        public string PlayButtonText => _playLabel != null ? _playLabel.text : null;

        public string FriendsButtonText => _friendsLabel != null ? _friendsLabel.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            var user = args as User ?? AppServices.Auth.CurrentUser;
            _greeting.text = user != null ? "Signed in as " + user.Username : "Signed in";

            AppServices.Friends.Changed += UpdateBadges;
            AppServices.Lobby.Changed += UpdateBadges;
            UpdateBadges();
            RefreshBadgeData();
        }

        public override void OnHidden()
        {
            AppServices.Friends.Changed -= UpdateBadges;
            AppServices.Lobby.Changed -= UpdateBadges;
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

            var play = UiFactory.Button(column, "Play", theme, () => Router.Show<PlayScreen>());
            _playLabel = play.GetComponentInChildren<Text>();

            UiFactory.Button(column, "Puzzles", theme, () => Router.Show<PuzzlesScreen>(), primary: false);
            UiFactory.Button(column, "Decklists", theme, () => Router.Show<DecklistsScreen>(), primary: false);

            var friends = UiFactory.Button(column, "Friends", theme, () => Router.Show<FriendsScreen>(), primary: false);
            _friendsLabel = friends.GetComponentInChildren<Text>();

            UiFactory.Button(column, "Settings", theme, () => Router.Show<SettingsScreen>(), primary: false);
            UiFactory.Button(column, "Log out", theme, OnLogoutClicked, primary: false);
        }

        private void UpdateBadges()
        {
            var games = AppServices.Lobby.GamesNeedingYou;
            _playLabel.text = games > 0 ? $"Play  ({games} waiting on you)" : "Play";

            var requests = AppServices.Friends.IncomingCount;
            _friendsLabel.text = requests > 0 ? $"Friends  ({requests} new)" : "Friends";
        }

        /// <summary>Quietly fetches what the badges count; a failure just leaves them as they were.</summary>
        private async void RefreshBadgeData()
        {
            try
            {
                await Task.WhenAll(AppServices.Friends.RefreshAsync(), AppServices.Lobby.RefreshGamesAsync());
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
                AppServices.Lobby.Clear();
                AppServices.Puzzles.Clear();
                AppServices.Decklists.Clear();
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
