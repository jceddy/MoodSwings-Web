using System;
using System.Threading.Tasks;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// One full-screen view (splash, login, lobby, ...). Lives under a
    /// shared Canvas; <see cref="ScreenRouter"/> activates exactly one at a
    /// time. Named UiScreen because UnityEngine.Screen already exists.
    /// </summary>
    public abstract class UiScreen : MonoBehaviour
    {
        [Tooltip("Id ScreenRouter.Show() looks this screen up by. Defaults to the class name.")]
        [SerializeField]
        private string screenId;

        public string ScreenId => string.IsNullOrEmpty(screenId) ? GetType().Name : screenId;

        /// <summary>The router this screen is registered with; set by the router in its Awake.</summary>
        public ScreenRouter Router { get; internal set; }

        /// <summary>
        /// Lets a screen that was just left hand a note back to the one on top
        /// again (e.g. "Game created." after New Game returns to Play). Does
        /// nothing unless a screen overrides it.
        /// </summary>
        public virtual void ShowMessage(string message, bool isError = false)
        {
        }

        private int _pollRun;

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

        /// <summary>
        /// Escape / the Back button, offered to the screen first. Return true if
        /// it was used up (closing a pop-up, say), so the router doesn't also
        /// leave the screen.
        /// </summary>
        public virtual bool HandleBack()
        {
            return false;
        }

        /// <summary>Called after the screen is activated. args is whatever the caller passed to Show().</summary>
        public virtual void OnShown(object args)
        {
        }

        /// <summary>Called just before the screen is deactivated.</summary>
        public virtual void OnHidden()
        {
        }
    }
}
