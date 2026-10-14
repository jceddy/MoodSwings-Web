using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using Newtonsoft.Json.Linq;

namespace MoodSwings.Core
{
    /// <summary>What a card-art file in a pack is the art for.</summary>
    public enum ArtKind
    {
        /// <summary>A card face, by catalog number.</summary>
        Card,

        /// <summary>The back of a card (a deck).</summary>
        Back,

        /// <summary>The Hurt Feelings card.</summary>
        HurtFeelings,
    }

    /// <summary>A card-art file recognized by its name.</summary>
    public readonly struct ArtFileName
    {
        public ArtFileName(ArtKind kind, int number, string extension)
        {
            Kind = kind;
            Number = number;
            Extension = extension;
        }

        public ArtKind Kind { get; }

        /// <summary>The card number, for <see cref="ArtKind.Card"/>.</summary>
        public int Number { get; }

        /// <summary>"png" or "jpg".</summary>
        public string Extension { get; }

        /// <summary>The name the file is kept under inside the app: "12.png", "back.jpg", "hurt-feelings.png".</summary>
        public string StoredName =>
            (Kind == ArtKind.Card ? Number.ToString() : Kind == ArtKind.Back ? "back" : "hurt-feelings") + "." + Extension;

        // "12-faith.png", "012.jpg", "Card 12.PNG" -> card 12; "back.png", "card-back.jpg"; "hurt-feelings.png".
        private static readonly Regex CardName = new Regex(@"^(?:card[\s_-]*)?0*(?<n>\d{1,4})(?:[\s_-].*)?\.(?<ext>png|jpe?g)$", RegexOptions.IgnoreCase);
        private static readonly Regex BackName = new Regex(@"^(?:card[\s_-]*)?back\.(?<ext>png|jpe?g)$|^cardback\.(?<ext2>png|jpe?g)$", RegexOptions.IgnoreCase);
        private static readonly Regex HurtName = new Regex(@"^hurt[\s_-]*feelings\.(?<ext>png|jpe?g)$", RegexOptions.IgnoreCase);

        /// <summary>Works out what a file name stands for; false for anything that isn't a PNG or JPG named like card art.</summary>
        public static bool TryParse(string fileName, out ArtFileName result)
        {
            result = default;
            if (string.IsNullOrEmpty(fileName))
            {
                return false;
            }

            var match = HurtName.Match(fileName);
            if (match.Success)
            {
                result = new ArtFileName(ArtKind.HurtFeelings, 0, Normalize(match.Groups["ext"].Value));
                return true;
            }

            match = BackName.Match(fileName);
            if (match.Success)
            {
                var ext = match.Groups["ext"].Success ? match.Groups["ext"].Value : match.Groups["ext2"].Value;
                result = new ArtFileName(ArtKind.Back, 0, Normalize(ext));
                return true;
            }

            match = CardName.Match(fileName);
            if (match.Success && int.TryParse(match.Groups["n"].Value, out var number) && number > 0)
            {
                result = new ArtFileName(ArtKind.Card, number, Normalize(match.Groups["ext"].Value));
                return true;
            }

            return false;
        }

        private static string Normalize(string extension) => extension.ToLowerInvariant().StartsWith("jp") ? "jpg" : "png";
    }

    /// <summary>The set codes cards are printed under ("MSW" for the base game).</summary>
    public static class ArtSets
    {
        /// <summary>The only set that exists so far, and what card images outside any set folder belong to.</summary>
        public const string Default = "MSW";

        // Codes are short and in capitals, like the sets table's: MSW. Anything else (a folder called "Neon Cards"
        // or "images") is just a folder, not a set.
        private static readonly Regex Code = new Regex(@"^[A-Z][A-Z0-9]{1,9}$", RegexOptions.Compiled);

        public static bool IsCode(string name) => !string.IsNullOrEmpty(name) && Code.IsMatch(name);
    }

    /// <summary>One art pack that has been added to the app.</summary>
    public sealed class ArtPackInfo
    {
        public string Id { get; set; }

        public string Name { get; set; }

        /// <summary>How many card faces it replaces, in every set.</summary>
        public int CardCount { get; set; }

        /// <summary>The card faces it replaces in each set, by set code.</summary>
        public Dictionary<string, int> Sets { get; } = new Dictionary<string, int>();

        public bool HasBack { get; set; }

        public bool HasHurtFeelings { get; set; }

        /// <summary>"12 cards", "12 cards and the card back", "30 cards (MSW 24, ABC 6) and the card back".</summary>
        public string Summary
        {
            get
            {
                var cards = CardCount + (CardCount == 1 ? " card" : " cards");
                if (Sets.Count > 1)
                {
                    cards += " (" + string.Join(", ", Sets.OrderBy(p => p.Key, StringComparer.Ordinal).Select(p => p.Key + " " + p.Value)) + ")";
                }

                var parts = new List<string> { cards };
                if (HasBack)
                {
                    parts.Add("the card back");
                }

                if (HasHurtFeelings)
                {
                    parts.Add("Hurt Feelings");
                }

                return string.Join(", ", parts.Take(parts.Count - 1)) + (parts.Count > 1 ? " and " : string.Empty) + parts.Last();
            }
        }
    }

    /// <summary>What happened when a pack was added.</summary>
    public sealed class ArtPackImport
    {
        public ArtPackInfo Pack { get; set; }

        /// <summary>Files that were left out, with why (a name that isn't card art, too big, not really an image...).</summary>
        public List<string> Skipped { get; } = new List<string>();

        /// <summary>Why nothing was added, or null.</summary>
        public string Problem { get; set; }

        public bool Ok => Pack != null && Problem == null;
    }

    /// <summary>
    /// The art packs on this device. A pack is a folder or a .zip of PNG or JPG images. Card faces go in a folder named
    /// for their set ("MSW/12-faith.png": the card's number first -- the catalog id, the same number the web client's
    /// art files use, which is unique across sets); images with no set folder are Mood Swings (MSW) cards, so a simple
    /// pack can be flat. The card back and the Hurt Feelings card (<c>back.png</c>, <c>hurt-feelings.png</c>) sit at the
    /// top, outside any set. A pack can replace any of them. Adding one copies its images into the app's own storage
    /// (so it works wherever the pack came from, and stays when the original is moved or deleted).
    /// </summary>
    public sealed class ArtPackStore
    {
        public const int MaxFileBytes = 12 * 1024 * 1024;
        public const int MaxFiles = 600;
        public const long MaxTotalBytes = 400L * 1024 * 1024;

        private const string InfoFile = "pack.json";

        private sealed class Candidate
        {
            public string Name;      // the file's own name
            public string Set;       // null: outside any set folder
            public long Size;
            public Func<byte[]> Read;
        }

        private readonly string _root;

        public ArtPackStore(string rootFolder)
        {
            _root = rootFolder;
        }

        public string Root => _root;

        /// <summary>The packs added so far, by name.</summary>
        public List<ArtPackInfo> List()
        {
            var packs = new List<ArtPackInfo>();
            if (!Directory.Exists(_root))
            {
                return packs;
            }

            foreach (var folder in Directory.GetDirectories(_root))
            {
                var pack = Read(folder);
                if (pack != null)
                {
                    packs.Add(pack);
                }
            }

            return packs.OrderBy(p => p.Name, StringComparer.CurrentCultureIgnoreCase).ToList();
        }

        public ArtPackInfo Find(string id) => string.IsNullOrEmpty(id) ? null : List().FirstOrDefault(p => p.Id == id);

        /// <summary>
        /// The path of the pack's image for a card face (in whichever set has it, the base game first), the back or Hurt
        /// Feelings; null if it doesn't replace that.
        /// </summary>
        public string FileFor(string packId, ArtKind kind, int number = 0)
        {
            if (!IsSafeId(packId))
            {
                return null;
            }

            var folder = Path.Combine(_root, packId);
            if (!Directory.Exists(folder))
            {
                return null;
            }

            if (kind != ArtKind.Card)
            {
                return Existing(folder, kind, 0);
            }

            foreach (var set in SetsIn(folder))
            {
                var path = Existing(Path.Combine(folder, set), ArtKind.Card, number);
                if (path != null)
                {
                    return path;
                }
            }

            return null;
        }

        // The set folders of a pack, the base game's first.
        private static IEnumerable<string> SetsIn(string packFolder) =>
            Directory.GetDirectories(packFolder).Select(Path.GetFileName).Where(ArtSets.IsCode)
                .OrderBy(set => set == ArtSets.Default ? 0 : 1).ThenBy(set => set, StringComparer.Ordinal);

        private static string Existing(string folder, ArtKind kind, int number)
        {
            foreach (var extension in new[] { "png", "jpg" })
            {
                var path = Path.Combine(folder, new ArtFileName(kind, number, extension).StoredName);
                if (File.Exists(path))
                {
                    return path;
                }
            }

            return null;
        }

        private static bool IsSafeId(string id) =>
            !string.IsNullOrEmpty(id) && id.IndexOfAny(new[] { '/', '\\', ':' }) < 0 && !id.Contains("..");

        /// <summary>
        /// Adds a pack from a folder of images: the files in it, and in any set folders (MSW) directly inside it.
        /// </summary>
        public ArtPackImport AddFolder(string folder, string name = null)
        {
            var result = new ArtPackImport();
            if (!Directory.Exists(folder))
            {
                result.Problem = "That folder isn't there.";
                return result;
            }

            var files = new List<Candidate>();
            Gather(folder, null, files);
            foreach (var sub in Directory.GetDirectories(folder))
            {
                var code = Path.GetFileName(sub);
                if (ArtSets.IsCode(code))
                {
                    Gather(sub, code, files);
                }
            }

            return Add(files, name ?? new DirectoryInfo(folder).Name, result);
        }

        private static void Gather(string folder, string set, List<Candidate> files)
        {
            foreach (var file in Directory.GetFiles(folder))
            {
                var path = file;
                files.Add(new Candidate { Name = Path.GetFileName(file), Set = set, Size = new FileInfo(file).Length, Read = () => File.ReadAllBytes(path) });
            }
        }

        /// <summary>Adds a pack from a .zip of images, laid out like a folder (set folders may be inside one wrapping folder).</summary>
        public ArtPackImport AddZip(string zipPath, string name = null)
        {
            var result = new ArtPackImport();
            if (!File.Exists(zipPath))
            {
                result.Problem = "That file isn't there.";
                return result;
            }

            try
            {
                using (var stream = new FileStream(zipPath, FileMode.Open, FileAccess.Read, FileShare.Read))
                using (var archive = new ZipArchive(stream, ZipArchiveMode.Read))
                {
                    var files = new List<Candidate>();
                    foreach (var entry in archive.Entries)
                    {
                        if (string.IsNullOrEmpty(entry.Name))
                        {
                            continue; // a folder
                        }

                        // Only the file's own name is used (never its path), so nothing can be written outside the pack.
                        // The folder it is in only matters if it is a set's.
                        var folders = entry.FullName.Split('/');
                        var set = folders.Length >= 2 && ArtSets.IsCode(folders[folders.Length - 2]) ? folders[folders.Length - 2] : null;
                        var captured = entry;
                        files.Add(new Candidate
                        {
                            Name = entry.Name,
                            Set = set,
                            Size = entry.Length,
                            Read = () =>
                            {
                                using (var input = captured.Open())
                                using (var memory = new MemoryStream())
                                {
                                    input.CopyTo(memory);
                                    return memory.ToArray();
                                }
                            },
                        });
                    }

                    return Add(files, name ?? Path.GetFileNameWithoutExtension(zipPath), result);
                }
            }
            catch (InvalidDataException)
            {
                result.Problem = "That isn't a zip file the app can read.";
                return result;
            }
        }

        /// <summary>Removes a pack and its images.</summary>
        public bool Delete(string id)
        {
            if (!IsSafeId(id))
            {
                return false;
            }

            var folder = Path.Combine(_root, id);
            if (!Directory.Exists(folder))
            {
                return false;
            }

            Directory.Delete(folder, recursive: true);
            return true;
        }

        private ArtPackImport Add(List<Candidate> files, string name, ArtPackImport result)
        {
            var chosen = new Dictionary<string, (ArtFileName parsed, string set, Candidate file)>();
            var total = 0L;
            foreach (var file in files.OrderBy(f => f.Set ?? string.Empty, StringComparer.Ordinal).ThenBy(f => f.Name, StringComparer.OrdinalIgnoreCase))
            {
                var label = file.Set != null ? file.Set + "/" + file.Name : file.Name;
                if (!ArtFileName.TryParse(file.Name, out var parsed))
                {
                    if (file.Name.EndsWith(".webp", StringComparison.OrdinalIgnoreCase))
                    {
                        result.Skipped.Add(label + ": WebP images aren't supported -- save it as PNG or JPG");
                    }
                    else if (LooksLikeImage(file.Name))
                    {
                        result.Skipped.Add(label + ": the card's number isn't at the start of the name");
                    }

                    continue;
                }

                // The card back and Hurt Feelings belong to the pack as a whole, not to a set.
                if (parsed.Kind != ArtKind.Card && file.Set != null)
                {
                    result.Skipped.Add(label + ": the " + (parsed.Kind == ArtKind.Back ? "card back" : "Hurt Feelings card") + " goes at the top of the pack, not in a set folder");
                    continue;
                }

                if (file.Size > MaxFileBytes)
                {
                    result.Skipped.Add(label + ": over " + MaxFileBytes / (1024 * 1024) + " MB");
                    continue;
                }

                if (chosen.Count >= MaxFiles || total + file.Size > MaxTotalBytes)
                {
                    result.Skipped.Add(label + ": the pack is too big");
                    continue;
                }

                // Images outside any set folder are Mood Swings cards. Two files for one card: the first by name wins.
                var set = parsed.Kind == ArtKind.Card ? file.Set ?? ArtSets.Default : null;
                var key = parsed.Kind + ":" + set + ":" + parsed.Number;
                if (chosen.ContainsKey(key))
                {
                    result.Skipped.Add(label + ": another file is already the art for that card");
                    continue;
                }

                total += file.Size;
                chosen[key] = (parsed, set, file);
            }

            if (chosen.Count == 0)
            {
                result.Problem = "No card art found. Name the images like 12-faith.png (the card's number first), as PNG or JPG.";
                return result;
            }

            var id = UniqueId(name);
            var folder = Path.Combine(_root, id);
            Directory.CreateDirectory(folder);
            var info = new ArtPackInfo { Id = id, Name = CleanName(name) };
            try
            {
                foreach (var item in chosen.Values)
                {
                    var bytes = item.file.Read();
                    var label = item.file.Set != null ? item.file.Set + "/" + item.file.Name : item.file.Name;
                    if (!HasImageHeader(bytes, item.parsed.Extension))
                    {
                        result.Skipped.Add(label + ": not really a " + item.parsed.Extension.ToUpperInvariant() + " image");
                        continue;
                    }

                    var target = folder;
                    if (item.set != null)
                    {
                        target = Path.Combine(folder, item.set);
                        Directory.CreateDirectory(target);
                    }

                    File.WriteAllBytes(Path.Combine(target, item.parsed.StoredName), bytes);
                    switch (item.parsed.Kind)
                    {
                        case ArtKind.Card:
                            info.CardCount++;
                            info.Sets[item.set] = (info.Sets.TryGetValue(item.set, out var n) ? n : 0) + 1;
                            break;
                        case ArtKind.Back:
                            info.HasBack = true;
                            break;
                        default:
                            info.HasHurtFeelings = true;
                            break;
                    }
                }

                if (info.CardCount == 0 && !info.HasBack && !info.HasHurtFeelings)
                {
                    Directory.Delete(folder, recursive: true);
                    result.Problem = "None of those files are PNG or JPG images.";
                    return result;
                }

                File.WriteAllText(Path.Combine(folder, InfoFile), new JObject { ["name"] = info.Name }.ToString(), Encoding.UTF8);
            }
            catch (Exception)
            {
                if (Directory.Exists(folder))
                {
                    Directory.Delete(folder, recursive: true);
                }

                throw;
            }

            result.Pack = info;
            return result;
        }

        private ArtPackInfo Read(string folder)
        {
            var infoPath = Path.Combine(folder, InfoFile);
            if (!File.Exists(infoPath))
            {
                return null;
            }

            string name;
            try
            {
                name = (string)JObject.Parse(File.ReadAllText(infoPath, Encoding.UTF8))["name"];
            }
            catch (Exception)
            {
                return null;
            }

            var pack = new ArtPackInfo { Id = Path.GetFileName(folder), Name = string.IsNullOrWhiteSpace(name) ? Path.GetFileName(folder) : name };
            foreach (var file in Directory.GetFiles(folder))
            {
                if (ArtFileName.TryParse(Path.GetFileName(file), out var parsed))
                {
                    if (parsed.Kind == ArtKind.Back)
                    {
                        pack.HasBack = true;
                    }
                    else if (parsed.Kind == ArtKind.HurtFeelings)
                    {
                        pack.HasHurtFeelings = true;
                    }
                }
            }

            foreach (var set in SetsIn(folder))
            {
                var count = Directory.GetFiles(Path.Combine(folder, set)).Count(f => ArtFileName.TryParse(Path.GetFileName(f), out var parsed) && parsed.Kind == ArtKind.Card);
                if (count > 0)
                {
                    pack.Sets[set] = count;
                    pack.CardCount += count;
                }
            }

            return pack;
        }

        private string UniqueId(string name)
        {
            var slug = Regex.Replace(CleanName(name).ToLowerInvariant(), @"[^a-z0-9]+", "-").Trim('-');
            if (slug.Length == 0)
            {
                slug = "pack";
            }

            if (slug.Length > 40)
            {
                slug = slug.Substring(0, 40).Trim('-');
            }

            var id = slug;
            for (var n = 2; Directory.Exists(Path.Combine(_root, id)); n++)
            {
                id = slug + "-" + n;
            }

            return id;
        }

        private static string CleanName(string name)
        {
            var clean = Regex.Replace(name ?? string.Empty, @"[\r\n\t]+", " ").Trim();
            if (clean.Length > 60)
            {
                clean = clean.Substring(0, 60).Trim();
            }

            return clean.Length == 0 ? "Art pack" : clean;
        }

        private static bool LooksLikeImage(string name) =>
            name.EndsWith(".png", StringComparison.OrdinalIgnoreCase) || name.EndsWith(".jpg", StringComparison.OrdinalIgnoreCase) || name.EndsWith(".jpeg", StringComparison.OrdinalIgnoreCase);

        private static bool HasImageHeader(byte[] bytes, string extension)
        {
            if (bytes == null || bytes.Length < 12)
            {
                return false;
            }

            return extension == "png"
                ? bytes[0] == 0x89 && bytes[1] == 0x50 && bytes[2] == 0x4E && bytes[3] == 0x47
                : bytes[0] == 0xFF && bytes[1] == 0xD8 && bytes[2] == 0xFF;
        }
    }
}
