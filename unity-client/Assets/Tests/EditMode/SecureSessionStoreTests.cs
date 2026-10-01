using System;
using System.Text;
using MoodSwings.Core.Storage;
using NUnit.Framework;
using UnityEngine;
using UnityEngine.TestTools;

namespace MoodSwings.Tests
{
    /// <summary>Reversible stand-in for DPAPI/Keystore: marker prefix + XOR, rejects anything without the marker.</summary>
    public sealed class FakeSecretProtector : ISecretProtector
    {
        private static readonly byte[] Marker = { 0x4d, 0x53 };

        public bool IsAvailable { get; set; } = true;

        public int ProtectCalls { get; private set; }

        public byte[] Protect(byte[] data)
        {
            ProtectCalls++;
            var result = new byte[Marker.Length + data.Length];
            Array.Copy(Marker, result, Marker.Length);
            for (var i = 0; i < data.Length; i++)
            {
                result[Marker.Length + i] = (byte)(data[i] ^ 0x5a);
            }

            return result;
        }

        public bool TryUnprotect(byte[] protectedData, out byte[] data)
        {
            data = null;
            if (protectedData.Length < Marker.Length || protectedData[0] != Marker[0] || protectedData[1] != Marker[1])
            {
                return false;
            }

            data = new byte[protectedData.Length - Marker.Length];
            for (var i = 0; i < data.Length; i++)
            {
                data[i] = (byte)(protectedData[Marker.Length + i] ^ 0x5a);
            }

            return true;
        }
    }

    public class SecureSessionStoreTests
    {
        private const string DiskKey = "session.v1";

        private InMemoryKeyValueStore _disk;
        private FakeSecretProtector _protector;

        [SetUp]
        public void SetUp()
        {
            _disk = new InMemoryKeyValueStore();
            _protector = new FakeSecretProtector();
        }

        private SecureSessionStore NewStore() => new SecureSessionStore(_disk, _protector);

        [Test]
        public void SavedSession_SurvivesARestart()
        {
            NewStore().Save("abc123");

            Assert.AreEqual("abc123", NewStore().Load());
        }

        [Test]
        public void TokenIsNeverWrittenInTheClear()
        {
            NewStore().Save("abc123");

            var stored = _disk.GetString(DiskKey);
            Assert.IsNotNull(stored);
            StringAssert.DoesNotContain("abc123", stored);
            StringAssert.DoesNotContain("abc123", Encoding.UTF8.GetString(Convert.FromBase64String(stored)));
        }

        [Test]
        public void PersistOff_KeepsTheSessionInMemoryOnly()
        {
            var store = NewStore();
            store.Persist = false;

            store.Save("abc123");

            Assert.AreEqual("abc123", store.Load());
            Assert.IsNull(_disk.GetString(DiskKey));
            Assert.IsNull(NewStore().Load());
        }

        [Test]
        public void TurningPersistOff_DeletesWhatWasAlreadySaved()
        {
            var store = NewStore();
            store.Save("abc123");

            store.Persist = false;

            Assert.IsNull(_disk.GetString(DiskKey));
            Assert.AreEqual("abc123", store.Load());
        }

        [Test]
        public void TurningPersistBackOn_WritesTheCurrentSession()
        {
            var store = NewStore();
            store.Persist = false;
            store.Save("abc123");

            store.Persist = true;

            Assert.AreEqual("abc123", NewStore().Load());
        }

        [Test]
        public void Clear_RemovesTheSessionEverywhere()
        {
            var store = NewStore();
            store.Save("abc123");

            store.Clear();

            Assert.IsNull(store.Load());
            Assert.IsNull(NewStore().Load());
        }

        [Test]
        public void UnavailableProtector_NeverWrites_ButKeepsTheSessionInMemory()
        {
            _protector.IsAvailable = false;
            var store = NewStore();
            LogAssert.Expect(LogType.Log, "No secure storage on this platform; the session won't be remembered between launches.");

            store.Save("abc123");

            Assert.AreEqual(0, _protector.ProtectCalls);
            Assert.IsNull(_disk.GetString(DiskKey));
            Assert.AreEqual("abc123", store.Load());
        }

        [Test]
        public void TamperedData_IsTreatedAsNoSession_AndDiscarded()
        {
            _disk.SetString(DiskKey, Convert.ToBase64String(new byte[] { 1, 2, 3, 4, 5 }));

            Assert.IsNull(NewStore().Load());
            Assert.IsNull(_disk.GetString(DiskKey));
        }

        [Test]
        public void GarbageThatIsNotBase64_IsTreatedAsNoSession_AndDiscarded()
        {
            _disk.SetString(DiskKey, "!!! not base64 !!!");

            Assert.IsNull(NewStore().Load());
            Assert.IsNull(_disk.GetString(DiskKey));
        }

        [Test]
        public void StoredDataWithNoSecureStorageAvailable_IsNotRestored()
        {
            NewStore().Save("abc123");
            _protector.IsAvailable = false;

            Assert.IsNull(NewStore().Load());
        }
    }
}
