using System;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// First screen: shows the title while a remembered session (if any) is
    /// checked against /me, then routes to Home (still signed in) or Login.
    /// Shown for at least <see cref="MinSeconds"/> so it doesn't flash by.
    /// </summary>
    public sealed class SplashScreen : UiScreen
    {
        private const float MinSeconds = 0.8f;

        private Text _status;
        private Button _retry;
        private bool _built;
        private int _attempt;

        public override void OnShown(object args)
        {
            EnsureBuilt();
            Begin();
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

            var column = UiFactory.CenteredColumn(transform, 640f, 48f);
            UiFactory.TitleBlock(column, theme, 96);

            _status = UiFactory.Label(column, "Loading...", 28, theme.textMuted);
            _retry = UiFactory.Button(column, "Try again", theme, Begin, primary: false);
        }

        private async void Begin()
        {
            var attempt = ++_attempt;
            _status.text = "Loading...";
            _retry.gameObject.SetActive(false);

            try
            {
                var resume = AppServices.Auth.ResumeAsync();
                await Awaitable.WaitForSecondsAsync(MinSeconds);
                var result = await resume;

                // Something else (e.g. the maintenance screen) may have taken
                // over while we waited -- don't navigate over it.
                if (this == null || attempt != _attempt || Router.Current != this)
                {
                    return;
                }

                switch (result.Outcome)
                {
                    case ResumeOutcome.Resumed:
                        Router.ClearHistory();
                        Router.Show<HomeScreen>(result.User, addToHistory: false);
                        break;
                    case ResumeOutcome.Expired:
                        Router.Show<LoginScreen>("Your session has expired. Please log in again.", addToHistory: false);
                        break;
                    case ResumeOutcome.Unavailable:
                        _status.text = "Can't reach the server. Check your connection and try again.";
                        _retry.gameObject.SetActive(true);
                        break;
                    default:
                        Router.Show<LoginScreen>(null, addToHistory: false);
                        break;
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
                if (this != null && attempt == _attempt)
                {
                    _status.text = "Something went wrong starting up.";
                    _retry.gameObject.SetActive(true);
                }
            }
        }
    }
}
