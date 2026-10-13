using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using MoodSwings.Networking;
using NUnit.Framework;

namespace MoodSwings.Tests
{
    /// <summary>Which music goes with what is happening, and the volume settings.</summary>
    public class MusicTests
    {
        [TestCase("white", MusicTheme.White)]
        [TestCase("Blue", MusicTheme.Blue)]
        [TestCase("BLACK", MusicTheme.Black)]
        [TestCase("red", MusicTheme.Red)]
        [TestCase("green", MusicTheme.Green)]
        [TestCase("colorless", MusicTheme.Main)]
        [TestCase("", MusicTheme.Main)]
        [TestCase(null, MusicTheme.Main)]
        public void AColor_HasItsTheme(string color, MusicTheme expected)
        {
            Assert.AreEqual(expected, MusicThemes.ForColor(color));
        }

        [Test]
        public void TheClips_AreNamedForTheirThemes()
        {
            CollectionAssert.AreEqual(
                new[] { "main", "white", "blue", "black", "red", "green" },
                new[] { MusicTheme.Main, MusicTheme.White, MusicTheme.Blue, MusicTheme.Black, MusicTheme.Red, MusicTheme.Green }.Select(MusicThemes.ClipName));
        }

        [Test]
        public void TheBoard_PlaysTheThemeOfTheMoodPlayedMostRecently()
        {
            var state = BoardFixtures.Load(406);
            Assert.Greater(state.InPlay.Count, 1);

            state.InPlay[state.InPlay.Count - 1].Color = "green";
            Assert.AreEqual(MusicTheme.Green, MusicThemes.For(state));

            // The server lists moods in the order they came into play, so the last one is the latest.
            state.InPlay[state.InPlay.Count - 1].Color = "red";
            Assert.AreEqual(MusicTheme.Red, MusicThemes.For(state));
        }

        [Test]
        public void WithNothingInPlay_ItIsTheMainTheme()
        {
            var state = BoardFixtures.Load(406);
            state.InPlay.Clear();

            Assert.AreEqual(MusicTheme.Main, MusicThemes.For(state));
            Assert.AreEqual(MusicTheme.Main, MusicThemes.For(null));
        }

        [Test]
        public void AFade_MovesTowardsItsTarget_AndStopsThere()
        {
            Assert.AreEqual(0.5f, MusicThemes.Fade(0f, wanted: true, deltaSeconds: 1f, seconds: 2f), 0.0001f);
            Assert.AreEqual(1f, MusicThemes.Fade(0.9f, wanted: true, deltaSeconds: 1f, seconds: 2f), 0.0001f);
            Assert.AreEqual(0.25f, MusicThemes.Fade(0.75f, wanted: false, deltaSeconds: 1f, seconds: 2f), 0.0001f);
            Assert.AreEqual(0f, MusicThemes.Fade(0.1f, wanted: false, deltaSeconds: 1f, seconds: 2f), 0.0001f);
            Assert.AreEqual(1f, MusicThemes.Fade(0f, wanted: true, deltaSeconds: 0.01f, seconds: 0f), "no fade at all");
        }

        [Test]
        public void TheVolumes_StartAudible_AndAreRemembered()
        {
            var store = new InMemoryKeyValueStore();
            var settings = new DeviceSettings(store);
            Assert.IsTrue(settings.MusicOn);
            Assert.AreEqual(0.5f, settings.MusicVolume, 0.0001f);
            Assert.AreEqual(0.8f, settings.EffectsVolume, 0.0001f);

            settings.MusicOn = false;
            settings.MusicVolume = 0.25f;
            settings.EffectsVolume = 1f;

            var fresh = new DeviceSettings(store);
            Assert.IsFalse(fresh.MusicOn);
            Assert.AreEqual(0.25f, fresh.MusicVolume, 0.0001f);
            Assert.AreEqual(1f, fresh.EffectsVolume, 0.0001f);
            Assert.IsTrue(fresh.SoundOn, "the effects switch is its own setting");
        }

        [Test]
        public void AVolume_IsKeptBetweenSilenceAndFull()
        {
            var settings = new DeviceSettings(new InMemoryKeyValueStore());

            settings.MusicVolume = 3f;
            Assert.AreEqual(1f, settings.MusicVolume, 0.0001f);

            settings.MusicVolume = -2f;
            Assert.AreEqual(0f, settings.MusicVolume, 0.0001f);
        }
    }
}
