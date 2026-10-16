using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// The Android Back button (or gesture). A phone has no keyboard for the Input System to report it as Escape, so it is
    /// caught natively (Assets/Plugins/Android/BackButton.java) and read here once a frame. Off Android nothing is ever
    /// pressed, except by a test.
    /// </summary>
    public static class AndroidBack
    {
        /// <summary>Presses a test has made; read (and counted down) like the real thing.</summary>
        public static int SimulatedPresses;

        private const string JavaClass = "com.moodswings.BackButton";

        /// <summary>Starts catching Back (Android only; once is enough).</summary>
        public static void Register()
        {
#if UNITY_ANDROID && !UNITY_EDITOR
            try
            {
                using (var player = new AndroidJavaClass("com.unity3d.player.UnityPlayer"))
                using (var activity = player.GetStatic<AndroidJavaObject>("currentActivity"))
                using (var back = new AndroidJavaClass(JavaClass))
                {
                    back.CallStatic("register", activity);
                }
            }
            catch (System.Exception e)
            {
                Debug.LogWarning("Back button unavailable: " + e.Message);
            }
#endif
        }

        /// <summary>True once for each press not yet handled.</summary>
        public static bool Consume()
        {
            if (SimulatedPresses > 0)
            {
                SimulatedPresses--;
                return true;
            }

#if UNITY_ANDROID && !UNITY_EDITOR
            try
            {
                using (var back = new AndroidJavaClass(JavaClass))
                {
                    return back.CallStatic<bool>("consume");
                }
            }
            catch (System.Exception)
            {
                return false;
            }
#else
            return false;
#endif
        }

        /// <summary>Back at the very top of the app: puts it in the background, as phone apps do (not closing it).</summary>
        public static void Leave()
        {
#if UNITY_ANDROID && !UNITY_EDITOR
            try
            {
                using (var player = new AndroidJavaClass("com.unity3d.player.UnityPlayer"))
                using (var activity = player.GetStatic<AndroidJavaObject>("currentActivity"))
                using (var back = new AndroidJavaClass(JavaClass))
                {
                    back.CallStatic("leave", activity);
                }
            }
            catch (System.Exception e)
            {
                Debug.LogWarning("Couldn't leave the app: " + e.Message);
            }
#endif
        }
    }
}
