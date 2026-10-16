using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.UI;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>The light and dark palettes, and the device setting that picks between them.</summary>
    public class ThemeTests
    {
        private static float Luminance(Color c) => 0.2126f * c.r + 0.7152f * c.g + 0.0722f * c.b;

        [Test]
        public void TheLightPalette_IsTheDefault_WithDarkTextOnALightPage()
        {
            var theme = ScriptableObject.CreateInstance<UiTheme>();
            try
            {
                Assert.IsFalse(theme.IsDark);
                Assert.Greater(Luminance(theme.background), 0.8f);
                Assert.Less(Luminance(theme.textPrimary), 0.2f);
            }
            finally
            {
                Object.DestroyImmediate(theme);
            }
        }

        [Test]
        public void TheDarkPalette_PutsLightTextOnADarkPage_AndSwitchesBack()
        {
            var theme = ScriptableObject.CreateInstance<UiTheme>();
            try
            {
                var lightBackground = theme.background;
                var lightAccent = theme.accent;

                theme.Apply(dark: true);
                Assert.IsTrue(theme.IsDark);
                Assert.Less(Luminance(theme.background), 0.2f);
                Assert.Greater(Luminance(theme.textPrimary), 0.8f);
                Assert.AreNotEqual(lightAccent, theme.accent);

                theme.Apply(dark: false);
                Assert.IsFalse(theme.IsDark);
                Assert.AreEqual(lightBackground, theme.background);
                Assert.AreEqual(lightAccent, theme.accent);
            }
            finally
            {
                Object.DestroyImmediate(theme);
            }
        }

        [Test]
        public void TextOnTheAccent_StandsOutFromIt_InBothPalettes()
        {
            var theme = ScriptableObject.CreateInstance<UiTheme>();
            try
            {
                foreach (var dark in new[] { false, true })
                {
                    theme.Apply(dark);
                    Assert.Greater(Mathf.Abs(Luminance(theme.onAccent) - Luminance(theme.accent)), 0.4f, dark ? "dark" : "light");
                    Assert.Greater(Mathf.Abs(Luminance(theme.textPrimary) - Luminance(theme.panel)), 0.6f, dark ? "dark" : "light");
                }
            }
            finally
            {
                Object.DestroyImmediate(theme);
            }
        }

        [Test]
        public void ChatSeen_IsRememberedPerGame()
        {
            var store = new InMemoryKeyValueStore();
            Assert.AreEqual(0, new DeviceSettings(store).ChatSeen(407));

            new DeviceSettings(store).SetChatSeen(407, 3);

            Assert.AreEqual(3, new DeviceSettings(store).ChatSeen(407));
            Assert.AreEqual(0, new DeviceSettings(store).ChatSeen(408), "another game has its own count");
        }

        [Test]
        public void DarkMode_IsOffUntilSwitchedOn_AndRemembered()
        {
            var store = new InMemoryKeyValueStore();
            Assert.IsFalse(new DeviceSettings(store).DarkMode);

            new DeviceSettings(store).DarkMode = true;

            Assert.IsTrue(new DeviceSettings(store).DarkMode);
        }
    }
}
