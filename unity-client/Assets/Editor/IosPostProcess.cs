#if UNITY_IOS
using System.IO;
using UnityEditor;
using UnityEditor.Callbacks;
using UnityEditor.iOS.Xcode;

namespace MoodSwings.Editor
{
    /// <summary>Adjusts the Xcode project Unity makes: what its Info.plist says about the app.</summary>
    public static class IosPostProcess
    {
        [PostProcessBuild]
        public static void OnPostProcessBuild(BuildTarget target, string path)
        {
            if (target != BuildTarget.iOS)
            {
                return;
            }

            var plistPath = Path.Combine(path, "Info.plist");
            var plist = new PlistDocument();
            plist.ReadFromFile(plistPath);

            // The app only talks HTTPS to the game's server, with nothing of its own beyond what iOS provides, which is
            // exempt from the export-compliance questions -- so TestFlight and the App Store needn't ask them each time.
            plist.root.SetBoolean("ITSAppUsesNonExemptEncryption", false);

            plist.WriteToFile(plistPath);
        }
    }
}
#endif
