using System.Linq;
using System.Text;
using MoodSwings.Core.Storage;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>
    /// The encryption the macOS protector puts around a saved session, with the master key held in memory instead of in
    /// the Keychain (that part is the system's <c>security</c> tool, and only runs on a Mac).
    /// </summary>
    public class KeychainSecretProtectorTests
    {
        private sealed class MemoryKeys : IMasterKeyStore
        {
            private readonly byte[] _key;

            public MemoryKeys(byte seed = 7)
            {
                _key = Enumerable.Range(0, 32).Select(i => (byte)(seed + i * 3)).ToArray();
            }

            public bool IsAvailable { get; set; } = true;

            public byte[] GetOrCreateKey() => IsAvailable ? _key : null;
        }

        private static readonly byte[] Secret = Encoding.UTF8.GetBytes("session-token-value-123");

        [Test]
        public void RoundTrips()
        {
            var protector = new KeychainSecretProtector(new MemoryKeys());

            var blob = protector.Protect(Secret);

            Assert.IsTrue(protector.TryUnprotect(blob, out var result));
            Assert.AreEqual(Secret, result);
        }

        [Test]
        public void TheCiphertext_DoesNotContainThePlaintext_AndDiffersEachTime()
        {
            var protector = new KeychainSecretProtector(new MemoryKeys());

            var first = protector.Protect(Secret);
            var second = protector.Protect(Secret);

            StringAssert.DoesNotContain("session-token-value-123", Encoding.UTF8.GetString(first));
            CollectionAssert.AreNotEqual(first, second, "a fresh IV each time");
        }

        [Test]
        public void ATamperedBlob_FailsToUnprotect()
        {
            var protector = new KeychainSecretProtector(new MemoryKeys());
            var blob = protector.Protect(Secret);

            for (var position = 0; position < blob.Length; position += 7)
            {
                var tampered = (byte[])blob.Clone();
                tampered[position] ^= 0x01;
                Assert.IsFalse(protector.TryUnprotect(tampered, out var data), "byte " + position);
                Assert.IsNull(data);
            }
        }

        [Test]
        public void ABlobFromAnotherKey_FailsToUnprotect()
        {
            var blob = new KeychainSecretProtector(new MemoryKeys(1)).Protect(Secret);

            Assert.IsFalse(new KeychainSecretProtector(new MemoryKeys(2)).TryUnprotect(blob, out _));
        }

        [Test]
        public void TruncatedOrEmptyData_FailsToUnprotect()
        {
            var protector = new KeychainSecretProtector(new MemoryKeys());
            var blob = protector.Protect(Secret);

            Assert.IsFalse(protector.TryUnprotect(null, out _));
            Assert.IsFalse(protector.TryUnprotect(new byte[0], out _));
            Assert.IsFalse(protector.TryUnprotect(blob.Take(20).ToArray(), out _));
            Assert.IsFalse(protector.TryUnprotect(blob.Take(blob.Length - 1).ToArray(), out _));
        }

        [Test]
        public void WithoutTheKey_ItIsUnavailable_AndNothingIsReadOrWritten()
        {
            var keys = new MemoryKeys { IsAvailable = false };
            var protector = new KeychainSecretProtector(keys);

            Assert.IsFalse(protector.IsAvailable);
            Assert.Throws<System.InvalidOperationException>(() => protector.Protect(Secret));
            Assert.IsFalse(protector.TryUnprotect(new KeychainSecretProtector(new MemoryKeys()).Protect(Secret), out _));
        }

        [Test]
        public void TheIosKeychainKeyStore_IsOnlyAvailableOnADevice_SoAnUnsupportedPlaceFailsClosed()
        {
            var keys = new IosKeychainKeyStore();

            Assert.IsFalse(keys.IsAvailable, "not an iPhone or iPad");
            Assert.IsNull(keys.GetOrCreateKey());
            Assert.IsFalse(new KeychainSecretProtector(keys).IsAvailable, "so no session is saved, rather than one in the clear");
            Assert.IsFalse(new KeychainSecretProtector(keys).TryUnprotect(new byte[80], out _));
        }
    }
}
