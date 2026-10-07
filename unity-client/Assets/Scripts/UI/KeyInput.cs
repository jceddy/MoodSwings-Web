using UnityEngine.InputSystem;

namespace MoodSwings.UI
{
    /// <summary>
    /// The few keys the screens react to, read through the Input System package
    /// (the old Input Manager is deprecated). Each is true only on the frame the key
    /// went down; all are false when there is no keyboard, as on a phone. The Android
    /// Back button arrives as Escape.
    /// </summary>
    public static class KeyInput
    {
        public static bool EscapePressed => Keyboard.current != null && Keyboard.current.escapeKey.wasPressedThisFrame;

        public static bool EnterPressed =>
            Keyboard.current != null
            && (Keyboard.current.enterKey.wasPressedThisFrame || Keyboard.current.numpadEnterKey.wasPressedThisFrame);

        /// <summary>F11, or Alt+Enter: switch between full screen and a window.</summary>
        public static bool FullscreenTogglePressed =>
            Keyboard.current != null
            && (Keyboard.current.f11Key.wasPressedThisFrame
                || (Keyboard.current.altKey.isPressed
                    && (Keyboard.current.enterKey.wasPressedThisFrame || Keyboard.current.numpadEnterKey.wasPressedThisFrame)));

        public static bool TabPressed => Keyboard.current != null && Keyboard.current.tabKey.wasPressedThisFrame;

        public static bool ShiftHeld => Keyboard.current != null && Keyboard.current.shiftKey.isPressed;
    }
}
