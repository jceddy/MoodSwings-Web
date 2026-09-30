using System;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// Encrypts a small secret with a key the OS protects (DPAPI user key,
    /// Android Keystore), so the ciphertext is useless if copied off the
    /// device/account.
    /// </summary>
    public interface ISecretProtector
    {
        /// <summary>False on platforms with no secure storage implemented yet; callers then don't persist secrets at all.</summary>
        bool IsAvailable { get; }

        byte[] Protect(byte[] data);

        /// <summary>False for anything that can't be decrypted -- corrupt, tampered, or from another user/device.</summary>
        bool TryUnprotect(byte[] protectedData, out byte[] data);
    }

    public sealed class NullSecretProtector : ISecretProtector
    {
        public bool IsAvailable => false;

        public byte[] Protect(byte[] data) => throw new NotSupportedException("No secure storage on this platform.");

        public bool TryUnprotect(byte[] protectedData, out byte[] data)
        {
            data = null;
            return false;
        }
    }
}
