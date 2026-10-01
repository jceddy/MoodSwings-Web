using System.Collections.Generic;
using System.Linq;
using UnityEditor;

namespace MoodSwings.Editor
{
    public static class SceneBuildSettings
    {
        /// <summary>
        /// Makes sure a scene is in the build list without disturbing the
        /// others. <paramref name="makeFirst"/> puts it at index 0, which is
        /// the scene a player build starts in.
        /// </summary>
        public static void Ensure(string scenePath, bool makeFirst = false)
        {
            var scenes = EditorBuildSettings.scenes.ToList();
            var existing = scenes.FirstOrDefault(s => s.path == scenePath);
            if (existing == null)
            {
                existing = new EditorBuildSettingsScene(scenePath, true);
                scenes.Add(existing);
            }

            existing.enabled = true;
            if (makeFirst)
            {
                scenes.Remove(existing);
                scenes.Insert(0, existing);
            }

            EditorBuildSettings.scenes = new List<EditorBuildSettingsScene>(scenes).ToArray();
        }
    }
}
