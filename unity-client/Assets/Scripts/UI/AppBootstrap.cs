using MoodSwings.Core;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Lives on the root Canvas of the Main scene next to the
    /// <see cref="ScreenRouter"/>: starts at the splash screen and reacts to
    /// the two server conditions any screen can run into -- maintenance mode
    /// and an expired session.
    /// </summary>
    [RequireComponent(typeof(ScreenRouter))]
    public sealed class AppBootstrap : MonoBehaviour
    {
        private ScreenRouter _router;
        private GameObject _banner;

        private void Start()
        {
            _router = GetComponent<ScreenRouter>();
            AppServices.Api.MaintenanceEntered += OnMaintenance;
            AppServices.Api.SessionExpired += OnSessionExpired;
            BuildServerBanner();
            _router.Show<SplashScreen>();
        }

        // A small tag in the corner while the app is on the dev server, so nobody mistakes it for production.
        private void BuildServerBanner()
        {
            var theme = AppServices.Theme;
            var tag = UiFactory.Create("Server banner", transform);
            tag.anchorMin = tag.anchorMax = tag.pivot = new Vector2(1f, 1f);
            tag.sizeDelta = new Vector2(150f, 34f);
            tag.anchoredPosition = new Vector2(-12f, -8f);
            var back = tag.gameObject.AddComponent<UnityEngine.UI.Image>();
            back.color = new Color(theme.danger.r, theme.danger.g, theme.danger.b, 0.85f);
            back.raycastTarget = false;
            var label = UiFactory.Label(tag, "DEV SERVER", 20, Color.white, TextAnchor.MiddleCenter, FontStyle.Bold);
            label.raycastTarget = false;
            UiFactory.Stretch(label.rectTransform);
            _banner = tag.gameObject;
            AppServices.ServerChanged += UpdateServerBanner;
            UpdateServerBanner();
        }

        private void UpdateServerBanner()
        {
            if (_banner != null)
            {
                _banner.SetActive(AppServices.IsDevServer);
                _banner.transform.SetAsLastSibling();
            }
        }

        private void OnDestroy()
        {
            AppServices.ServerChanged -= UpdateServerBanner;
            if (AppServices.Api != null)
            {
                AppServices.Api.MaintenanceEntered -= OnMaintenance;
                AppServices.Api.SessionExpired -= OnSessionExpired;
            }
        }

        private void OnMaintenance(string message)
        {
            if (_router.Current is MaintenanceScreen)
            {
                return;
            }

            _router.Show<MaintenanceScreen>(message, addToHistory: false);
        }

        private void OnSessionExpired()
        {
            // Splash and Login handle an expired/absent session themselves
            // (splash routes to Login with a message; Login has no session
            // to lose) -- this is for one that dies mid-use.
            if (_router.Current is SplashScreen || _router.Current is LoginScreen)
            {
                return;
            }

            AppServices.ClearUserData();
            _router.ClearHistory();
            _router.Show<LoginScreen>("Your session has expired. Please log in again.", addToHistory: false);
        }

        // Back in the app after a tap on a notification (it was already running): open what the notification points at.
        private void OnApplicationFocus(bool focused)
        {
            if (!focused || _router == null || AppServices.Push == null || AppServices.Auth?.CurrentUser == null)
            {
                return;
            }

            var current = _router.Current;
            if (current == null || current is SplashScreen || current is LoginScreen || current is MaintenanceScreen)
            {
                return;
            }

            PushRouter.Open(_router, AppServices.Push.TakeLaunchRoute());
        }

        private void Update()
        {
            if (AppExit.CanToggleFullscreen && KeyInput.FullscreenTogglePressed)
            {
                AppExit.ToggleFullscreen();
            }

            // Escape on desktop; the system Back button on Android.
            if (KeyInput.EscapePressed)
            {
                var current = _router.Current;
                if (current == null || !current.HandleBack())
                {
                    _router.Back();
                }
            }
        }
    }
}
