using MoodSwings.Core;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Whether the app keeps going (and so keeps playing its music) when its window is not the one in front. A desktop
    /// window is paused by default when it loses focus; with the setting on (the default) it carries on instead. A phone's
    /// app is always put to sleep when left, so the setting is neither offered nor applied there.
    /// </summary>
    public static class BackgroundSound
    {
        /// <summary>Whether the setting means anything on this device.</summary>
        public static bool IsOffered => !Application.isMobilePlatform;

        /// <summary>Makes the window follow the device's setting; called at start-up and whenever it is changed.</summary>
        public static void Apply()
        {
            if (IsOffered && AppServices.Device != null)
            {
                Application.runInBackground = AppServices.Device.BackgroundSoundOn;
            }
        }
    }
}
