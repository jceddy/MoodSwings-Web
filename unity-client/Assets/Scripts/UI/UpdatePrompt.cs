using System;
using System.Threading.Tasks;
using MoodSwings.Core;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Looks for a newer version of the app and asks the player about it: once when the app starts (if they haven't
    /// switched that off), and whenever Settings asks. Update installs it on Windows and opens the download elsewhere;
    /// Later asks again next time; Skip this version stays quiet until a newer one comes out.
    /// </summary>
    public sealed class UpdatePrompt
    {
        /// <summary>The prompt of the running app (set by <see cref="AppBootstrap"/>), for Settings to use.</summary>
        public static UpdatePrompt Current { get; set; }

        /// <summary>Opens a download in the browser; tests swap it.</summary>
        public static Action<string> OpenUrl = url => Application.OpenURL(url);

        /// <summary>Whether Update installs from inside the app; tests swap it.</summary>
        public static Func<bool> InstallsInApp = () => UpdateInstaller.InstallsInApp;

        /// <summary>Downloads and starts the installer; tests swap it.</summary>
        public static Func<UpdateInfo, Action<float>, Task> Install = UpdateInstaller.DownloadAndRunAsync;

        private readonly UpdateOverlay _overlay;

        public UpdatePrompt(Transform parent, UiTheme theme)
        {
            _overlay = new UpdateOverlay(parent, theme);
        }

        public UpdateOverlay Overlay => _overlay;

        /// <summary>
        /// Checks, and offers the update if there is one. Returns a line for Settings to show ("You have the latest version").
        /// A routine check (not <paramref name="manual"/>) keeps quiet about a version the player chose to skip.
        /// </summary>
        public async Task<string> CheckAsync(bool manual)
        {
            var updates = AppServices.Updates;
            if (updates == null || !updates.IsAvailable)
            {
                return "Updates aren't checked for on this device.";
            }

            if (_overlay.IsOpen)
            {
                return string.Empty;
            }

            var result = await updates.CheckAsync(manual);
            if (result.Update != null)
            {
                await OfferAsync(result.Update);
            }

            return result.Message;
        }

        public async Task OfferAsync(UpdateInfo update)
        {
            var updates = AppServices.Updates;
            var inApp = InstallsInApp();
            string problem = null;
            while (true)
            {
                var choice = await _overlay.AskAsync(update, updates.CurrentVersion, inApp ? "Update now" : "Download", problem);
                if (choice == UpdateChoice.Skip)
                {
                    updates.Skip(update.Version);
                }

                if (choice != UpdateChoice.Update)
                {
                    _overlay.Close();
                    return;
                }

                if (!inApp)
                {
                    OpenUrl(update.Url);
                    _overlay.Close();
                    return;
                }

                try
                {
                    _overlay.ShowBusy("Downloading... 0%");
                    await Install(update, fraction => _overlay.ShowBusy($"Downloading... {Mathf.RoundToInt(fraction * 100f)}%"));
                    _overlay.ShowBusy("Starting the installer...");
                    AppExit.Quit();
                    return;
                }
                catch (Exception e)
                {
                    Debug.Log("Update failed: " + e.Message);
                    problem = e.Message + " Try again, or get it from the download page.";
                }
            }
        }

        /// <summary>Back / Escape closes the box (as Later). True if it was open.</summary>
        public bool Dismiss() => _overlay.Dismiss();
    }
}
