using System;
using System.Security.Cryptography;
#if UNITY_IOS && !UNITY_EDITOR
using System.Runtime.InteropServices;
#endif
using UnityEngine;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// iOS: the master key behind <see cref="KeychainSecretProtector"/> lives in the app's own Keychain, through the two
    /// functions in Assets/Plugins/iOS/MoodKeychain.mm. The item is generic-password "MoodSwings.SecureSession" /
    /// "master-key", readable once the device has been unlocked after a restart and never moved to another device or
    /// into a backup, so a copy of the app's files is no use without the phone itself.
    /// </summary>
    public sealed class IosKeychainKeyStore : IMasterKeyStore
    {
        private const string Service = "MoodSwings.SecureSession";
        private const string Account = "master-key";
        private const int KeySize = 32;

        private byte[] _key;
        private bool _failed;

        public bool IsAvailable => !_failed && Application.platform == RuntimePlatform.IPhonePlayer;

        public byte[] GetOrCreateKey()
        {
            if (_key != null)
            {
                return _key;
            }

            if (!IsAvailable)
            {
                return null;
            }

            try
            {
                var stored = new byte[KeySize * 2];
                var length = Read(stored);
                if (length == KeySize)
                {
                    _key = new byte[KeySize];
                    Buffer.BlockCopy(stored, 0, _key, 0, KeySize);
                    return _key;
                }

                if (length < 0)
                {
                    _failed = true; // the Keychain would not answer: never replace a key that may be there
                    return null;
                }

                // None yet (or one of the wrong size): make a new one. Replacing a bad key only costs the saved session.
                var fresh = new byte[KeySize];
                using (var random = RandomNumberGenerator.Create())
                {
                    random.GetBytes(fresh);
                }

                if (!Write(fresh))
                {
                    _failed = true;
                    return null;
                }

                _key = fresh;
                return _key;
            }
            catch (Exception e)
            {
                Debug.LogWarning("Keychain unavailable: " + e.Message);
                _failed = true;
                return null;
            }
        }

#if UNITY_IOS && !UNITY_EDITOR
        [DllImport("__Internal")]
        private static extern int MoodKeychainGet(string service, string account, byte[] buffer, int capacity);

        [DllImport("__Internal")]
        private static extern int MoodKeychainSet(string service, string account, byte[] data, int length);

        private static int Read(byte[] buffer) => MoodKeychainGet(Service, Account, buffer, buffer.Length);

        private static bool Write(byte[] key) => MoodKeychainSet(Service, Account, key, key.Length) == 1;
#else
        // Not on a device: nothing to read or write (IsAvailable is false everywhere but an iPhone or iPad).
        private static int Read(byte[] buffer) => -1;

        private static bool Write(byte[] key) => false;
#endif
    }
}
