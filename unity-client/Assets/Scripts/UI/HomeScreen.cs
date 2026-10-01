using System;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Placeholder landing screen after login -- proves the session works
    /// and gives a way to log out. The real main menu is Phase 2.
    /// </summary>
    public sealed class HomeScreen : UiScreen
    {
        private Text _greeting;
        private bool _built;

        public string GreetingText => _greeting != null ? _greeting.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            var user = args as User ?? AppServices.Auth.CurrentUser;
            _greeting.text = user != null ? "Signed in as " + user.Username : "Signed in";
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

            var column = UiFactory.CenteredColumn(transform, 640f, 24f);
            UiFactory.TitleBlock(column, theme);

            _greeting = UiFactory.Label(column, string.Empty, 36, theme.textPrimary);
            UiFactory.Label(column, "The main menu is coming in the next phase.", 26, theme.textMuted);
            UiFactory.Button(column, "Log out", theme, OnLogoutClicked, primary: false);
        }

        private async void OnLogoutClicked()
        {
            try
            {
                await AppServices.Auth.LogoutAsync();
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
