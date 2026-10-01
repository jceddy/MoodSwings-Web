using System.Collections.Generic;
using System.Threading.Tasks;
using MoodSwings.Core;
using MoodSwings.Networking;
using UnityEngine;

namespace MoodSwings.UI
{
    /// <summary>
    /// The open lobby: games other players have posted that you can join, the
    /// ones you posted (take down), and the ones you've joined and are waiting
    /// on (leave). A game starts when its last player joins. Refreshes every
    /// few seconds while open.
    /// </summary>
    public sealed class OpenGamesScreen : ListScreen
    {
        private const float PollSeconds = 8f;

        protected override string Title => "Open games";

        public override void OnShown(object args)
        {
            EnsureBuilt();
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
            UiFactory.Button(column, "Post a game", theme, () => Router.Show<NewGameScreen>(new GameSetup { PostToOpenLobby = true }));

            var note = UiFactory.Label(
                column, "A posted game starts as soon as enough players have joined.", 22, theme.textMuted);
            UiFactory.Size(note.gameObject, height: 32f);
        }

        private async Task Refresh()
        {
            var result = await AppServices.Lobby.RefreshOpenGamesAsync();
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

            AddSection(theme, "Join a game", lobby.AvailableOpenGames, "Nobody has posted a game right now.", "Join",
                listing => RunAction(() => lobby.JoinOpenGameAsync(listing)));
            AddSection(theme, "Games you posted", lobby.MyOpenGames, "You haven't posted a game.", "Take down",
                listing => RunAction(() => lobby.CancelOpenGameAsync(listing)));
            AddSection(theme, "Games you joined", lobby.JoinedOpenGames, "You haven't joined any open games.", "Leave",
                listing => RunAction(() => lobby.LeaveOpenGameAsync(listing)));
        }

        private void AddSection(
            UiTheme theme, string title, IReadOnlyList<OpenGameListing> listings, string emptyText, string actionLabel,
            System.Action<OpenGameListing> action)
        {
            UiFactory.SectionTitle(List, theme, $"{title} ({listings.Count})");
            if (listings.Count == 0)
            {
                var empty = UiFactory.Label(List, emptyText, 26, theme.textMuted);
                UiFactory.Size(empty.gameObject, height: 60f);
            }

            var yourUserId = AppServices.Auth.CurrentUser?.Id ?? 0;
            foreach (var listing in listings)
            {
                var row = UiFactory.RowPanel(List, theme, height: 100f);
                UiFactory.TwoLineText(row.transform, theme, GameDisplay.ListingTitle(listing, yourUserId), GameDisplay.ListingSettings(listing));

                var captured = listing;
                var button = UiFactory.Button(row.transform, actionLabel, theme, () => action(captured), primary: actionLabel == "Join");
                UiFactory.Size(button.gameObject, width: 220f);
            }
        }

        private void RunAction(System.Func<Task<LobbyResult>> action)
        {
            Run(async () =>
            {
                var result = await action();
                if (this != null)
                {
                    SetStatus(result.Message, isError: !result.Ok);
                }
            });
        }
    }
}
