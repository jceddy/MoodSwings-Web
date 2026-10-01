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
