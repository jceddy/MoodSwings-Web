using System.Text;
using MoodSwings.Core.Storage;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>
    /// Exercises the real Android Keystore via SecureStore.java. Skipped
    /// everywhere but an Android device/emulator, so run it with
    /// -runTests -testPlatform Android (or from the Test Runner with the
    /// Android platform selected).
    /// </summary>
    public class AndroidKeystoreTests
    {
        private AndroidKeystoreSecretProtector _protector;

        [SetUp]
        public void SetUp()
        {
            _protector = new AndroidKeystoreSecretProtector();
            Assume.That(Application.platform, Is.EqualTo(RuntimePlatform.Android), "Only runs on Android.");
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
        public void EncryptingTwiceGivesDifferentBlobs_BecauseTheIvIsRandom()
        {
            var secret = Encoding.UTF8.GetBytes("session-token-value-123");

            Assert.AreNotEqual(_protector.Protect(secret), _protector.Protect(secret));
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
            Assert.IsFalse(_protector.TryUnprotect(new byte[64], out _));
        }

        [Test]
        public void SecureSessionStore_PersistsAcrossInstancesThroughRealPlayerPrefs()
        {
            var prefs = new PlayerPrefsKeyValueStore();
            new SecureSessionStore(prefs, _protector).Save("tok-123");
            try
            {
                Assert.AreEqual("tok-123", new SecureSessionStore(prefs, _protector).Load());
                StringAssert.DoesNotContain("tok-123", prefs.GetString("session.v1"));
            }
            finally
            {
                prefs.Delete("session.v1");
            }
        }
    }
}
