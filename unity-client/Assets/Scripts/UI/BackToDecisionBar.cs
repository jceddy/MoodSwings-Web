using System;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The way back to a question that has been set aside to look at the table: a "Back to decision" button in the middle of the
    /// board, under Pass, where no mood sits. Shared by the choice forms and the Chaos Draft offer.
    /// </summary>
    public sealed class BackToDecisionBar
    {
        private readonly GameObject _root;

        public BackToDecisionBar(Transform parent, UiTheme theme, Action back)
        {
            var bar = UiFactory.Create("Back to decision bar", parent);
            _root = bar.gameObject;
            bar.anchorMin = bar.anchorMax = bar.pivot = new Vector2(0.5f, 0.5f);
            bar.sizeDelta = new Vector2(440f, UiFactory.ControlHeight);
            bar.anchoredPosition = new Vector2(0f, -128f);
            var button = UiFactory.Button(bar, "Back to decision", theme, () => back());
            button.gameObject.name = "Back to decision";
            UiFactory.Stretch((RectTransform)button.transform);
            _root.SetActive(false);
        }

        public bool IsShown => _root.activeSelf;

        /// <summary>Puts the bar up at the given place in the stack: over the table, under the menu and anything opened over the form.</summary>
        public void Show(int siblingIndex)
        {
            _root.SetActive(true);
            _root.transform.SetSiblingIndex(siblingIndex);
        }

        public void Hide() => _root.SetActive(false);
    }
}
