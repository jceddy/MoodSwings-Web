using System;
using System.Diagnostics;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;
using UnityEngine;
using UnityEngine.Networking;
using Debug = UnityEngine.Debug;

namespace MoodSwings.Core
{
    /// <summary>
    /// Installing an update from inside the app, which only the Windows build does: it downloads the installer, checks it is
    /// the file that was published, and starts it. macOS and Android hand the download to the browser instead.
    /// </summary>
    public static class UpdateInstaller
    {
        /// <summary>Whether this platform installs an update itself (rather than opening the download in the browser).</summary>
        public static bool InstallsInApp => Application.platform == RuntimePlatform.WindowsPlayer;

        /// <summary>The SHA-256 of a file, as lowercase hex.</summary>
        public static string Sha256Hex(string path)
        {
            using (var stream = File.OpenRead(path))
            using (var sha = SHA256.Create())
            {
                var hash = sha.ComputeHash(stream);
                var text = new StringBuilder(hash.Length * 2);
                foreach (var b in hash)
                {
                    text.Append(b.ToString("x2"));
                }

                return text.ToString();
            }
        }

        /// <summary>True when the file is the published one (or the manifest gave no hash to check against).</summary>
        public static bool Matches(string path, string expectedSha256)
        {
            return string.IsNullOrEmpty(expectedSha256)
                || string.Equals(Sha256Hex(path), expectedSha256.Trim(), StringComparison.OrdinalIgnoreCase);
        }

        /// <summary>Downloads the installer, checks it, runs it, and quits so the installer can replace the app. Throws if any step fails.</summary>
        public static async Task DownloadAndRunAsync(UpdateInfo update, Action<float> progress)
        {
            var folder = Path.Combine(Path.GetTempPath(), "MOOD-update");
            Directory.CreateDirectory(folder);
            var name = Path.GetFileName(new Uri(update.Url).LocalPath);
            var path = Path.Combine(folder, string.IsNullOrEmpty(name) ? "MOOD-setup.exe" : name);
            if (File.Exists(path))
            {
                File.Delete(path);
            }

            using (var request = new UnityWebRequest(update.Url, UnityWebRequest.kHttpVerbGET))
            {
                request.downloadHandler = new DownloadHandlerFile(path) { removeFileOnAbort = true };
                request.timeout = 0;
                var operation = request.SendWebRequest();
                while (!operation.isDone)
                {
                    progress?.Invoke(request.downloadProgress);
                    await Task.Yield();
                }

                if (request.result != UnityWebRequest.Result.Success)
                {
                    TryDelete(path);
                    throw new IOException("The download failed (" + request.error + ").");
                }
            }

            progress?.Invoke(1f);
            if (!Matches(path, update.Sha256))
            {
                TryDelete(path);
                throw new IOException("The download didn't match what was published, so it wasn't run.");
            }

            Process.Start(new ProcessStartInfo(path) { UseShellExecute = true });
        }

        private static void TryDelete(string path)
        {
            try
            {
                File.Delete(path);
            }
            catch (Exception e)
            {
                Debug.Log("Couldn't remove " + path + ": " + e.Message);
            }
        }
    }
}
