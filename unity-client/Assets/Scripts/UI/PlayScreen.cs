using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Play: your games (waiting on you first, then in progress, then
    /// finished), with the doors to starting a new game and to the open
    /// lobby, and Watch for spectating. Any game can be opened to its board, and
    /// a finished one can be rematched.
    /// </summary>
    public sealed class PlayScreen : ListScreen
    {
        private const float PollSeconds = 10f;
        private const int FinishedShownByDefault = 15;

        private bool _showAllFinished;

        protected override string Title => "Play";

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _showAllFinished = false;
            SetStatus(args as string);

            AppServices.Lobby.Changed += Rebuild;
            Rebuild();
            Run(Refresh);
            PollWhileShown(PollSeconds, Refresh);
        }

        public override void OnHidden()
        {
            AppServices.Lobby.Changed -= Rebuild;
            StopPolling();
        }

        protected override void BuildAbove(RectTransform column, UiTheme theme)
        {
            var actions = UiFactory.Row(column, "Actions", 16f, TextAnchor.MiddleCenter);
            var newGame = UiFactory.Button(actions.transform, "New game", theme, () => Router.Show<NewGameScreen>());
            UiFactory.Flexible(newGame.gameObject, width: 1f);
            var open = UiFactory.Button(actions.transform, "Open games", theme, () => Router.Show<OpenGamesScreen>(), primary: false);
            UiFactory.Flexible(open.gameObject, width: 1f);
            var watch = UiFactory.Button(actions.transform, "Watch", theme, () => Router.Show<WatchScreen>(), primary: false);
            UiFactory.Flexible(watch.gameObject, width: 1f);
        }

        private async Task Refresh()
        {
            var result = await AppServices.Lobby.RefreshGamesAsync();
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
            var lobby = AppServices.Lobby;
            var me = AppServices.Auth.CurrentUser;

            UiFactory.SectionTitle(List, theme, $"In progress ({lobby.ActiveGames.Count})");
            if (lobby.ActiveGames.Count == 0)
            {
                var empty = UiFactory.Label(List, "No games in progress. Start one with New game.", 26, theme.textMuted);
                UiFactory.Size(empty.gameObject, height: 60f);
            }

            foreach (var entry in GameDisplay.Group(lobby.ActiveGames))
            {
                AddEntry(theme, entry, me, canRematch: false);
            }

            // A match's games are one entry, so the first few are the most recent games and matches, not a count of games.
            UiFactory.SectionTitle(List, theme, $"Finished ({lobby.PastGames.Count})");
            var finished = GameDisplay.Group(lobby.PastGames);
            var shown = _showAllFinished ? finished.Count : System.Math.Min(FinishedShownByDefault, finished.Count);
            for (var i = 0; i < shown; i++)
            {
                AddEntry(theme, finished[i], me, canRematch: true);
            }

            if (shown < finished.Count)
            {
                var more = UiFactory.Button(List, $"Show all {lobby.PastGames.Count}", theme, () =>
                {
                    _showAllFinished = true;
                    Rebuild();
                }, primary: false);
                UiFactory.Size(more.gameObject, height: 56f);
            }
        }

        private void AddEntry(UiTheme theme, GameDisplay.GameEntry entry, User me, bool canRematch)
        {
            if (entry.IsMatch)
            {
                AddMatchGroup(theme, entry, me, canRematch);
            }
            else
            {
                AddGameRow(theme, entry.Game, me, canRematch);
            }
        }

        // A best-of-three match as one block: who and what and the score on top, then each game, latest first, indented
        // beside a bar in the accent color.
        private void AddMatchGroup(UiTheme theme, GameDisplay.GameEntry entry, User me, bool canRematch)
        {
            var latest = entry.Game;
            var match = entry.Match;
            var block = UiFactory.Create("Match " + GameDisplay.MatchKey(latest), List);
            var blockLayout = block.gameObject.AddComponent<VerticalLayoutGroup>();
            blockLayout.spacing = 6f;
            blockLayout.childControlWidth = true;
            blockLayout.childControlHeight = true;
            blockLayout.childForceExpandWidth = true;
            blockLayout.childForceExpandHeight = false;

            var header = UiFactory.RowPanel(block, theme, height: 134f);
            header.gameObject.name = "Match header";
            var text = UiFactory.Create("Text", header.transform);
            UiFactory.Flexible(text.gameObject, width: 1f);
            var column = text.gameObject.AddComponent<VerticalLayoutGroup>();
            column.spacing = 2f;
            column.childControlWidth = true;
            column.childControlHeight = true;
            column.childForceExpandWidth = true;
            column.childForceExpandHeight = false;
            UiFactory.Label(text, "vs " + GameDisplay.Opponents(latest, me?.Username), 30, theme.textPrimary, TextAnchor.MiddleLeft, FontStyle.Bold);
            UiFactory.Label(text, GameDisplay.Settings(latest.Format, latest.DeckType, 0, latest.CustomDeckName) + "  -  Best of three", 24, theme.textMuted, TextAnchor.MiddleLeft);
            UiFactory.Label(text, GameDisplay.MatchScore(match), 24, theme.textPrimary, TextAnchor.MiddleLeft);
            var result = GameDisplay.MatchResult(match);
            if (result != null)
            {
                UiFactory.Label(text, result, 24, theme.accent, TextAnchor.MiddleLeft, FontStyle.Bold);
            }

            AddRematch(theme, header.transform, latest, me, canRematch && match.Status == "completed");

            var games = UiFactory.Create("Match games", block);
            var gamesLayout = games.gameObject.AddComponent<VerticalLayoutGroup>();
            gamesLayout.padding = new RectOffset(34, 0, 0, 0);
            gamesLayout.spacing = 6f;
            gamesLayout.childControlWidth = true;
            gamesLayout.childControlHeight = true;
            gamesLayout.childForceExpandWidth = true;
            gamesLayout.childForceExpandHeight = false;
            foreach (var game in entry.MatchGames)
            {
                AddGameRow(theme, game, me, canRematch: false, parent: games);
            }

            // The bar down the left of the games, tying them to the header above.
            var bar = UiFactory.Create("Match bar", games);
            bar.gameObject.AddComponent<Image>().color = theme.accent;
            bar.gameObject.AddComponent<LayoutElement>().ignoreLayout = true;
            bar.anchorMin = new Vector2(0f, 0f);
            bar.anchorMax = new Vector2(0f, 1f);
            bar.pivot = new Vector2(0f, 0.5f);
            bar.sizeDelta = new Vector2(8f, 0f);
            bar.anchoredPosition = new Vector2(12f, 0f);
        }

        private void AddRematch(UiTheme theme, Transform row, GameSummary game, User me, bool canRematch)
        {
            var rematch = canRematch && me != null && game.IsCompleted ? GameSetup.ForRematch(game, me.Id) : null;
            if (rematch != null)
            {
                var button = UiFactory.Button(row, "Rematch", theme, () => Router.Show<NewGameScreen>(rematch), primary: false);
                UiFactory.Size(button.gameObject, width: 170f);
            }
        }

        // A game's row. Inside a match group (parent given) it is headed by its number in the match, not its opponents.
        private void AddGameRow(UiTheme theme, GameSummary game, User me, bool canRematch, Transform parent = null)
        {
            var inMatch = parent != null;
            var row = UiFactory.RowPanel(parent ?? List, theme, height: inMatch ? 84f : 100f);
            if (inMatch)
            {
                row.gameObject.name = "Game " + game.MatchGameNumber;
                UiFactory.TwoLineText(row.transform, theme, "Game " + (game.MatchGameNumber ?? 1), GameDisplay.WhenLine(game));
            }
            else
            {
                UiFactory.TwoLineText(row.transform, theme, "vs " + GameDisplay.Opponents(game, me?.Username), GameDisplay.Settings(game));
            }

            var needsYou = GameDisplay.NeedsYou(game);
            var status = UiFactory.Label(
                row.transform, GameDisplay.StatusLine(game, me?.Username), 26,
                needsYou ? theme.accent : theme.textMuted, TextAnchor.MiddleRight, needsYou ? FontStyle.Bold : FontStyle.Normal);
            UiFactory.Size(status.gameObject, width: 300f);

            var board = UiFactory.Button(
                row.transform, "Open", theme,
                () => Router.Show<BoardScreen>(BoardSession.ForPlayer(AppServices.Api, game.Id)),
                primary: needsYou);
            UiFactory.Size(board.gameObject, width: 130f);

            AddRematch(theme, row.transform, game, me, canRematch);
        }
    }
}
