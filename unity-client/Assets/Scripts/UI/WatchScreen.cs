using System.Linq;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;
using UnityEngine.UI;

namespace MoodSwings.UI
{
    /// <summary>
    /// Watch someone else's game: open one of your friends' games that's in
    /// progress, or paste a spectate code a player shared. Either way it
    /// opens the board as a spectator -- no hand, no actions.
    /// </summary>
    public sealed class WatchScreen : ListScreen
    {
        private const float PollSeconds = 10f;

        private InputField _code;

        protected override string Title => "Watch a game";

        public override void OnShown(object args)
        {
            EnsureBuilt();
            _code.text = string.Empty;
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
            var row = UiFactory.Row(column, "CodeRow", 16f, TextAnchor.MiddleCenter);
            _code = UiFactory.Input(row.transform, "Spectate code", theme);
            UiFactory.Flexible(_code.gameObject, width: 1f);
            var watch = UiFactory.Button(row.transform, "Watch", theme, () => Run(WatchByCodeAsync));
            UiFactory.Size(watch.gameObject, width: 220f);

            var note = UiFactory.Label(
                column, "Players can share a code from their game. Friends' games need no code.", 22, theme.textMuted);
            UiFactory.Size(note.gameObject, height: 32f);
        }

        private async Task Refresh()
        {
            var result = await AppServices.Lobby.RefreshWatchableGamesAsync();
            if (this != null && !result.Ok)
            {
                SetStatus(result.Message, isError: true);
            }
        }

        private async Task WatchByCodeAsync()
        {
            var code = _code.text.Trim();
            var result = await AppServices.Lobby.ResolveSpectateCodeAsync(code);
            if (this == null)
            {
                return;
            }

            if (!result.Ok)
            {
                SetStatus(result.Message, isError: true);
                return;
            }

            Router.Show<BoardScreen>(BoardSession.ForSpectator(AppServices.Api, result.GameId.Value, code));
        }

        private void Rebuild()
        {
            if (List == null)
            {
                return;
            }

            ClearList();
            var theme = AppServices.Theme;
            var games = AppServices.Lobby.WatchableGames;
            var me = AppServices.Auth.CurrentUser;

            UiFactory.SectionTitle(List, theme, $"Friends' games ({games.Count})");
            if (games.Count == 0)
            {
                var empty = UiFactory.Label(List, "None of your friends is in a game right now.", 26, theme.textMuted);
                UiFactory.Size(empty.gameObject, height: 60f);
            }

            foreach (var game in games)
            {
                var row = UiFactory.RowPanel(List, theme, height: 100f);
                var players = string.Join(", ", game.Players.OrderBy(p => p.SeatOrder).Select(p => p.Username));
                UiFactory.TwoLineText(
                    row.transform, theme, players, GameDisplay.Settings(game) + "  -  " + GameDisplay.StatusLine(game, me?.Username));

                var id = game.Id;
                var watch = UiFactory.Button(
                    row.transform, "Watch", theme,
                    () => Router.Show<BoardScreen>(BoardSession.ForSpectator(AppServices.Api, id)));
                UiFactory.Size(watch.gameObject, width: 170f);
            }
        }
    }
}
