using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Shown when the server reports maintenance mode (503
    /// {"status":"maintenance"}) -- web-static's maintenance.html. The arg is
    /// the server's message; Retry re-runs startup.
    /// </summary>
    public sealed class MaintenanceScreen : UiScreen
    {
        private const string DefaultMessage = "MoodSwings is down for maintenance. Please try again shortly.";

        private Text _message;
        private bool _built;

        public string MessageText => _message != null ? _message.text : null;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            var message = args as string;
            _message.text = string.IsNullOrWhiteSpace(message) ? DefaultMessage : message;
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

            var column = UiFactory.CenteredColumn(transform, 720f, 28f);
            var title = UiFactory.Label(column, "Down for maintenance", 64, theme.accent, TextAnchor.MiddleCenter, FontStyle.Bold);
            UiFactory.Size(title.gameObject, height: 100f);

            _message = UiFactory.Label(column, DefaultMessage, 30, theme.textPrimary);
            UiFactory.Button(column, "Try again", theme, () => Router.Show<SplashScreen>(null, addToHistory: false));
        }
    }
}
