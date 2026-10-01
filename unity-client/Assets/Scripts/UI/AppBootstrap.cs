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

        private void Start()
        {
            _router = GetComponent<ScreenRouter>();
            AppServices.Api.MaintenanceEntered += OnMaintenance;
            AppServices.Api.SessionExpired += OnSessionExpired;
            _router.Show<SplashScreen>();
        }

        private void OnDestroy()
        {
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

            AppServices.Friends.Clear();
            AppServices.Lobby.Clear();
            _router.ClearHistory();
            _router.Show<LoginScreen>("Your session has expired. Please log in again.", addToHistory: false);
        }

        private void Update()
        {
            // Escape on desktop; the system Back button on Android.
            if (Input.GetKeyDown(KeyCode.Escape))
            {
                _router.Back();
            }
        }
    }
}
