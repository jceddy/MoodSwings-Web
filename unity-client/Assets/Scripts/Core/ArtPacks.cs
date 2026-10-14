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

    /// <summary>One art pack that has been added to the app.</summary>
    public sealed class ArtPackInfo
    {
        public string Id { get; set; }

        public string Name { get; set; }

        /// <summary>How many card faces it replaces.</summary>
        public int CardCount { get; set; }

        public bool HasBack { get; set; }

        public bool HasHurtFeelings { get; set; }

        /// <summary>"12 cards", "12 cards and the card back".</summary>
        public string Summary
        {
            get
            {
                var parts = new List<string> { CardCount + (CardCount == 1 ? " card" : " cards") };
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
    /// The art packs on this device. A pack is a folder or a .zip of PNG or JPG images named like the art-overrides
    /// folder's ("12-faith.png": the card's number first); it can replace any of the cards, and the card back and the
    /// Hurt Feelings card (<c>back.png</c>, <c>hurt-feelings.png</c>). Adding one copies its images into the app's own
    /// storage (so it works wherever the pack came from, and stays when the original is moved or deleted).
    /// </summary>
    public sealed class ArtPackStore
    {
        public const int MaxFileBytes = 12 * 1024 * 1024;
        public const int MaxFiles = 600;
        public const long MaxTotalBytes = 400L * 1024 * 1024;

        private const string InfoFile = "pack.json";

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

        /// <summary>The path of the pack's image for a card face, the back or Hurt Feelings; null if it doesn't replace that.</summary>
        public string FileFor(string packId, ArtKind kind, int number = 0)
        {
            if (string.IsNullOrEmpty(packId) || packId.IndexOfAny(new[] { '/', '\\', ':' }) >= 0 || packId.Contains(".."))
            {
                return null;
            }

            var folder = Path.Combine(_root, packId);
            if (!Directory.Exists(folder))
            {
                return null;
            }

            foreach (var extension in new[] { "png", "jpg" })
            {
                var name = new ArtFileName(kind, number, extension).StoredName;
                var path = Path.Combine(folder, name);
                if (File.Exists(path))
                {
                    return path;
                }
            }

            return null;
        }

        /// <summary>Adds a pack from a folder of images (the files directly in it).</summary>
        public ArtPackImport AddFolder(string folder, string name = null)
        {
            var result = new ArtPackImport();
            if (!Directory.Exists(folder))
            {
                result.Problem = "That folder isn't there.";
                return result;
            }

            var files = new List<(string name, long size, Func<byte[]> read)>();
            foreach (var file in Directory.GetFiles(folder))
            {
                var path = file;
                files.Add((Path.GetFileName(file), new FileInfo(file).Length, () => File.ReadAllBytes(path)));
            }

            return Add(files, name ?? new DirectoryInfo(folder).Name, result);
        }

        /// <summary>Adds a pack from a .zip of images (the files directly in it, or in the one folder it holds).</summary>
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
                    var files = new List<(string name, long size, Func<byte[]> read)>();
                    foreach (var entry in archive.Entries)
                    {
                        if (string.IsNullOrEmpty(entry.Name))
                        {
                            continue; // a folder
                        }

                        // Only the file's own name is used (never its path), so nothing can be written outside the pack.
                        var captured = entry;
                        files.Add((entry.Name, entry.Length, () =>
                        {
                            using (var input = captured.Open())
                            using (var memory = new MemoryStream())
                            {
                                input.CopyTo(memory);
                                return memory.ToArray();
                            }
                        }));
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
            if (string.IsNullOrEmpty(id) || id.IndexOfAny(new[] { '/', '\\', ':' }) >= 0 || id.Contains(".."))
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

        private ArtPackImport Add(List<(string name, long size, Func<byte[]> read)> files, string name, ArtPackImport result)
        {
            var chosen = new Dictionary<string, (ArtFileName parsed, Func<byte[]> read, string file)>();
            var total = 0L;
            foreach (var file in files.OrderBy(f => f.name, StringComparer.OrdinalIgnoreCase))
            {
                if (!ArtFileName.TryParse(file.name, out var parsed))
                {
                    if (file.name.EndsWith(".webp", StringComparison.OrdinalIgnoreCase))
                    {
                        result.Skipped.Add(file.name + ": WebP images aren't supported -- save it as PNG or JPG");
                    }
                    else if (LooksLikeImage(file.name))
                    {
                        result.Skipped.Add(file.name + ": the card's number isn't at the start of the name");
                    }

                    continue;
                }

                if (file.size > MaxFileBytes)
                {
                    result.Skipped.Add(file.name + ": over " + MaxFileBytes / (1024 * 1024) + " MB");
                    continue;
                }

                if (chosen.Count >= MaxFiles || total + file.size > MaxTotalBytes)
                {
                    result.Skipped.Add(file.name + ": the pack is too big");
                    continue;
                }

                // Two files for one card: the first by name wins.
                var key = parsed.Kind + ":" + parsed.Number;
                if (chosen.ContainsKey(key))
                {
                    result.Skipped.Add(file.name + ": another file is already the art for that card");
                    continue;
                }

                total += file.size;
                chosen[key] = (parsed, file.read, file.name);
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
                    var bytes = item.read();
                    if (!HasImageHeader(bytes, item.parsed.Extension))
                    {
                        result.Skipped.Add(item.file + ": not really a " + item.parsed.Extension.ToUpperInvariant() + " image");
                        continue;
                    }

                    File.WriteAllBytes(Path.Combine(folder, item.parsed.StoredName), bytes);
                    switch (item.parsed.Kind)
                    {
                        case ArtKind.Card:
                            info.CardCount++;
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
                if (!ArtFileName.TryParse(Path.GetFileName(file), out var parsed))
                {
                    continue;
                }

                switch (parsed.Kind)
                {
                    case ArtKind.Card:
                        pack.CardCount++;
                        break;
                    case ArtKind.Back:
                        pack.HasBack = true;
                        break;
                    default:
                        pack.HasHurtFeelings = true;
                        break;
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
