using System;
using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>A push token source the tests control.</summary>
    internal sealed class FakePushProvider : IPushTokenProvider
    {
        public bool Available = true;

        public string Token = "fcm-token-1";

        public string LaunchUrl;

        public int TokenRequests;

        public bool IsAvailable => Available;

        public string Platform => "android";

        public event Action<string> TokenRefreshed;

        public Task<string> GetTokenAsync()
        {
            TokenRequests++;
            return Task.FromResult(Token);
        }

        public string TakeLaunchUrl()
        {
            var url = LaunchUrl;
            LaunchUrl = null;
            return url;
        }

        public void Refresh(string token) => TokenRefreshed?.Invoke(token);
    }

    /// <summary>Registering this device for push, signing out, and where a tapped notification leads.</summary>
    public class PushTests
    {
        private FakeHttpTransport _transport;
        private InMemoryKeyValueStore _store;
        private DeviceSettings _device;
        private FakePushProvider _provider;
        private PushFlow _flow;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _store = new InMemoryKeyValueStore();
            _device = new DeviceSettings(_store);
            _provider = new FakePushProvider();
            _flow = new PushFlow(new ApiClient(new ApiConfig("https://example.test"), _transport), _provider, _device, _store);
        }

        private PushFlow Fresh() => new PushFlow(new ApiClient(new ApiConfig("https://example.test"), _transport), _provider, _device, _store);

        [Test]
        public void SigningIn_RegistersTheDevicesToken_WithItsPlatform()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\"}");

            Assert.IsTrue(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/notifications/device-token", _transport.LastRequest.Url);
            var body = JObject.Parse(_transport.LastRequest.Body);
            Assert.AreEqual("fcm-token-1", (string)body["token"]);
            Assert.AreEqual("android", (string)body["platform"]);
            Assert.IsTrue(_flow.IsRegistered);
        }

        [Test]
        public void TheSameTokenIsNotSentTwice()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _flow.RegisterAsync().GetAwaiter().GetResult();

            Assert.IsTrue(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual(1, _transport.Requests.Count);
        }

        [Test]
        public void WherePushIsUnavailable_NothingIsAskedOrSent()
        {
            _provider.Available = false;

            Assert.IsTrue(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual(0, _transport.Requests.Count);
            Assert.AreEqual(0, _provider.TokenRequests);
            Assert.IsFalse(_flow.IsAvailable);
        }

        [Test]
        public void WithPushSwitchedOffOnThisDevice_NothingIsSent()
        {
            _device.PushOn = false;

            Assert.IsTrue(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual(0, _transport.Requests.Count);
            Assert.IsFalse(_flow.Enabled);
        }

        [Test]
        public void IfNotificationPermissionIsRefused_ItSaysSo_AndSendsNothing()
        {
            _provider.Token = null;

            var result = _flow.RegisterAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("isn't allowed to show notifications", result.Message);
            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void AFailedRegistration_SaysWhy_AndTriesAgainNextTime()
        {
            _transport.Enqueue(400, "{\"status\":\"error\",\"message\":\"A device token is required.\"}");
            Assert.IsFalse(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);
            Assert.IsFalse(_flow.IsRegistered);

            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            Assert.IsTrue(_flow.RegisterAsync().GetAwaiter().GetResult().Ok);
            Assert.IsTrue(_flow.IsRegistered);
        }

        [Test]
        public void SigningOut_TakesTheTokenBack_EvenFromAnEarlierRun()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _flow.RegisterAsync().GetAwaiter().GetResult();

            // A new run of the app: it remembers the token it registered, though this PushFlow never did.
            var later = Fresh();
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            Assert.IsTrue(later.UnregisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual("https://example.test/app/notifications/device-token/remove", _transport.LastRequest.Url);
            Assert.AreEqual("fcm-token-1", (string)JObject.Parse(_transport.LastRequest.Body)["token"]);
            Assert.IsNull(_store.GetString("push.token"), "forgotten once the server has it");
        }

        [Test]
        public void AFailedSignOutRemoval_ReportsIt_ButKeepsTheTokenToRetry()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _flow.RegisterAsync().GetAwaiter().GetResult();
            _transport.EnqueueNetworkError("down");

            var result = _flow.UnregisterAsync().GetAwaiter().GetResult();

            Assert.IsFalse(result.Ok);
            Assert.IsFalse(_flow.IsRegistered);
            Assert.AreEqual("fcm-token-1", _store.GetString("push.token"));
        }

        [Test]
        public void WithNothingRegistered_SigningOutSendsNothing()
        {
            Assert.IsTrue(_flow.UnregisterAsync().GetAwaiter().GetResult().Ok);

            Assert.AreEqual(0, _transport.Requests.Count);
        }

        [Test]
        public void ARefreshedToken_IsRegisteredWhileSignedIn_AndIgnoredWhenNot()
        {
            _provider.Refresh("early-token");
            Assert.AreEqual(0, _transport.Requests.Count, "nobody is registered yet");

            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _flow.RegisterAsync().GetAwaiter().GetResult();
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _provider.Refresh("rotated-token");

            Assert.AreEqual("rotated-token", (string)JObject.Parse(_transport.LastRequest.Body)["token"]);
        }

        [Test]
        public void TheDeviceSwitch_RemovesAndRestoresTheRegistration()
        {
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            _flow.RegisterAsync().GetAwaiter().GetResult();

            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            Assert.IsTrue(_flow.SetEnabledAsync(false).GetAwaiter().GetResult().Ok);
            Assert.IsFalse(_device.PushOn);
            StringAssert.EndsWith("/device-token/remove", _transport.LastRequest.Url);

            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            Assert.IsTrue(_flow.SetEnabledAsync(true).GetAwaiter().GetResult().Ok);
            Assert.IsTrue(_device.PushOn);
            StringAssert.EndsWith("/device-token", _transport.LastRequest.Url);
        }

        [Test]
        public void PushIsOnUntilSwitchedOff()
        {
            Assert.IsTrue(_device.PushOn);
            _device.PushOn = false;
            Assert.IsFalse(new DeviceSettings(_store).PushOn);
        }

        [TestCase("/game/?id=7", PushRoute.Kind.Game, 7)]
        [TestCase("/game?id=12&x=1", PushRoute.Kind.Game, 12)]
        [TestCase("/game/?open_friends=1", PushRoute.Kind.Friends, 0)]
        [TestCase("/achievements/", PushRoute.Kind.Achievements, 0)]
        [TestCase("/achievements", PushRoute.Kind.Achievements, 0)]
        public void ALink_OpensTheScreenItNames(string url, PushRoute.Kind kind, int gameId)
        {
            var route = PushRoute.Parse(url);

            Assert.IsNotNull(route);
            Assert.AreEqual(kind, route.Target);
            Assert.AreEqual(gameId, route.GameId);
        }

        [TestCase(null)]
        [TestCase("")]
        [TestCase("/stats/")]
        [TestCase("/game/")]
        [TestCase("/game/?id=abc")]
        [TestCase("/game/?id=0")]
        [TestCase("https://example.com/")]
        public void AnythingElse_IsNotARoute(string url)
        {
            Assert.IsNull(PushRoute.Parse(url));
        }

        [Test]
        public void TheLaunchRoute_ComesFromTheNotificationThatOpenedTheApp_OnlyOnce()
        {
            _provider.LaunchUrl = "/game/?id=7";

            Assert.AreEqual(7, _flow.TakeLaunchRoute().GameId);
            Assert.IsNull(_flow.TakeLaunchRoute());
        }
    }
}
