using System;
using System.Runtime.InteropServices;
using System.Text;
using UnityEngine;

namespace MoodSwings.Core.Storage
{
    /// <summary>
    /// Windows DPAPI (CryptProtectData), scoped to the current Windows
    /// user. Called through P/Invoke directly because Unity's .NET Standard
    /// profile doesn't ship System.Security.Cryptography.ProtectedData.
    /// Only instantiate on Windows -- see <see cref="SecretProtectors"/>.
    /// </summary>
    public sealed class DpapiSecretProtector : ISecretProtector
    {
        private const int CryptProtectUiForbidden = 0x1;

        // Extra app-specific secret mixed into the key, so another program
        // running as the same user can't decrypt our blobs by just calling
        // CryptUnprotectData.
        private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("MoodSwings.SecureSession.v1");

        [StructLayout(LayoutKind.Sequential)]
        private struct DataBlob
        {
            public int cbData;
            public IntPtr pbData;
        }

        [DllImport("crypt32.dll", SetLastError = true)]
        private static extern bool CryptProtectData(
            ref DataBlob dataIn, string description, ref DataBlob entropy, IntPtr reserved, IntPtr prompt, int flags, out DataBlob dataOut);

        [DllImport("crypt32.dll", SetLastError = true)]
        private static extern bool CryptUnprotectData(
            ref DataBlob dataIn, IntPtr description, ref DataBlob entropy, IntPtr reserved, IntPtr prompt, int flags, out DataBlob dataOut);

        [DllImport("kernel32.dll")]
        private static extern IntPtr LocalFree(IntPtr handle);

        public bool IsAvailable =>
            Application.platform == RuntimePlatform.WindowsEditor || Application.platform == RuntimePlatform.WindowsPlayer;

        public byte[] Protect(byte[] data)
        {
            if (!Transform(data, protect: true, out var result))
            {
                throw new InvalidOperationException("CryptProtectData failed (Win32 error " + Marshal.GetLastWin32Error() + ").");
            }

            return result;
        }

        public bool TryUnprotect(byte[] protectedData, out byte[] data)
        {
            return Transform(protectedData, protect: false, out data);
        }

        private static bool Transform(byte[] input, bool protect, out byte[] output)
        {
            output = null;
            var inBlob = ToBlob(input);
            var entropyBlob = ToBlob(Entropy);
            var outBlob = new DataBlob();

            try
            {
                var ok = protect
                    ? CryptProtectData(ref inBlob, null, ref entropyBlob, IntPtr.Zero, IntPtr.Zero, CryptProtectUiForbidden, out outBlob)
                    : CryptUnprotectData(ref inBlob, IntPtr.Zero, ref entropyBlob, IntPtr.Zero, IntPtr.Zero, CryptProtectUiForbidden, out outBlob);

                if (!ok)
                {
                    return false;
                }

                output = new byte[outBlob.cbData];
                Marshal.Copy(outBlob.pbData, output, 0, outBlob.cbData);
                return true;
            }
            finally
            {
                Marshal.FreeHGlobal(inBlob.pbData);
                Marshal.FreeHGlobal(entropyBlob.pbData);
                if (outBlob.pbData != IntPtr.Zero)
                {
                    LocalFree(outBlob.pbData);
                }
            }
        }

        private static DataBlob ToBlob(byte[] bytes)
        {
            var blob = new DataBlob { cbData = bytes.Length, pbData = Marshal.AllocHGlobal(Math.Max(bytes.Length, 1)) };
            Marshal.Copy(bytes, 0, blob.pbData, bytes.Length);
            return blob;
        }
    }
}
