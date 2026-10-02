using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// The puzzle collection: standalone solitaire puzzles, easiest first, each with its goal and how you've done.
    /// Attempt (or Try again) starts a fresh game and opens its board.
    /// </summary>
    public sealed class PuzzlesScreen : ListScreen
    {
        private bool _busy;

        protected override string Title => "Puzzles";

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _busy = false;
            SetStatus(string.Empty);

            AppServices.Puzzles.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Puzzles.Changed -= Rebuild;
        }

        private async Task Refresh()
        {
            var result = await AppServices.Puzzles.RefreshAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var puzzles = AppServices.Puzzles;

            if (puzzles.Puzzles.Count == 0)
            {
                var empty = UiFactory.Label(List, "Loading the puzzles...", 26, theme.textMuted);
                UiFactory.Size(empty.gameObject, height: 60f);
                return;
            }

            UiFactory.SectionTitle(List, theme, $"{puzzles.SolvedCount} of {puzzles.Puzzles.Count} solved");
            foreach (var puzzle in puzzles.Puzzles)
            {
                AddRow(theme, puzzle);
            }
        }

        private void AddRow(UiTheme theme, PuzzleInfo puzzle)
        {
            var row = UiFactory.Create("Puzzle " + puzzle.Title, List);
            row.gameObject.AddComponent<Image>().color = theme.panel;
            var layout = row.gameObject.AddComponent<HorizontalLayoutGroup>();
            layout.padding = new RectOffset(22, 18, 14, 14);
            layout.spacing = 20f;
            layout.childAlignment = TextAnchor.MiddleLeft;
            layout.childControlWidth = true;
            layout.childControlHeight = true;
            layout.childForceExpandWidth = false;
            layout.childForceExpandHeight = false;

            var text = UiFactory.Create("Text", row);
            UiFactory.Flexible(text.gameObject, width: 1f);
            var column = text.gameObject.AddComponent<VerticalLayoutGroup>();
            column.spacing = 4f;
            column.childControlWidth = true;
            column.childControlHeight = true;
            column.childForceExpandWidth = true;
            column.childForceExpandHeight = false;

            UiFactory.Label(text, (puzzle.Solved ? "✓  " : string.Empty) + puzzle.Title, 30, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Label(text, puzzle.Description, 24, theme.textMuted, TextAnchor.UpperLeft);
            var limit = PuzzleDisplay.Limit(puzzle);
            if (limit != null)
            {
                UiFactory.Label(text, limit, 22, theme.textMuted, TextAnchor.MiddleLeft);
            }

            UiFactory.Label(text, PuzzleDisplay.Progress(puzzle), 22, puzzle.Solved ? theme.accent : theme.textMuted, TextAnchor.MiddleLeft);

            var button = UiFactory.Button(row, puzzle.Solved ? "Try again" : "Attempt", theme, () => Run(() => Attempt(puzzle)), primary: !puzzle.Solved);
            button.gameObject.name = "Attempt " + puzzle.Title;

            // A fixed size: a long description wraps in its own column, but it must never squeeze the button.
            // (A layout group shrinks children from their preferred toward their minimum size, which is
            // nothing unless it's set.)
            var size = UiFactory.Size(button.gameObject, 220f);
            size.minWidth = 220f;
            size.flexibleWidth = 0f;
        }

        private async Task Attempt(PuzzleInfo puzzle)
        {
            if (_busy)
            {
                return;
            }

            _busy = true;
            SetStatus("Starting the puzzle...");
            var result = await AppServices.Puzzles.StartAsync(puzzle);
            if (this == null)
            {
                return;
            }

            _busy = false;
            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            SetStatus(string.Empty);
            Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, result.GameId.Value));
        }
    }
}
