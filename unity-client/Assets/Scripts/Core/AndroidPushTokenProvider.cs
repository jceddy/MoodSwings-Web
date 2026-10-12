using System;
using System.Threading.Tasks;
using UnityEngine;
#if UNITY_ANDROID && !UNITY_EDITOR
using UnityEngine.Android;
#endif

namespace MoodSwings.Core
{
    /// <summary>
    /// Push on Android through Firebase Cloud Messaging, implemented in Assets/Plugins/Android/MoodPush.java. On
    /// Android 13 and later it asks for the notification permission first; a refusal means no token (the server then
    /// has nothing to push to). Not run by the automated tests (it needs a device and the Firebase project); the
    /// flow around it, <see cref="PushFlow"/>, is.
    /// </summary>
    public sealed class AndroidPushTokenProvider : IPushTokenProvider
    {
        public const string PackageName = "com.jceddy.moodswings";

        private const string JavaClass = "com.moodswings.MoodPush";
        private const string NotificationPermission = "android.permission.POST_NOTIFICATIONS";

        private readonly FirebaseConfig _config;

        public AndroidPushTokenProvider(FirebaseConfig config)
        {
            _config = config;
        }

        public bool IsAvailable => Application.platform == RuntimePlatform.Android && _config != null;

        public string Platform => "android";

        // Firebase rotates tokens rarely; a new one is picked up the next time the app signs in or opens.
        public event Action<string> TokenRefreshed
        {
            add { }
            remove { }
        }

        public async Task<string> GetTokenAsync()
        {
#if UNITY_ANDROID && !UNITY_EDITOR
            if (!await EnsurePermissionAsync())
            {
                return null;
            }

            try
            {
                using var activity = CurrentActivity();
                using var push = new AndroidJavaClass(JavaClass);
                push.CallStatic("start", activity, _config.ApplicationId, _config.ApiKey, _config.ProjectId, _config.SenderId);

                // The token arrives on another thread; poll for it (a few seconds at most on a working connection).
                for (var waited = 0; waited < 300; waited++)
                {
                    if (push.CallStatic<bool>("done"))
                    {
                        break;
                    }

                    await Task.Delay(100);
                }

                var error = push.CallStatic<string>("error");
                if (!string.IsNullOrEmpty(error))
                {
                    Debug.LogWarning("Push token request failed: " + error);
                    return null;
                }

                return push.CallStatic<string>("token");
            }
            catch (Exception e)
            {
                Debug.LogWarning("Push token request failed: " + e.Message);
                return null;
            }
#else
            await Task.CompletedTask;
            return null;
#endif
        }

        public string TakeLaunchUrl()
        {
#if UNITY_ANDROID && !UNITY_EDITOR
            try
            {
                using var activity = CurrentActivity();
                using var push = new AndroidJavaClass(JavaClass);
                return push.CallStatic<string>("takeLaunchUrl", activity);
            }
            catch (Exception e)
            {
                Debug.LogWarning("Reading the launch notification failed: " + e.Message);
            }
#endif
            return null;
        }

#if UNITY_ANDROID && !UNITY_EDITOR
        private static AndroidJavaObject CurrentActivity()
        {
            using var player = new AndroidJavaClass("com.unity3d.player.UnityPlayer");
            return player.GetStatic<AndroidJavaObject>("currentActivity");
        }

        private static async Task<bool> EnsurePermissionAsync()
        {
            using var version = new AndroidJavaClass("android.os.Build$VERSION");
            if (version.GetStatic<int>("SDK_INT") < 33 || Permission.HasUserAuthorizedPermission(NotificationPermission))
            {
                return true;
            }

            var answer = new TaskCompletionSource<bool>();
            var callbacks = new PermissionCallbacks();
            callbacks.PermissionGranted += _ => answer.TrySetResult(true);
            callbacks.PermissionDenied += _ => answer.TrySetResult(false);
            callbacks.PermissionDeniedAndDontAskAgain += _ => answer.TrySetResult(false);
            Permission.RequestUserPermission(NotificationPermission, callbacks);

            return await answer.Task;
        }
#endif
    }
}
