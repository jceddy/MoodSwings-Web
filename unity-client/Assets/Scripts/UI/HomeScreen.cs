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
        private Text _achievementsLabel;
        private RectTransform _gridRow;
        private bool _built;

        public string GreetingText => _greeting != null ? _greeting.text : null;

        public string PlayButtonText => _playLabel != null ? _playLabel.text : null;

        public string FriendsButtonText => _friendsLabel != null ? _friendsLabel.text : null;

        public string AchievementsButtonText => _achievementsLabel != null ? _achievementsLabel.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            var user = args as User ?? AppServices.Auth.CurrentUser;
            _greeting.text = user != null ? "Signed in as " + user.Username : "Signed in";

            AppServices.Friends.Changed += UpdateBadges;
            AppServices.Lobby.Changed += UpdateBadges;
            AppServices.Stats.Changed += UpdateBadges;
            UpdateBadges();
            RefreshBadgeData();
        }

        public override void OnHidden()
        {
            AppServices.Friends.Changed -= UpdateBadges;
            AppServices.Lobby.Changed -= UpdateBadges;
            AppServices.Stats.Changed -= UpdateBadges;
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

            var column = UiFactory.CenteredColumn(transform, 800f, 20f);
            UiFactory.TitleBlock(column, theme);

            _greeting = UiFactory.Label(column, string.Empty, 32, theme.textPrimary);
            UiFactory.Size(_greeting.gameObject, height: 70f);

            var play = UiFactory.Button(column, "Play", theme, () => Router.Show<PlayScreen>());
            _playLabel = play.GetComponentInChildren<Text>();

            // Everything but Play sits two to a row, so the menu keeps fitting a landscape screen as it grows.
            GridButton(column, "Puzzles", theme, () => Router.Show<PuzzlesScreen>());
            GridButton(column, "Decklists", theme, () => Router.Show<DecklistsScreen>());

            _friendsLabel = GridButton(column, "Friends", theme, () => Router.Show<FriendsScreen>());
            _achievementsLabel = GridButton(column, "Achievements", theme, () => Router.Show<AchievementsScreen>());

            GridButton(column, "Stats", theme, () => Router.Show<StatsScreen>());
            GridButton(column, "Settings", theme, () => Router.Show<SettingsScreen>());

            GridButton(column, "Log out", theme, OnLogoutClicked);

            // A full-screen desktop window has no close button of its own.
            if (AppExit.IsAvailable)
            {
                var quit = GridButton(column, "Quit", theme, () => AppExit.Quit());
                quit.GetComponentInParent<Button>().gameObject.name = "Quit";
            }
        }

        /// <summary>A secondary button in the next free slot of a two-wide row; returns its label.</summary>
        private Text GridButton(RectTransform column, string text, UiTheme theme, UnityEngine.Events.UnityAction onClick)
        {
            if (_gridRow == null || _gridRow.childCount >= 2)
            {
                var row = UiFactory.Row(column, "Row", 20f, TextAnchor.MiddleCenter);
                row.childForceExpandWidth = true;
                _gridRow = row.GetComponent<RectTransform>();
            }

            var button = UiFactory.Button(_gridRow, text, theme, onClick, primary: false);
            UiFactory.Flexible(button.gameObject, width: 1f);
            return button.GetComponentInChildren<Text>();
        }

        private void UpdateBadges()
        {
            var games = AppServices.Lobby.GamesNeedingYou;
            _playLabel.text = games > 0 ? $"Play  ({games} waiting on you)" : "Play";

            var requests = AppServices.Friends.IncomingCount;
            _friendsLabel.text = requests > 0 ? $"Friends  ({requests} new)" : "Friends";

            var unseen = AppServices.Stats.Unseen().Count;
            _achievementsLabel.text = unseen > 0 ? $"Achievements  ({unseen} new)" : "Achievements";
        }

        /// <summary>Quietly fetches what the badges count; a failure just leaves them as they were.</summary>
        private async void RefreshBadgeData()
        {
            try
            {
                await Task.WhenAll(
                    AppServices.Friends.RefreshAsync(), AppServices.Lobby.RefreshGamesAsync(), AppServices.Stats.RefreshAchievementsAsync());
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
                AppServices.Stats.Clear();
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
