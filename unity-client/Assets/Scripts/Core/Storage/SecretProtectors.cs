using UnityEngine;

namespace MoodSwings.Core.Storage
{
    public static class SecretProtectors
    {
        /// <summary>
        /// Windows: DPAPI. Android: Keystore. Everything else (macOS, Linux,
        /// iOS -- Keychain is still to do) gets a protector that reports
        /// unavailable, so sessions simply aren't remembered there.
        /// </summary>
        public static ISecretProtector ForCurrentPlatform()
        {
            switch (Application.platform)
            {
                case RuntimePlatform.WindowsEditor:
                case RuntimePlatform.WindowsPlayer:
                    return new DpapiSecretProtector();
                case RuntimePlatform.Android:
                    return new AndroidKeystoreSecretProtector();
                default:
                    return new NullSecretProtector();
            }
        }
    }
}
