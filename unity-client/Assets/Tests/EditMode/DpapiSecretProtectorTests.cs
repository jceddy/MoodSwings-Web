using System.Text;
using MoodSwings.Core.Storage;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Runs against the real Windows DPAPI; skipped elsewhere.</summary>
    public class DpapiSecretProtectorTests
    {
        private DpapiSecretProtector _protector;

        [SetUp]
        public void SetUp()
        {
            _protector = new DpapiSecretProtector();
            Assume.That(_protector.IsAvailable, "DPAPI is only available on Windows.");
        }

        [Test]
        public void RoundTrips()
        {
            var secret = Encoding.UTF8.GetBytes("session-token-value-123");

            var blob = _protector.Protect(secret);

            Assert.IsTrue(_protector.TryUnprotect(blob, out var result));
            Assert.AreEqual(secret, result);
        }

        [Test]
        public void CiphertextDoesNotContainThePlaintext()
        {
            var blob = _protector.Protect(Encoding.UTF8.GetBytes("session-token-value-123"));

            StringAssert.DoesNotContain("session-token-value-123", Encoding.UTF8.GetString(blob));
        }

        [Test]
        public void TamperedBlob_FailsToUnprotect()
        {
            var blob = _protector.Protect(Encoding.UTF8.GetBytes("session-token-value-123"));
            blob[blob.Length - 1] ^= 0xff;

            Assert.IsFalse(_protector.TryUnprotect(blob, out var result));
            Assert.IsNull(result);
        }

        [Test]
        public void Garbage_FailsToUnprotect()
        {
            Assert.IsFalse(_protector.TryUnprotect(new byte[] { 1, 2, 3 }, out _));
        }
    }
}
