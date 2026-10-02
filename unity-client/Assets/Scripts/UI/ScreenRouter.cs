using System;
using System.Collections.Generic;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// Shows one <see cref="UiScreen"/> at a time and keeps a back stack.
    /// Put it on the root Canvas object and list the screens in the
    /// inspector; every screen starts hidden.
    /// </summary>
    public sealed class ScreenRouter : MonoBehaviour
    {
        [SerializeField]
        private List<UiScreen> screens = new List<UiScreen>();

        [Tooltip("Screen id to show on start. Leave empty to start with nothing shown.")]
        [SerializeField]
        private string initialScreenId;

        private readonly Dictionary<string, UiScreen> _byId = new Dictionary<string, UiScreen>();
        private readonly Stack<(UiScreen screen, object args)> _history = new Stack<(UiScreen, object)>();

        private Rect _appliedSafeArea;
        private Vector2Int _appliedScreenSize;

        public UiScreen Current { get; private set; }

        /// <summary>Raised after a screen change: (previous, new).</summary>
        public event Action<UiScreen, UiScreen> ScreenChanged;

        private void Awake()
        {
            foreach (var screen in screens)
            {
                if (screen == null)
                {
                    continue;
                }

                _byId[screen.ScreenId] = screen;
                screen.Router = this;
                screen.gameObject.SetActive(false);
            }
        }

        private void Start()
        {
            if (!string.IsNullOrEmpty(initialScreenId))
            {
                Show(initialScreenId);
            }
        }

        public void Show(string screenId, object args = null, bool addToHistory = true)
        {
            if (!_byId.TryGetValue(screenId, out var next))
            {
                throw new ArgumentException($"No screen registered with id '{screenId}'.", nameof(screenId));
            }

            var previous = Current;
            if (previous != null)
            {
                if (addToHistory)
                {
                    _history.Push((previous, null));
                }

                previous.OnHidden();
                previous.gameObject.SetActive(false);
            }

            Current = next;
            FitToSafeArea(next);
            next.gameObject.SetActive(true);
            next.OnShown(args);
            ScreenChanged?.Invoke(previous, next);
        }

        private void Update()
        {
            // A phone turned around, or a window resized, moves the safe area.
            var size = new Vector2Int(Screen.width, Screen.height);
            if (Current != null && (ScreenSafeArea.Current != _appliedSafeArea || size != _appliedScreenSize))
            {
                FitToSafeArea(Current);
            }
        }

        private void FitToSafeArea(UiScreen screen)
        {
            ScreenSafeArea.Apply((RectTransform)screen.transform);
            _appliedSafeArea = ScreenSafeArea.Current;
            _appliedScreenSize = new Vector2Int(Screen.width, Screen.height);
        }

        public void Show<T>(object args = null, bool addToHistory = true) where T : UiScreen
        {
            Show(typeof(T).Name, args, addToHistory);
        }

        /// <summary>Returns to the previous screen; false when there's nowhere to go back to.</summary>
        public bool Back()
        {
            if (_history.Count == 0)
            {
                return false;
            }

            var (screen, args) = _history.Pop();
            Show(screen.ScreenId, args, addToHistory: false);
            return true;
        }

        /// <summary>Forgets the back stack -- e.g. after login, so Back doesn't return to the login screen.</summary>
        public void ClearHistory()
        {
            _history.Clear();
        }
    }
}
