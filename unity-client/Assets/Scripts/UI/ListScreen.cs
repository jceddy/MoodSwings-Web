using System;
using System.Threading.Tasks;
using MoodSwings.Core;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The shape most menu screens share: a header with a Back button, a
    /// centered column holding a status line and a scrolling list, and
    /// optional extras above or below. Subclasses fill the list and say what
    /// to do on show. (FriendsScreen and SettingsScreen predate this and build
    /// the same structure by hand.)
    /// </summary>
    public abstract class ListScreen : UiScreen
    {
        protected const float ColumnWidth = 1000f;

        private Text _status;
        private bool _built;
        private int _pollRun;

        protected abstract string Title { get; }

        /// <summary>Put the status line under the list (next to a bottom action button) instead of above it.</summary>
        protected virtual bool StatusBelowList => false;

        protected RectTransform List { get; private set; }

        public string StatusText => _status != null ? _status.text : null;

        /// <summary>Everything currently in the scrolling list (titles and rows), so tests can see what's on screen.</summary>
        public int RowCount => List != null ? List.childCount : 0;

        /// <summary>Extra controls above the list (action buttons, a description).</summary>
        protected virtual void BuildAbove(RectTransform column, UiTheme theme)
        {
        }

        /// <summary>Extra controls below the list (a primary action button).</summary>
        protected virtual void BuildBelow(RectTransform column, UiTheme theme)
        {
        }

        protected void EnsureBuilt()
        {
            if (_built)
            {
                return;
            }

            _built = true;
            var theme = AppServices.Theme;
            UiFactory.Background(transform, theme.background);
            UiFactory.Header(transform, theme, Title, () => Router.Back());

            var column = UiFactory.Create("Column", transform);
            column.anchorMin = new Vector2(0.5f, 0f);
            column.anchorMax = new Vector2(0.5f, 1f);
            column.pivot = new Vector2(0.5f, 0.5f);
            column.offsetMin = new Vector2(-ColumnWidth / 2f, 40f);
            column.offsetMax = new Vector2(ColumnWidth / 2f, -130f);
            var layout = column.gameObject.AddComponent<VerticalLayoutGroup>();
            layout.spacing = 12f;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = true;
            layout.childForceExpandHeight = false;

            BuildAbove(column, theme);
            if (!StatusBelowList)
            {
                _status = NewStatusLabel(column, theme);
            }

            UiFactory.ScrollList(column, out var list);
            List = list;
            UiFactory.Flexible(list.parent.parent.gameObject, height: 1f);

            if (StatusBelowList)
            {
                _status = NewStatusLabel(column, theme);
            }

            BuildBelow(column, theme);
        }

        private static Text NewStatusLabel(Transform parent, UiTheme theme)
        {
            var label = UiFactory.Label(parent, string.Empty, 26, theme.textMuted);
            UiFactory.Size(label.gameObject, height: 40f);
            return label;
        }

        protected void SetStatus(string message, bool isError = false)
        {
            var theme = AppServices.Theme;
            _status.text = message ?? string.Empty;
            _status.color = isError ? theme.danger : theme.textMuted;
        }

        public override void ShowMessage(string message, bool isError = false)
        {
            if (_status != null)
            {
                SetStatus(message, isError);
            }
        }

        protected void ClearList()
        {
            // Destroy() waits for the end of the frame, so hide the old rows too
            // or the layout would still count them.
            foreach (Transform child in List)
            {
                child.gameObject.SetActive(false);
                Destroy(child.gameObject);
            }
        }

        /// <summary>Runs <paramref name="refresh"/> every <paramref name="seconds"/> until the screen is hidden (or this is called again).</summary>
        protected async void PollWhileShown(float seconds, Func<Task> refresh)
        {
            var run = ++_pollRun;
            try
            {
                while (true)
                {
                    await Awaitable.WaitForSecondsAsync(seconds);
                    if (this == null || run != _pollRun)
                    {
                        return;
                    }

                    await refresh();
                    if (this == null || run != _pollRun)
                    {
                        return;
                    }
                }
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }

        protected void StopPolling()
        {
            _pollRun++;
        }

        /// <summary>Runs an async UI action, logging (not swallowing silently) anything unexpected.</summary>
        protected async void Run(Func<Task> action)
        {
            try
            {
                await action();
            }
            catch (Exception e)
            {
                Debug.LogException(e);
            }
        }
    }
}
