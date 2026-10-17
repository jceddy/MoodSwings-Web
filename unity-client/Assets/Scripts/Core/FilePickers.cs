using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading.Tasks;
using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>Something the player chose on their device.</summary>
    public sealed class PickedPath
    {
        /// <summary>Where it can be read from now.</summary>
        public string Path { get; set; }

        /// <summary>What to call it (the folder's or the file's own name).</summary>
        public string Name { get; set; }

        /// <summary>
        /// A copy the picker made just for this (on Android, where an app can't read a chosen folder in place): delete it
        /// once it has been used.
        /// </summary>
        public bool IsTemporary { get; set; }

        /// <summary>Removes the temporary copy, if this is one.</summary>
        public void Cleanup()
        {
            try
            {
                if (!IsTemporary || string.IsNullOrEmpty(Path))
                {
                    return;
                }

                if (Directory.Exists(Path))
                {
                    Directory.Delete(Path, recursive: true);
                }
                else if (File.Exists(Path))
                {
                    File.Delete(Path);
                }
            }
            catch (Exception)
            {
                // It is in a cache folder the system empties anyway.
            }
        }
    }

    /// <summary>Asks the player to choose a folder or a zip file on their device. Null means they cancelled.</summary>
    public interface IFilePicker
    {
        bool IsAvailable { get; }

        Task<PickedPath> PickFolderAsync();

        Task<PickedPath> PickZipAsync();
    }

    public sealed class NoFilePicker : IFilePicker
    {
        public bool IsAvailable => false;

        public Task<PickedPath> PickFolderAsync() => Task.FromResult<PickedPath>(null);

        public Task<PickedPath> PickZipAsync() => Task.FromResult<PickedPath>(null);
    }

    public static class FilePickers
    {
        public static IFilePicker ForThisPlatform()
        {
            switch (Application.platform)
            {
                case RuntimePlatform.WindowsPlayer:
                case RuntimePlatform.WindowsEditor:
                    return new WindowsFilePicker();
                case RuntimePlatform.OSXPlayer:
                case RuntimePlatform.OSXEditor:
                    return new MacFilePicker();
                case RuntimePlatform.Android:
                    return new AndroidFilePicker();
                default:
                    return new NoFilePicker();
            }
        }
    }

    /// <summary>Runs a command that shows the system's own dialog and prints the chosen path.</summary>
    internal static class DialogProcess
    {
        public static Task<string> RunAsync(string program, params string[] arguments) => Task.Run(() =>
        {
            var start = new ProcessStartInfo(program)
            {
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                StandardOutputEncoding = Encoding.UTF8,
            };
            foreach (var argument in arguments)
            {
                start.ArgumentList.Add(argument);
            }

            using (var process = Process.Start(start))
            {
                var output = process.StandardOutput.ReadToEnd();
                process.WaitForExit();
                // A cancelled dialog prints nothing (or, for osascript, exits with an error).
                return process.ExitCode == 0 ? output.Trim() : string.Empty;
            }
        });

        public static PickedPath ToPick(string output)
        {
            var path = (output ?? string.Empty).Trim().TrimEnd('/', '\\');
            if (path.Length == 0)
            {
                return null;
            }

            return new PickedPath { Path = path, Name = System.IO.Path.GetFileNameWithoutExtension(path) };
        }
    }

    /// <summary>Windows: the system's folder and file dialogs, through PowerShell (which is on every Windows).</summary>
    public sealed class WindowsFilePicker : IFilePicker
    {
        // A tiny always-on-top window owns the dialog, so it comes up in front of the game rather than behind it.
        private const string Owner = "Add-Type -AssemblyName System.Windows.Forms; [Console]::OutputEncoding=[Text.Encoding]::UTF8; "
                                     + "$o=New-Object System.Windows.Forms.Form; $o.TopMost=$true; ";

        public bool IsAvailable => true;

        public async Task<PickedPath> PickFolderAsync() => DialogProcess.ToPick(await DialogProcess.RunAsync(
            "powershell.exe", "-NoProfile", "-NonInteractive", "-STA", "-Command",
            Owner + "$d=New-Object System.Windows.Forms.FolderBrowserDialog; $d.Description='Choose the art pack folder'; "
                  + "if($d.ShowDialog($o) -eq 'OK'){ $d.SelectedPath }"));

        public async Task<PickedPath> PickZipAsync() => DialogProcess.ToPick(await DialogProcess.RunAsync(
            "powershell.exe", "-NoProfile", "-NonInteractive", "-STA", "-Command",
            Owner + "$d=New-Object System.Windows.Forms.OpenFileDialog; $d.Filter='Zip files (*.zip)|*.zip'; $d.Title='Choose the art pack'; "
                  + "if($d.ShowDialog($o) -eq 'OK'){ $d.FileName }"));
    }

    /// <summary>macOS: the system's choose-folder and choose-file dialogs, through AppleScript.</summary>
    public sealed class MacFilePicker : IFilePicker
    {
        public bool IsAvailable => true;

        public async Task<PickedPath> PickFolderAsync() => DialogProcess.ToPick(await DialogProcess.RunAsync(
            "/usr/bin/osascript", "-e", "POSIX path of (choose folder with prompt \"Choose the art pack folder\")"));

        public async Task<PickedPath> PickZipAsync() => DialogProcess.ToPick(await DialogProcess.RunAsync(
            "/usr/bin/osascript", "-e", "POSIX path of (choose file with prompt \"Choose the art pack\" of type {\"zip\", \"public.zip-archive\"})"));
    }

    /// <summary>
    /// Android: the system's document picker, through Assets/Plugins/Android/ArtPicker.java. An app can't read a chosen
    /// folder in place, so the picker copies the images (or the zip) into the app's cache and gives that copy's path;
    /// the pack store then keeps its own copy.
    /// </summary>
    public sealed class AndroidFilePicker : IFilePicker
    {
        public bool IsAvailable => Application.platform == RuntimePlatform.Android;

        public Task<PickedPath> PickFolderAsync() => Pick(folder: true);

        public Task<PickedPath> PickZipAsync() => Pick(folder: false);

#if UNITY_ANDROID && !UNITY_EDITOR
        private async Task<PickedPath> Pick(bool folder)
        {
            using (var player = new AndroidJavaClass("com.unity3d.player.UnityPlayer"))
            using (var activity = player.GetStatic<AndroidJavaObject>("currentActivity"))
            using (var picker = new AndroidJavaClass("com.moodswings.ArtPicker"))
            {
                picker.CallStatic("pick", activity, folder, Application.temporaryCachePath);
                // The result arrives when the player has chosen (or backed out); the Java side is polled, not called back.
                while (!picker.CallStatic<bool>("done"))
                {
                    await Task.Delay(200);
                }

                var error = picker.CallStatic<string>("error");
                if (!string.IsNullOrEmpty(error))
                {
                    throw new InvalidOperationException(error);
                }

                if (picker.CallStatic<bool>("cancelled"))
                {
                    return null;
                }

                return new PickedPath
                {
                    Path = picker.CallStatic<string>("path"),
                    Name = picker.CallStatic<string>("name"),
                    IsTemporary = true,
                };
            }
        }
#else
        private Task<PickedPath> Pick(bool folder) => Task.FromResult<PickedPath>(null);
#endif
    }
}
