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
        public void TheBoard_PlaysTheThemeOfTheMoodPlayedMostRecently_ByAnyone()
        {
            // Game 406: BotSageQuick played Frustration (red), and then you played Ambition (black).
            var state = BoardFixtures.Load(406);
            Assert.AreEqual(MusicTheme.Black, MusicThemes.For(state, null, MusicTheme.Main), "your own play");

            // Now an opponent's play is the latest one: the music follows it too.
            var newest = state.RecentEvents.Max(e => e.Id);
            state.RecentEvents.Add(new BoardEvent { Id = newest + 2, Description = "BotSageQuick played Frustration from hand" });
            Assert.AreEqual(MusicTheme.Red, MusicThemes.For(state, null, MusicTheme.Main), "an opponent's play counts");
        }

        [Test]
        public void TheLatestPlay_IsNotTheMoodWithTheHighestCardNumber()
        {
            // Game 407 lists Tranquility (green) last, but the latest play was BotSageQuick's Confusion (blue).
            var state = BoardFixtures.Load(407);
            Assert.AreEqual("Tranquility", state.InPlay.Last().Name);

            Assert.AreEqual(MusicTheme.Blue, MusicThemes.For(state, null, MusicTheme.Main));
        }

        [Test]
        public void WhenTheRoundIsScored_TheMusicCarriesOnUntilSomethingIsPlayed()
        {
            var state = BoardFixtures.Load(406);
            var newest = state.RecentEvents.Max(e => e.Id);
            state.RecentEvents.Add(new BoardEvent { Id = newest + 1, Description = "Round scored (bshaftoe: 4, BotSage: 9) -- BotSage won" });
            state.InPlay.Clear(); // the moods are cleared away at the end of a round

            Assert.AreEqual(MusicTheme.Green, MusicThemes.For(state, null, MusicTheme.Green), "no new color to go by: it stays as it was");

            state.InPlay.Add(new BoardCard { CardId = 1, Name = "Frustration", Color = "red", OwnerGamePlayerId = 911 });
            state.RecentEvents.Add(new BoardEvent { Id = newest + 2, Description = "BotSageQuick played Frustration from hand" });
            Assert.AreEqual(MusicTheme.Red, MusicThemes.For(state, null, MusicTheme.Green), "and then follows the first mood of the new round");
        }

        [Test]
        public void AMoodThatHasLeftPlay_KeepsTheMusic_UntilTheCatalogSaysWhatColorItWas()
        {
            var state = BoardFixtures.Load(406);
            var newest = state.RecentEvents.Max(e => e.Id);
            state.RecentEvents.Add(new BoardEvent { Id = newest + 1, Description = "BotSage played Joy from hand (using an extra play from Gluttony)" });

            Assert.IsNull(MusicThemes.Latest(state, null, out var needsCatalog));
            Assert.IsTrue(needsCatalog, "the catalog would settle it");
            Assert.AreEqual(MusicTheme.Green, MusicThemes.For(state, null, MusicTheme.Green), "not in play and no catalog: unchanged");

            var catalog = new[] { new BoardCard { Name = "Joy", Color = "white" } };
            Assert.AreEqual(MusicTheme.White, MusicThemes.For(state, catalog, MusicTheme.Green));
        }

        [Test]
        public void WithNothingPlayedYet_TheMusicIsLeftAlone()
        {
            var state = BoardFixtures.Load(406);
            state.RecentEvents.Clear();
            state.RecentEvents.Add(new BoardEvent { Id = 1, Description = "BotSage was dealt 5 cards" });

            Assert.IsNull(MusicThemes.Latest(state, null, out var needsCatalog));
            Assert.IsFalse(needsCatalog);
            Assert.AreEqual(MusicTheme.Main, MusicThemes.For(state, null, MusicTheme.Main));
            Assert.AreEqual(MusicTheme.Blue, MusicThemes.For(state, null, MusicTheme.Blue));
            Assert.AreEqual(MusicTheme.Red, MusicThemes.For(null, null, MusicTheme.Red));
        }

        [Test]
        public void TheMusicFiles_AreAllThere_AndAreDifferentPieces()
        {
            var clips = new[] { MusicTheme.Main, MusicTheme.White, MusicTheme.Blue, MusicTheme.Black, MusicTheme.Red, MusicTheme.Green }
                .Select(t => UnityEngine.Resources.Load<UnityEngine.AudioClip>("Music/" + MusicThemes.ClipName(t))).ToList();

            CollectionAssert.DoesNotContain(clips, null);
            Assert.AreEqual(clips.Count, clips.Select(c => c.length).Distinct().Count(), "no two themes are the same recording");
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
        public void SoundInTheBackground_IsOnUntilSwitchedOff_AndRemembered()
        {
            var store = new InMemoryKeyValueStore();
            Assert.IsTrue(new DeviceSettings(store).BackgroundSoundOn);

            new DeviceSettings(store).BackgroundSoundOn = false;

            Assert.IsFalse(new DeviceSettings(store).BackgroundSoundOn);
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
