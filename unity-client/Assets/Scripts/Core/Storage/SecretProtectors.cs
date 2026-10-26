using UnityEngine;

namespace MoodSwings.Core.Storage
{
    public static class SecretProtectors
    {
        /// <summary>
        /// Windows: DPAPI. Android: Keystore. macOS: a key in the login Keychain. iOS: a key in the app's Keychain.
        /// Everything else (Linux) gets a protector that reports unavailable, so sessions simply aren't remembered there.
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
                case RuntimePlatform.OSXEditor:
                case RuntimePlatform.OSXPlayer:
                    return new KeychainSecretProtector(new MacKeychainKeyStore());
                case RuntimePlatform.IPhonePlayer:
                    return new KeychainSecretProtector(new IosKeychainKeyStore());
                default:
                    return new NullSecretProtector();
            }
        }
    }
}
