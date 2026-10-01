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

            foreach (var game in lobby.ActiveGames)
            {
                AddGameRow(theme, game, me, canRematch: false);
            }

            UiFactory.SectionTitle(List, theme, $"Finished ({lobby.PastGames.Count})");
            var shown = _showAllFinished ? lobby.PastGames.Count : System.Math.Min(FinishedShownByDefault, lobby.PastGames.Count);
            for (var i = 0; i < shown; i++)
            {
                AddGameRow(theme, lobby.PastGames[i], me, canRematch: true);
            }

            if (shown < lobby.PastGames.Count)
            {
                var more = UiFactory.Button(List, $"Show all {lobby.PastGames.Count}", theme, () =>
                {
                    _showAllFinished = true;
                    Rebuild();
                }, primary: false);
                UiFactory.Size(more.gameObject, height: 56f);
            }
        }

        private void AddGameRow(UiTheme theme, GameSummary game, User me, bool canRematch)
        {
            var row = UiFactory.RowPanel(List, theme, height: 100f);
            UiFactory.TwoLineText(row.transform, theme, "vs " + GameDisplay.Opponents(game, me?.Username), GameDisplay.Settings(game));

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

            var rematch = canRematch && me != null && game.IsCompleted ? GameSetup.ForRematch(game, me.Id) : null;
            if (rematch != null)
            {
                var button = UiFactory.Button(row.transform, "Rematch", theme, () => Router.Show<NewGameScreen>(rematch), primary: false);
                UiFactory.Size(button.gameObject, width: 170f);
            }
        }
    }
}
