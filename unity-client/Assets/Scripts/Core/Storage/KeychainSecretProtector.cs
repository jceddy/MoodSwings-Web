using System;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;

namespace MoodSwings.Core.Storage
{
    /// <summary>Holds the one secret key the protector's encryption is built on, somewhere the OS keeps safe.</summary>
    public interface IMasterKeyStore
    {
        bool IsAvailable { get; }

        /// <summary>The key (32 random bytes), made and stored the first time it is asked for. Null if it can't be had.</summary>
        byte[] GetOrCreateKey();
    }

    /// <summary>
    /// macOS: the session is encrypted (AES-256-CBC, then an HMAC-SHA256 over the result) with a random key that lives in
    /// the user's login Keychain, so the saved session is useless when copied off the Mac or out of the account. Unity's
    /// .NET profile has no Keychain API, so the key is read and written through the system's own <c>security</c> tool.
    /// It fails closed: anything wrong means "no saved session", never a plaintext fallback.
    /// </summary>
    public sealed class KeychainSecretProtector : ISecretProtector
    {
        private const byte Version = 1;
        private const int IvSize = 16;
        private const int MacSize = 32;

        private readonly IMasterKeyStore _keys;

        public KeychainSecretProtector(IMasterKeyStore keys)
        {
            _keys = keys;
        }

        public bool IsAvailable => _keys.IsAvailable;

        public byte[] Protect(byte[] data)
        {
            var master = _keys.GetOrCreateKey() ?? throw new InvalidOperationException("The Keychain key isn't available.");
            using var aes = Aes.Create();
            aes.Key = Derive(master, "enc");
            aes.GenerateIV();
            byte[] cipher;
            using (var encryptor = aes.CreateEncryptor())
            {
                cipher = encryptor.TransformFinalBlock(data, 0, data.Length);
            }

            var blob = new byte[1 + IvSize + cipher.Length + MacSize];
            blob[0] = Version;
            Buffer.BlockCopy(aes.IV, 0, blob, 1, IvSize);
            Buffer.BlockCopy(cipher, 0, blob, 1 + IvSize, cipher.Length);
            var mac = Mac(master, blob, blob.Length - MacSize);
            Buffer.BlockCopy(mac, 0, blob, blob.Length - MacSize, MacSize);
            return blob;
        }

        public bool TryUnprotect(byte[] protectedData, out byte[] data)
        {
            data = null;
            try
            {
                if (protectedData == null || protectedData.Length < 1 + IvSize + 16 + MacSize || protectedData[0] != Version)
                {
                    return false;
                }

                var master = _keys.GetOrCreateKey();
                if (master == null)
                {
                    return false;
                }

                var body = protectedData.Length - MacSize;
                if (!FixedTimeEquals(Mac(master, protectedData, body), protectedData, body))
                {
                    return false; // tampered, or made with another key
                }

                using var aes = Aes.Create();
                aes.Key = Derive(master, "enc");
                var iv = new byte[IvSize];
                Buffer.BlockCopy(protectedData, 1, iv, 0, IvSize);
                aes.IV = iv;
                using var decryptor = aes.CreateDecryptor();
                data = decryptor.TransformFinalBlock(protectedData, 1 + IvSize, body - 1 - IvSize);
                return true;
            }
            catch (Exception)
            {
                data = null;
                return false;
            }
        }

        private static byte[] Derive(byte[] master, string purpose)
        {
            using var sha = SHA256.Create();
            var label = Encoding.UTF8.GetBytes("MoodSwings.SecureSession.v1." + purpose);
            var input = new byte[master.Length + label.Length];
            Buffer.BlockCopy(master, 0, input, 0, master.Length);
            Buffer.BlockCopy(label, 0, input, master.Length, label.Length);
            return sha.ComputeHash(input);
        }

        private static byte[] Mac(byte[] master, byte[] blob, int length)
        {
            using var hmac = new HMACSHA256(Derive(master, "mac"));
            return hmac.ComputeHash(blob, 0, length);
        }

        private static bool FixedTimeEquals(byte[] expected, byte[] blob, int offset)
        {
            var difference = 0;
            for (var i = 0; i < MacSize; i++)
            {
                difference |= expected[i] ^ blob[offset + i];
            }

            return difference == 0;
        }
    }

    /// <summary>The master key in the macOS login Keychain, through <c>/usr/bin/security</c>.</summary>
    public sealed class MacKeychainKeyStore : IMasterKeyStore
    {
        private const string Tool = "/usr/bin/security";
        private const string Service = "MoodSwings.SecureSession";
        private const string Account = "master-key";
        private const int TimeoutMilliseconds = 5000;

        private byte[] _key;
        private bool _failed;

        public bool IsAvailable =>
            !_failed && (UnityEngine.Application.platform == UnityEngine.RuntimePlatform.OSXPlayer
                         || UnityEngine.Application.platform == UnityEngine.RuntimePlatform.OSXEditor);

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
                var found = Run(out var stored, "find-generic-password", "-s", Service, "-a", Account, "-w");
                if (found)
                {
                    _key = Convert.FromBase64String(stored.Trim());
                    if (_key.Length == 32)
                    {
                        return _key;
                    }
                }

                // None yet (or an unusable one): make a new one. Replacing a bad key only costs the saved session.
                var fresh = new byte[32];
                using (var random = RandomNumberGenerator.Create())
                {
                    random.GetBytes(fresh);
                }

                if (!Run(out _, "add-generic-password", "-U", "-s", Service, "-a", Account, "-w", Convert.ToBase64String(fresh)))
                {
                    _failed = true;
                    return null;
                }

                _key = fresh;
                return _key;
            }
            catch (Exception e)
            {
                UnityEngine.Debug.LogWarning("Keychain unavailable: " + e.Message);
                _failed = true;
                return null;
            }
        }

        private static bool Run(out string output, params string[] arguments)
        {
            var start = new ProcessStartInfo(Tool)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
            };
            foreach (var argument in arguments)
            {
                start.ArgumentList.Add(argument);
            }

            using var process = Process.Start(start);
            output = process.StandardOutput.ReadToEnd();
            if (!process.WaitForExit(TimeoutMilliseconds))
            {
                process.Kill();
                return false;
            }

            return process.ExitCode == 0;
        }
    }
}
