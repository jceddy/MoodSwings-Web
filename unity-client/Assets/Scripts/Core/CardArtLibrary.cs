using System.Collections.Generic;
using System.Linq;
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
        private static readonly Dictionary<string, Sprite> PackCache = new Dictionary<string, Sprite>();

        /// <summary>The art packs the player has added; null until the app has set it up.</summary>
        public static ArtPackStore Packs { get; set; }

        /// <summary>The art pack in use, or null for the art that ships with the app ("Default").</summary>
        public static string ActivePackId { get; set; }

        /// <summary>
        /// The art for a card: the active art pack's image if it replaces this card, otherwise the shipped art. Null if
        /// neither exists (the art hasn't been generated, or the id is unknown).
        /// </summary>
        public static Sprite ForCard(int catalogCardId)
        {
            return FromPack(ArtKind.Card, catalogCardId) ?? Load(catalogCardId.ToString());
        }

        /// <summary>
        /// Frees the images of an art pack that is being removed, so they don't linger in memory; if it is the pack in use
        /// the art goes back to the default.
        /// </summary>
        public static void Forget(string packId)
        {
            var prefix = packId + "|";
            foreach (var key in PackCache.Keys.Where(k => k.StartsWith(prefix)).ToList())
            {
                var sprite = PackCache[key];
                PackCache.Remove(key);
                if (sprite != null)
                {
                    UnityEngine.Object.Destroy(sprite.texture);
                    UnityEngine.Object.Destroy(sprite);
                }
            }

            if (ActivePackId == packId)
            {
                ActivePackId = null;
            }
        }

        // The active pack's image, or null when there is no pack in use or it leaves this one alone. What it did not
        // have, or could not be read, is remembered too, so the files are looked for once.
        private static Sprite FromPack(ArtKind kind, int number)
        {
            if (Packs == null || string.IsNullOrEmpty(ActivePackId))
            {
                return null;
            }

            var key = ActivePackId + "|" + kind + "|" + number;
            if (PackCache.TryGetValue(key, out var cached))
            {
                return cached;
            }

            Sprite sprite = null;
            var path = Packs.FileFor(ActivePackId, kind, number);
            if (path != null)
            {
                try
                {
                    sprite = ArtPackImages.ToSprite(System.IO.File.ReadAllBytes(path));
                }
                catch (System.Exception e)
                {
                    Debug.LogWarning("Couldn't read art pack image " + path + ": " + e.Message);
                }
            }

            PackCache[key] = sprite;
            return sprite;
        }

        /// <param name="skin">A theme skin ("futuristic", "neon", "steampunk"), or null/empty for the base art.</param>
        public static Sprite HurtFeelings(string skin = null)
        {
            if (string.IsNullOrEmpty(skin))
            {
                var packed = FromPack(ArtKind.HurtFeelings, 0);
                if (packed != null)
                {
                    return packed;
                }
            }

            return Load(string.IsNullOrEmpty(skin) ? "hurt-feelings" : "hurt-feelings-" + skin);
        }

        /// <summary>
        /// The back of a Mood Swings card (Assets/Resources/UI/card-back, committed, unlike the card faces): what a deck
        /// looks like. Null only if the file is missing.
        /// </summary>
        public static Sprite CardBack() => FromPack(ArtKind.Back, 0) ?? Load("UI/card-back", folder: string.Empty);

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
