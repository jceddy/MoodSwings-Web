using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using MoodSwings.Core;
using MoodSwings.Core.Storage;
using NUnit.Framework;
using UnityEngine;

namespace MoodSwings.Tests
{
    /// <summary>Art packs: what counts as card art, adding one from a folder or a zip, and what stays out.</summary>
    public class ArtPackTests
    {
        private string _temp;
        private ArtPackStore _store;

        [SetUp]
        public void SetUp()
        {
            _temp = Path.Combine(Path.GetTempPath(), "moodswings-artpack-test-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(_temp);
            _store = new ArtPackStore(Path.Combine(_temp, "packs"));
        }

        [TearDown]
        public void TearDown()
        {
            if (Directory.Exists(_temp))
            {
                Directory.Delete(_temp, recursive: true);
            }
        }

        // A real, tiny PNG (or JPG) of one color.
        internal static byte[] Image(Color color, bool jpg = false, int width = 32, int height = 44)
        {
            var texture = new Texture2D(width, height, TextureFormat.RGBA32, false);
            texture.SetPixels(Enumerable.Repeat(color, width * height).ToArray());
            texture.Apply();
            var bytes = jpg ? ImageConversion.EncodeToJPG(texture) : ImageConversion.EncodeToPNG(texture);
            UnityEngine.Object.DestroyImmediate(texture);
            return bytes;
        }

        private string MakeFolder(string name, params (string file, byte[] bytes)[] files)
        {
            var folder = Path.Combine(_temp, name);
            Directory.CreateDirectory(folder);
            foreach (var (file, bytes) in files)
            {
                File.WriteAllBytes(Path.Combine(folder, file), bytes);
            }

            return folder;
        }

        [TestCase("12-faith.png", ArtKind.Card, 12, "png")]
        [TestCase("1-altruism.PNG", ArtKind.Card, 1, "png")]
        [TestCase("012.jpg", ArtKind.Card, 12, "jpg")]
        [TestCase("24-scorn.jpeg", ArtKind.Card, 24, "jpg")]
        [TestCase("Card 7 - courage.png", ArtKind.Card, 7, "png")]
        [TestCase("back.png", ArtKind.Back, 0, "png")]
        [TestCase("Card-Back.jpg", ArtKind.Back, 0, "jpg")]
        [TestCase("hurt-feelings.png", ArtKind.HurtFeelings, 0, "png")]
        [TestCase("Hurt_Feelings.JPG", ArtKind.HurtFeelings, 0, "jpg")]
        public void ACardArtFileName_IsRecognized(string file, ArtKind kind, int number, string extension)
        {
            Assert.IsTrue(ArtFileName.TryParse(file, out var parsed));
            Assert.AreEqual(kind, parsed.Kind);
            Assert.AreEqual(number, parsed.Number);
            Assert.AreEqual(extension, parsed.Extension);
        }

        [TestCase("faith.png")]
        [TestCase("0-nothing.png")]
        [TestCase("12-faith.webp")]
        [TestCase("12-faith.gif")]
        [TestCase("1920x1080.png")]
        [TestCase("notes.txt")]
        [TestCase("")]
        public void ASomethingElse_IsNotCardArt(string file)
        {
            Assert.IsFalse(ArtFileName.TryParse(file, out _));
        }

        [Test]
        public void AFolder_BecomesAPack_ThatReplacesTheCardsItHas()
        {
            var folder = MakeFolder("Pixel Pack",
                ("1-altruism.png", Image(Color.red)), ("12-faith.jpg", Image(Color.green, jpg: true)), ("back.png", Image(Color.blue)));

            var result = _store.AddFolder(folder);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.AreEqual("Pixel Pack", result.Pack.Name);
            Assert.AreEqual(2, result.Pack.CardCount);
            Assert.IsTrue(result.Pack.HasBack);
            Assert.IsFalse(result.Pack.HasHurtFeelings);
            Assert.AreEqual("2 cards and the card back", result.Pack.Summary);
            Assert.IsNotNull(_store.FileFor(result.Pack.Id, ArtKind.Card, 1));
            Assert.IsNotNull(_store.FileFor(result.Pack.Id, ArtKind.Card, 12));
            Assert.IsNull(_store.FileFor(result.Pack.Id, ArtKind.Card, 2), "the pack leaves card 2 alone");
            Assert.IsNotNull(_store.FileFor(result.Pack.Id, ArtKind.Back));
        }

        [Test]
        public void APack_IsKeptInTheApp_EvenIfTheOriginalGoesAway()
        {
            var folder = MakeFolder("Mine", ("3-charity.png", Image(Color.red)));
            var pack = _store.AddFolder(folder).Pack;

            Directory.Delete(folder, recursive: true);

            Assert.IsTrue(File.Exists(_store.FileFor(pack.Id, ArtKind.Card, 3)));
            CollectionAssert.AreEqual(new[] { "Mine" }, _store.List().Select(p => p.Name).ToArray());
            Assert.AreEqual(1, new ArtPackStore(_store.Root).List().Count, "a fresh look at the same folder finds it");
        }

        [Test]
        public void AZip_BecomesAPack_NamedForTheFile()
        {
            var zipPath = Path.Combine(_temp, "Neon Cards.zip");
            using (var archive = ZipFile.Open(zipPath, ZipArchiveMode.Create))
            {
                Add(archive, "5-complacency.png", Image(Color.red));
                Add(archive, "inner/6-conviction.png", Image(Color.red)); // in a folder inside the zip
                Add(archive, "readme.txt", new byte[] { 65, 66, 67 });
            }

            var result = _store.AddZip(zipPath);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.AreEqual("Neon Cards", result.Pack.Name);
            Assert.AreEqual(2, result.Pack.CardCount);
        }

        private static void Add(ZipArchive archive, string name, byte[] bytes)
        {
            var entry = archive.CreateEntry(name);
            using (var stream = entry.Open())
            {
                stream.Write(bytes, 0, bytes.Length);
            }
        }

        [Test]
        public void AZipEntry_CannotWriteOutsideThePack()
        {
            var zipPath = Path.Combine(_temp, "Sneaky.zip");
            using (var archive = ZipFile.Open(zipPath, ZipArchiveMode.Create))
            {
                Add(archive, "../../9-discipline.png", Image(Color.red));
            }

            var result = _store.AddZip(zipPath);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.IsFalse(File.Exists(Path.Combine(_temp, "9-discipline.png")));
            Assert.IsFalse(File.Exists(Path.Combine(Path.GetDirectoryName(_temp), "9-discipline.png")));
            Assert.IsNotNull(_store.FileFor(result.Pack.Id, ArtKind.Card, 9), "only the file's own name is used, so it lands in the pack");
        }

        [Test]
        public void FilesThatAreNotCardArt_AreSkipped_AndSaid()
        {
            var folder = MakeFolder("Mixed",
                ("2-benevolence.png", Image(Color.red)),
                ("3-charity.webp", new byte[100]),
                ("cover.png", Image(Color.red)),
                ("notes.txt", new byte[10]),
                ("4-chivalry.png", new byte[] { 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13 })); // named right, not an image

            var result = _store.AddFolder(folder);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.AreEqual(1, result.Pack.CardCount);
            Assert.AreEqual(3, result.Skipped.Count);
            Assert.IsTrue(result.Skipped.Any(s => s.StartsWith("3-charity.webp") && s.Contains("WebP")));
            Assert.IsTrue(result.Skipped.Any(s => s.StartsWith("cover.png") && s.Contains("number")));
            Assert.IsTrue(result.Skipped.Any(s => s.StartsWith("4-chivalry.png") && s.Contains("PNG")));
        }

        [Test]
        public void NothingUsable_AddsNoPack_AndSaysWhy()
        {
            var folder = MakeFolder("Empty", ("notes.txt", new byte[10]), ("cover.png", Image(Color.red)));

            var result = _store.AddFolder(folder);

            Assert.IsFalse(result.Ok);
            StringAssert.Contains("12-faith.png", result.Problem);
            Assert.IsEmpty(_store.List());
        }

        [Test]
        public void AMissingFolder_OrABrokenZip_IsRefused()
        {
            Assert.IsFalse(_store.AddFolder(Path.Combine(_temp, "nowhere")).Ok);
            Assert.IsFalse(_store.AddZip(Path.Combine(_temp, "nowhere.zip")).Ok);

            var broken = Path.Combine(_temp, "broken.zip");
            File.WriteAllBytes(broken, new byte[200]);
            Assert.IsFalse(_store.AddZip(broken).Ok);
        }

        [Test]
        public void TwoPacksWithTheSameName_GetTheirOwnIds()
        {
            var first = _store.AddFolder(MakeFolder("Same", ("1-altruism.png", Image(Color.red)))).Pack;
            var second = _store.AddFolder(MakeFolder("Same2", ("1-altruism.png", Image(Color.red))), name: "Same").Pack;

            Assert.AreNotEqual(first.Id, second.Id);
            Assert.AreEqual(2, _store.List().Count);
        }

        [Test]
        public void ARemovedPack_IsGone()
        {
            var pack = _store.AddFolder(MakeFolder("Gone", ("1-altruism.png", Image(Color.red)))).Pack;

            Assert.IsTrue(_store.Delete(pack.Id));

            Assert.IsEmpty(_store.List());
            Assert.IsNull(_store.FileFor(pack.Id, ArtKind.Card, 1));
            Assert.IsFalse(_store.Delete(pack.Id));
        }

        private string MakeSetFolder(string name, params (string path, byte[] bytes)[] files)
        {
            var folder = Path.Combine(_temp, name);
            foreach (var (path, bytes) in files)
            {
                var full = Path.Combine(folder, path.Replace('/', Path.DirectorySeparatorChar));
                Directory.CreateDirectory(Path.GetDirectoryName(full));
                File.WriteAllBytes(full, bytes);
            }

            return folder;
        }

        [Test]
        public void ASetFolder_HoldsItsCards_AndTheBackAndHurtFeelingsSitAtTheTop()
        {
            var folder = MakeSetFolder("WithSets",
                ("MSW/1-altruism.png", Image(Color.red)), ("MSW/12-faith.png", Image(Color.green)),
                ("back.png", Image(Color.blue)), ("hurt-feelings.png", Image(Color.black)));

            var result = _store.AddFolder(folder);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.AreEqual(2, result.Pack.CardCount);
            Assert.AreEqual(2, result.Pack.Sets["MSW"]);
            Assert.IsTrue(result.Pack.HasBack);
            Assert.IsTrue(result.Pack.HasHurtFeelings);
            StringAssert.EndsWith(Path.Combine("MSW", "12.png"), _store.FileFor(result.Pack.Id, ArtKind.Card, 12), "kept in its set's folder");
            StringAssert.EndsWith("back.png", _store.FileFor(result.Pack.Id, ArtKind.Back));
            Assert.IsNotNull(_store.FileFor(result.Pack.Id, ArtKind.HurtFeelings));
        }

        [Test]
        public void ImagesOutsideAnySetFolder_AreMoodSwingsCards()
        {
            var flat = _store.AddFolder(MakeFolder("Flat", ("7-courage.png", Image(Color.red)))).Pack;

            Assert.AreEqual(1, flat.Sets["MSW"]);
            StringAssert.EndsWith(Path.Combine("MSW", "7.png"), _store.FileFor(flat.Id, ArtKind.Card, 7));
        }

        [Test]
        public void SeveralSets_AreCountedApart_AndTheBaseGameIsLookedInFirst()
        {
            var folder = MakeSetFolder("TwoSets",
                ("MSW/5-complacency.png", Image(Color.red)), ("MSW/9-discipline.png", Image(Color.red)), ("ABC/5-other.png", Image(Color.green)),
                ("ABC/200-newcard.png", Image(Color.green)));

            var pack = _store.AddFolder(folder).Pack;

            Assert.AreEqual(4, pack.CardCount);
            Assert.AreEqual("4 cards (ABC 2, MSW 2)", pack.Summary);
            StringAssert.Contains("MSW", _store.FileFor(pack.Id, ArtKind.Card, 5), "card 5 is in both: the base game's");
            StringAssert.Contains("ABC", _store.FileFor(pack.Id, ArtKind.Card, 200), "card 200 only in the other set");
        }

        [Test]
        public void TheBackInsideASetFolder_IsSkipped_AndSaid()
        {
            var folder = MakeSetFolder("BackInSet", ("MSW/3-charity.png", Image(Color.red)), ("MSW/back.png", Image(Color.blue)));

            var result = _store.AddFolder(folder);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.IsFalse(result.Pack.HasBack);
            Assert.IsTrue(result.Skipped.Any(x => x.StartsWith("MSW/back.png") && x.Contains("top of the pack")));
        }

        [Test]
        public void AFlatCardAndTheSameCardInTheSetFolder_AreOneCard()
        {
            var folder = MakeSetFolder("Both", ("4-chivalry.png", Image(Color.red)), ("MSW/4-chivalry.png", Image(Color.green)));

            var result = _store.AddFolder(folder);

            Assert.AreEqual(1, result.Pack.CardCount);
            Assert.AreEqual(1, result.Skipped.Count);
        }

        [Test]
        public void AFolderThatIsNotASetCode_IsNotLookedInto()
        {
            var folder = MakeSetFolder("Other", ("2-benevolence.png", Image(Color.red)), ("images/3-charity.png", Image(Color.red)), ("msw/6-conviction.png", Image(Color.red)));

            var result = _store.AddFolder(folder);

            Assert.AreEqual(1, result.Pack.CardCount, "set codes are capitals, so only MSW-style folders count");
        }

        [Test]
        public void AZip_WithSetFoldersInsideAWrappingFolder_IsRead()
        {
            var zipPath = Path.Combine(_temp, "Wrapped.zip");
            using (var archive = ZipFile.Open(zipPath, ZipArchiveMode.Create))
            {
                Add(archive, "My Pack/MSW/5-complacency.png", Image(Color.red));
                Add(archive, "My Pack/MSW/6-conviction.png", Image(Color.red));
                Add(archive, "My Pack/back.png", Image(Color.blue));
            }

            var result = _store.AddZip(zipPath);

            Assert.IsTrue(result.Ok, result.Problem);
            Assert.AreEqual(2, result.Pack.Sets["MSW"]);
            Assert.IsTrue(result.Pack.HasBack);
        }

        [TestCase("../outside")]
        [TestCase("a/b")]
        [TestCase("..")]
        public void APackId_CannotReachOutsideTheStore(string id)
        {
            Assert.IsNull(_store.FileFor(id, ArtKind.Card, 1));
            Assert.IsFalse(_store.Delete(id));
        }

        [Test]
        public void TheArtPackInUse_IsRememberedOnTheDevice()
        {
            var store = new InMemoryKeyValueStore();
            Assert.IsNull(new DeviceSettings(store).ArtPack, "the default until one is chosen");

            new DeviceSettings(store).ArtPack = "pixel-pack";
            Assert.AreEqual("pixel-pack", new DeviceSettings(store).ArtPack);

            new DeviceSettings(store).ArtPack = null;
            Assert.IsNull(new DeviceSettings(store).ArtPack);
        }

        [Test]
        public void ASummary_ReadsNaturally()
        {
            Assert.AreEqual("1 card", new ArtPackInfo { CardCount = 1 }.Summary);
            Assert.AreEqual("24 cards, the card back and Hurt Feelings", new ArtPackInfo { CardCount = 24, HasBack = true, HasHurtFeelings = true }.Summary);
            Assert.AreEqual("0 cards and Hurt Feelings", new ArtPackInfo { HasHurtFeelings = true }.Summary);
        }
    }
}
