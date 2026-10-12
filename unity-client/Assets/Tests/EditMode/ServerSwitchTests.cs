using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Switching the app between the production and dev servers.</summary>
    public class ServerSwitchTests
    {
        private FakeHttpTransport _transport;
        private InMemoryKeyValueStore _disk;
        private ApiConfig _config;

        [SetUp]
        public void SetUp()
        {
            _transport = new FakeHttpTransport();
            _disk = new InMemoryKeyValueStore();
            _config = new ApiConfig(ApiConfig.ProdSiteRoot);
            var store = new SecureSessionStore(_disk, new NullSecretProtector());
            store.Save("prod-session");
            AppServices.Override(new ApiClient(_config, _transport, store), null, store, _disk, new FakePushProvider());
        }

        [Test]
        public void TheServers_AreTheDevAndProductionSites()
        {
            Assert.AreEqual("https://moodswings.jceddy.com", ApiConfig.ProdSiteRoot);
            Assert.AreEqual("https://moodswings-dev.jceddy.com", ApiConfig.DevSiteRoot);
            Assert.AreEqual("https://moodswings.jceddy.com/app", ApiConfig.Prod.ApiBase);
            Assert.IsFalse(ApiConfig.Prod.IsDevServer);
            Assert.IsTrue(ApiConfig.Dev.IsDevServer);
        }

        [Test]
        public void TheClientFollowsTheConfig_OnEveryRequest()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.Api.PostAsync<ApiEnvelope>("/ping", null).GetAwaiter().GetResult();
            StringAssert.StartsWith("https://moodswings.jceddy.com/app/", _transport.LastRequest.Url);

            _config.UseSiteRoot(ApiConfig.DevSiteRoot + "/");
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.Api.PostAsync<ApiEnvelope>("/ping", null).GetAwaiter().GetResult();

            Assert.AreEqual("https://moodswings-dev.jceddy.com/app/ping", _transport.LastRequest.Url);
        }

        [Test]
        public void SwitchingToDev_SignsOutOfProduction_ForgetsItsData_AndPointsAtDev()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}"); // the logout
            var changes = 0;
            AppServices.ServerChanged += () => changes++;

            AppServices.SetDevServerAsync(true).GetAwaiter().GetResult();

            Assert.IsTrue(_transport.Requests.Any(r => r.Url == "https://moodswings.jceddy.com/app/logout"), "signed out of production first");
            Assert.IsTrue(AppServices.IsDevServer);
            Assert.AreEqual(ApiConfig.DevSiteRoot, _config.SiteRoot);
            Assert.IsNull(AppServices.Auth.CurrentUser);
            Assert.IsFalse(AppServices.Tournaments.Loaded);
            Assert.AreEqual(true, AppServices.Device.DevServerChoice);
            Assert.AreEqual(1, changes);
        }

        [Test]
        public void TheProductionSessionIsNotSentToDev()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.SetDevServerAsync(true).GetAwaiter().GetResult();

            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.Api.PostAsync<ApiEnvelope>("/ping", null).GetAwaiter().GetResult();

            var request = _transport.LastRequest;
            Assert.AreEqual("https://moodswings-dev.jceddy.com/app/ping", request.Url);
            Assert.IsFalse(request.Headers.Values.Any(v => v.Contains("prod-session")), "no cookie from the other server");
        }

        [Test]
        public void SwitchingBack_GoesToProduction_AndSwitchingToTheSameServerDoesNothing()
        {
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.SetDevServerAsync(true).GetAwaiter().GetResult();
            var requests = _transport.Requests.Count;

            AppServices.SetDevServerAsync(true).GetAwaiter().GetResult();
            Assert.AreEqual(requests, _transport.Requests.Count, "already there");

            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            AppServices.SetDevServerAsync(false).GetAwaiter().GetResult();
            Assert.IsFalse(AppServices.IsDevServer);
            Assert.AreEqual(ApiConfig.ProdSiteRoot, _config.SiteRoot);
            Assert.AreEqual(false, AppServices.Device.DevServerChoice);
        }

        [Test]
        public void TheChoice_IsRemembered_AndAbsentUntilMade()
        {
            var settings = new DeviceSettings(new InMemoryKeyValueStore());
            Assert.IsNull(settings.DevServerChoice);

            settings.DevServerChoice = true;
            Assert.AreEqual(true, settings.DevServerChoice);
            settings.DevServerChoice = false;
            Assert.AreEqual(false, settings.DevServerChoice);
            settings.DevServerChoice = null;
            Assert.IsNull(settings.DevServerChoice);
        }

        [Test]
        public void ADevicePushTokenIsTakenBackFromTheOldServer_BeforeTheSwitch()
        {
            var provider = new FakePushProvider();
            var store = new SecureSessionStore(_disk, new NullSecretProtector());
            store.Save("prod-session");
            AppServices.Override(new ApiClient(_config, _transport, store), null, store, _disk, provider);
            _transport.Enqueue(201, "{\"status\":\"ok\"}");
            AppServices.Push.RegisterAsync().GetAwaiter().GetResult();
            _transport.Enqueue(200, "{\"status\":\"ok\"}");
            _transport.Enqueue(200, "{\"status\":\"ok\"}");

            AppServices.SetDevServerAsync(true).GetAwaiter().GetResult();

            var urls = _transport.Requests.Select(r => r.Url).ToList();
            var removed = urls.FindIndex(u => u == "https://moodswings.jceddy.com/app/notifications/device-token/remove");
            var loggedOut = urls.FindIndex(u => u == "https://moodswings.jceddy.com/app/logout");
            Assert.GreaterOrEqual(removed, 0);
            Assert.Less(removed, loggedOut, "while the session still works");
        }
    }
}
