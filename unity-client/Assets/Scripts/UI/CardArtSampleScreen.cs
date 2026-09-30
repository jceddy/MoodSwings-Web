using System.Collections.Generic;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Phase 0 smoke test: lays out the first few converted cards plus the
    /// Hurt Feelings art via <see cref="CardArtLibrary"/>, proving the
    /// WebP-to-PNG pipeline, runtime loading and the screen router work
    /// end to end. Throwaway -- delete once real screens exist.
    /// </summary>
    public sealed class CardArtSampleScreen : UiScreen
    {
        [SerializeField] private RectTransform grid;
        [SerializeField] private Text status;
        [SerializeField] private int cardCount = 10;

        /// <summary>Card images that loaded with art, for tests.</summary>
        public int LoadedCount { get; private set; }

        public override void OnShown(object args)
        {
            foreach (Transform child in grid)
            {
                Destroy(child.gameObject);
            }

            var ids = new List<int>();
            foreach (var sprite in Resources.LoadAll<Sprite>("CardArt"))
            {
                if (int.TryParse(sprite.name, out var id))
                {
                    ids.Add(id);
                }
            }

            ids.Sort();

            LoadedCount = 0;
            for (var i = 0; i < Mathf.Min(cardCount, ids.Count); i++)
            {
                if (AddCard(CardArtLibrary.ForCard(ids[i]), "Card " + ids[i]))
                {
                    LoadedCount++;
                }
            }

            if (AddCard(CardArtLibrary.HurtFeelings(), "Hurt Feelings"))
            {
                LoadedCount++;
            }

            status.text = ids.Count == 0
                ? "No card art found. Run tools/convert_card_art.py, then reimport."
                : $"{LoadedCount} images loaded via CardArtLibrary ({ids.Count} cards available)";
        }

        private bool AddCard(Sprite sprite, string objectName)
        {
            var go = new GameObject(objectName, typeof(RectTransform), typeof(Image));
            go.transform.SetParent(grid, false);

            var image = go.GetComponent<Image>();
            image.sprite = sprite;
            image.preserveAspect = true;
            image.enabled = sprite != null;
            return sprite != null;
        }
    }
}
