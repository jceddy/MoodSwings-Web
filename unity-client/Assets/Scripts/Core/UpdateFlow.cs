using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;
using Newtonsoft.Json.Linq;
using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>Where the app looks to see whether a newer version exists.</summary>
    public static class UpdateConfig
    {
        /// <summary>
        /// A small JSON file kept at a fixed address (tools/publish_client.py writes and uploads it). It names the newest
        /// version and, for each platform, where to download it. Change this to move where the builds are hosted.
        /// </summary>
        public const string ManifestUrl = "https://github.com/jceddy/MoodSwings-Web/releases/download/client-latest/latest.json";
    }

    /// <summary>A newer version of the app, and where to get it.</summary>
    public sealed class UpdateInfo
    {
        public string Version { get; set; }

        /// <summary>What is new, one line each (may be empty).</summary>
        public List<string> Notes { get; set; } = new List<string>();

        /// <summary>The file to download for this platform.</summary>
        public string Url { get; set; }

        /// <summary>Its SHA-256 (lowercase hex), checked before an installer is run; null if the manifest doesn't give one.</summary>
        public string Sha256 { get; set; }
    }

    /// <summary>The outcome of one look at the manifest.</summary>
    public sealed class UpdateCheck
    {
        /// <summary>The newer version, or null when this one is the newest (or the check failed, or it was skipped).</summary>
        public UpdateInfo Update { get; set; }

        /// <summary>The manifest couldn't be fetched or read.</summary>
        public bool Failed { get; set; }

        /// <summary>For a person to read: "You have the latest version (1.2.0).", "Couldn't check for updates."</summary>
        public string Message { get; set; }
    }

    public static class AppVersions
    {
        /// <summary>Compares "1.2.0"-style versions (missing parts count as 0; anything after a '-' is ignored). Null if either isn't one.</summary>
        public static int? Compare(string a, string b)
        {
            if (!TryParse(a, out var left) || !TryParse(b, out var right))
            {
                return null;
            }

            for (var i = 0; i < 3; i++)
            {
                if (left[i] != right[i])
                {
                    return left[i] < right[i] ? -1 : 1;
                }
            }

            return 0;
        }

        private static bool TryParse(string text, out int[] parts)
        {
            parts = new int[3];
            if (string.IsNullOrWhiteSpace(text))
            {
                return false;
            }

            var core = text.Trim().TrimStart('v', 'V').Split('-', '+')[0];
            var pieces = core.Split('.');
            if (pieces.Length == 0 || pieces.Length > 3)
            {
                return false;
            }

            for (var i = 0; i < pieces.Length; i++)
            {
                if (!int.TryParse(pieces[i], out parts[i]) || parts[i] < 0)
                {
                    return false;
                }
            }

            return true;
        }
    }

    /// <summary>
    /// Finds out whether a newer version of the app has been published. UI-free. Reads the manifest at a fixed address
    /// (see <see cref="UpdateConfig"/>); a check that can't be made is not an error to show, just "no update".
    /// </summary>
    public sealed class UpdateFlow
    {
        private readonly IHttpTransport _transport;
        private readonly string _manifestUrl;
        private readonly string _currentVersion;
        private readonly string _platform;
        private readonly DeviceSettings _device;

        /// <param name="platform">"windows", "macos" or "android"; null for a platform that isn't updated this way.</param>
        public UpdateFlow(IHttpTransport transport, string manifestUrl, string currentVersion, string platform, DeviceSettings device)
        {
            _transport = transport;
            _manifestUrl = manifestUrl;
            _currentVersion = currentVersion;
            _platform = platform;
            _device = device;
        }

        /// <summary>The manifest's key for the platform this is running on, or null when updates aren't offered here.</summary>
        public static string PlatformKey(RuntimePlatform platform)
        {
            switch (platform)
            {
                case RuntimePlatform.WindowsPlayer: return "windows";
                case RuntimePlatform.OSXPlayer: return "macos";
                case RuntimePlatform.Android: return "android";
                default: return null;
            }
        }

        public string CurrentVersion => _currentVersion;

        /// <summary>Whether updates are offered on this platform at all.</summary>
        public bool IsAvailable => _platform != null;

        /// <summary>
        /// Looks at the manifest. A routine check says nothing about a version the player already chose to skip; a manual
        /// one (<paramref name="manual"/>) always reports what there is.
        /// </summary>
        public async Task<UpdateCheck> CheckAsync(bool manual = false, CancellationToken cancellationToken = default)
        {
            if (!IsAvailable)
            {
                return new UpdateCheck { Message = "Updates aren't checked for on this device." };
            }

            HttpResponse response;
            try
            {
                response = await _transport.SendAsync(new HttpRequest { Url = _manifestUrl }, cancellationToken);
            }
            catch (Exception e) when (!(e is OperationCanceledException))
            {
                return Failed(e.Message);
            }

            if (response.NetworkError != null || response.StatusCode < 200 || response.StatusCode >= 300 || string.IsNullOrWhiteSpace(response.Body))
            {
                return Failed(response.NetworkError ?? "HTTP " + response.StatusCode);
            }

            UpdateInfo info;
            try
            {
                info = Parse(response.Body);
            }
            catch (Exception e)
            {
                return Failed(e.Message);
            }

            if (info == null)
            {
                return new UpdateCheck { Message = $"You have the latest version ({_currentVersion})." };
            }

            if (!manual && string.Equals(_device?.SkippedUpdateVersion, info.Version, StringComparison.Ordinal))
            {
                return new UpdateCheck { Message = $"Version {info.Version} is available (you chose to skip it)." };
            }

            return new UpdateCheck { Update = info, Message = $"Version {info.Version} is available." };
        }

        /// <summary>Chooses not to be told about this version again (a newer one will still be offered).</summary>
        public void Skip(string version)
        {
            if (_device != null)
            {
                _device.SkippedUpdateVersion = version;
            }
        }

        // The manifest: {"version","notes":[...],"windows":{"url","sha256"},"macos":{...},"android":{...}}. Null if there is
        // nothing newer for this platform.
        private UpdateInfo Parse(string body)
        {
            var manifest = JObject.Parse(body);
            var version = (string)manifest["version"];
            var comparison = AppVersions.Compare(version, _currentVersion);
            if (comparison == null)
            {
                throw new FormatException("The manifest has no usable version.");
            }

            if (comparison <= 0)
            {
                return null;
            }

            var entry = manifest[_platform] as JObject;
            var url = (string)entry?["url"];
            if (string.IsNullOrEmpty(url))
            {
                return null; // this version isn't published for this platform
            }

            var notes = new List<string>();
            if (manifest["notes"] is JArray array)
            {
                notes.AddRange(array.Select(n => (string)n).Where(n => !string.IsNullOrWhiteSpace(n)));
            }
            else if (manifest["notes"]?.Type == JTokenType.String)
            {
                notes.AddRange(((string)manifest["notes"]).Split('\n').Select(n => n.Trim()).Where(n => n.Length > 0));
            }

            return new UpdateInfo { Version = version, Notes = notes, Url = url, Sha256 = ((string)entry["sha256"])?.ToLowerInvariant() };
        }

        private static UpdateCheck Failed(string reason)
        {
            Debug.Log("Update check failed: " + reason);
            return new UpdateCheck { Failed = true, Message = "Couldn't check for updates." };
        }
    }
}
