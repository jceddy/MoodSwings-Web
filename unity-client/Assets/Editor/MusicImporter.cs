using UnityEditor;
using UnityEngine;

namespace MoodSwings.Editor
{
    /// <summary>
    /// The background music is several minutes of stereo audio per theme, so it is streamed from disk (and stored as
    /// Vorbis) instead of being decoded into memory when it loads. Applied to every clip imported under Resources/Music.
    /// </summary>
    public sealed class MusicImporter : AssetPostprocessor
    {
        private const string Folder = "Assets/Resources/Music/";

        // Bump when the settings below change, so Unity reimports the clips.
        public override uint GetVersion() => 1;

        private void OnPreprocessAudio()
        {
            if (!assetPath.StartsWith(Folder))
            {
                return;
            }

            var importer = (AudioImporter)assetImporter;
            importer.forceToMono = false;
            importer.loadInBackground = true;
            var settings = importer.defaultSampleSettings;
            settings.loadType = AudioClipLoadType.Streaming;
            settings.preloadAudioData = false;
            settings.compressionFormat = AudioCompressionFormat.Vorbis;
            settings.quality = 0.5f;
            settings.sampleRateSetting = AudioSampleRateSetting.PreserveSampleRate;
            importer.defaultSampleSettings = settings;
        }
    }
}
