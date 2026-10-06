using System.Collections.Generic;
using UnityEngine;

namespace MoodSwings.Core
{
    /// <summary>
    /// Card art lookup by catalog card id (the `catalog_card_id` the game
    /// state carries -- same id web-static's card image URLs are built
    /// from). Unity can't import the site's .webp files, so
    /// tools/convert_card_art.py converts them to
    /// Assets/Resources/CardArt/&lt;id&gt;.png; that folder is git-ignored
    /// (it's regenerated from web-static/img/, not a second copy of the
    /// art in this repo), so run the script after cloning.
    /// </summary>
    public static class CardArtLibrary
    {
        private const string Folder = "CardArt/";

        private static readonly Dictionary<string, Sprite> Cache = new Dictionary<string, Sprite>();

        /// <summary>Null if the art hasn't been generated or the id is unknown.</summary>
        public static Sprite ForCard(int catalogCardId)
        {
            return Load(catalogCardId.ToString());
        }

        /// <param name="skin">A theme skin ("futuristic", "neon", "steampunk"), or null/empty for the base art.</param>
        public static Sprite HurtFeelings(string skin = null)
        {
            return Load(string.IsNullOrEmpty(skin) ? "hurt-feelings" : "hurt-feelings-" + skin);
        }

        /// <summary>
        /// The back of a Mood Swings card (Assets/Resources/UI/card-back, committed, unlike the card faces): what a deck
        /// looks like. Null only if the file is missing.
        /// </summary>
        public static Sprite CardBack() => Load("UI/card-back", folder: string.Empty);

        private static Sprite Load(string name, string folder = Folder)
        {
            var path = folder + name;
            if (Cache.TryGetValue(path, out var cached))
            {
                return cached;
            }

            var sprite = Resources.Load<Sprite>(path);
            Cache[path] = sprite;
            return sprite;
        }
    }
}
