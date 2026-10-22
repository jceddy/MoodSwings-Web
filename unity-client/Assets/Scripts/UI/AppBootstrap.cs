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
        private UpdatePrompt _updates;
        private bool _updatesChecked;
        private TourOverlay _tour;

        /// <summary>The welcome tour's overlay.</summary>
        public TourOverlay Tour => _tour;
        private bool _tourOffered;
        private bool _tourStarting;
        private GameObject _banner;
        private Rect _bannerSafeArea;
        private Vector2Int _bannerScreenSize;

        private void Start()
        {
            _router = GetComponent<ScreenRouter>();
            AppServices.Api.MaintenanceEntered += OnMaintenance;
            AppServices.Api.SessionExpired += OnSessionExpired;
            // The camera's color is what shows around a notch and in the rounded corners, so it follows the palette.
            var canvas = GetComponent<Canvas>();
            var camera = canvas != null && canvas.worldCamera != null ? canvas.worldCamera : Camera.main;
            if (camera != null)
            {
                camera.clearFlags = CameraClearFlags.SolidColor;
                camera.backgroundColor = AppServices.Theme.background;
            }

            // The main theme plays while the app is open; the board changes it to suit the game, and leaving the board
            // brings it back.
            MusicPlayer.Instance.SetTheme(MusicTheme.Main);
            _updates = new UpdatePrompt(transform, AppServices.Theme);
            UpdatePrompt.Current = _updates;
            _tour = new TourOverlay(transform, AppServices.Theme, AppServices.Tour, id => (_router.Current as HomeScreen)?.TourTarget(id));
            _router.ScreenChanged += (previous, next) =>
            {
                // The welcome tour is about the main menu: the first time it is reached on this device, and whenever Settings
                // asks for it again. It goes away if the player is taken somewhere else (signed out, say).
                if (next is HomeScreen)
                {
                    var replay = AppServices.Tour.TakeReplayRequest();
                    if (replay || (!_tourOffered && !AppServices.Tour.Seen))
                    {
                        StartCoroutine(StartTour());
                    }

                    _tourOffered = true;
                }
                else if (AppServices.Tour.IsActive)
                {
                    AppServices.Tour.Skip();
                }

                // Once a launch, when the player gets as far as the main menu (not while logging in, or mid-game).
                if (!_updatesChecked && next is HomeScreen)
                {
                    _updatesChecked = true;
                    if (AppServices.Device.CheckForUpdates && AppServices.Updates != null)
                    {
                        StartCoroutine(CheckForUpdatesAfterTour());
                    }
                }

                if (!(next is BoardScreen))
                {
                    MusicPlayer.Instance.SetTheme(MusicTheme.Main);
                }
            };

            // Before the screens, so they know there is a table behind them (and sit above it).
            Backdrop.Create(transform);
            AndroidBack.Register();
            BackgroundSound.Apply();
            BuildServerBanner();
            _router.Show<SplashScreen>();
        }

        // The main menu is laid out a frame after it is shown, and the tour points at where its buttons end up.
        private System.Collections.IEnumerator StartTour()
        {
            if (_tourStarting || AppServices.Tour.IsActive)
            {
                yield break;
            }

            _tourStarting = true;
            yield return null;
            yield return null;
            _tourStarting = false;
            if (_router.Current is HomeScreen)
            {
                AppServices.Tour.Start();
            }
        }

        // The tour comes first: a box offering an update over it would be one thing too many.
        private System.Collections.IEnumerator CheckForUpdatesAfterTour()
        {
            yield return null;
            yield return null;
            yield return null;
            while (_tourStarting || AppServices.Tour.IsActive)
            {
                yield return null;
            }

            _ = _updates.CheckAsync(manual: false);
        }

        // A small tag in the corner while the app is on the dev server, so nobody mistakes it for production.
        private void BuildServerBanner()
        {
            var theme = AppServices.Theme;
            var tag = UiFactory.Create("Server banner", transform);
            _banner = tag.gameObject;
            tag.pivot = new Vector2(1f, 1f);
            tag.sizeDelta = new Vector2(150f, 34f);
            FitServerBanner();
            var back = tag.gameObject.AddComponent<UnityEngine.UI.Image>();
            back.color = new Color(theme.danger.r, theme.danger.g, theme.danger.b, 0.85f);
            back.raycastTarget = false;
            var label = UiFactory.Label(tag, "DEV SERVER", 20, Color.white, TextAnchor.MiddleCenter, FontStyle.Bold);
            label.raycastTarget = false;
            UiFactory.Stretch(label.rectTransform);
            AppServices.ServerChanged += UpdateServerBanner;
            UpdateServerBanner();
        }

        // In the upper right corner of the safe area, a good way in: the corner of the screen itself can be rounded off.
        private void FitServerBanner()
        {
            var tag = (RectTransform)_banner.transform;
            ScreenSafeArea.Anchors(ScreenSafeArea.Current, Screen.width, Screen.height, out _, out var max);
            tag.anchorMin = tag.anchorMax = max;
            // Further in than the safe area asks: phones with rounded corners don't count those as unsafe, and clip a tag
            // that sits right in one.
            tag.anchoredPosition = new Vector2(-96f, -24f);
            _bannerSafeArea = ScreenSafeArea.Current;
            _bannerScreenSize = new Vector2Int(Screen.width, Screen.height);
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
            if (UpdatePrompt.Current == _updates)
            {
                UpdatePrompt.Current = null;
            }

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
            if (_banner != null && (ScreenSafeArea.Current != _bannerSafeArea
                || _bannerScreenSize != new Vector2Int(Screen.width, Screen.height)))
            {
                FitServerBanner();
            }

            if (AppExit.CanToggleFullscreen && KeyInput.FullscreenTogglePressed)
            {
                AppExit.ToggleFullscreen();
            }

            // Escape on desktop; the system Back button on Android (which has no keyboard to report it as Escape).
            _tour.Tick();
            if (KeyInput.EscapePressed || AndroidBack.Consume())
            {
                if (_tour.Dismiss() || _updates.Dismiss())
                {
                    return;
                }

                var current = _router.Current;
                if ((current == null || !current.HandleBack()) && !_router.Back())
                {
                    // Nowhere further back: on a phone that puts the app away, as Back does at the top of any app.
                    AndroidBack.Leave();
                }
            }
        }
    }
}
