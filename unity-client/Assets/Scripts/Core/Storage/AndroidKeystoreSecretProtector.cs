using System;
using UnityEngine;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// Android Keystore-backed AES-GCM, implemented in
    /// Assets/Plugins/Android/SecureStore.java (Java rather than C# JNI
    /// calls, which can't resolve the Keystore API's overloads reliably).
    ///
    /// UNVERIFIED ON A DEVICE: this environment has no Android build module,
    /// so neither the Java nor this wrapper has been built or run. It fails
    /// closed -- any error means "no saved session", never a plaintext
    /// fallback. Verify on a real device when the Android module is installed.
    /// </summary>
    public sealed class AndroidKeystoreSecretProtector : ISecretProtector
    {
        private const string JavaClass = "com.moodswings.SecureStore";

        public bool IsAvailable => Application.platform == RuntimePlatform.Android;

        public byte[] Protect(byte[] data)
        {
            using var store = new AndroidJavaClass(JavaClass);
            return store.CallStatic<byte[]>("encrypt", data);
        }

        public bool TryUnprotect(byte[] protectedData, out byte[] data)
        {
            try
            {
                using var store = new AndroidJavaClass(JavaClass);
                data = store.CallStatic<byte[]>("decrypt", protectedData);
                return data != null;
            }
            catch (Exception e)
            {
                // Bad blob, or the Keystore key is gone (e.g. app data
                // restored onto a new device) -- treat as no session.
                Debug.LogWarning("Keystore decrypt failed: " + e.Message);
                data = null;
                return false;
            }
        }
    }
}
