using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>The outcome of a lobby action, ready to show. <see cref="GameId"/> is set when the action produced a game.</summary>
    public sealed class LobbyResult
    {
        public bool Ok { get; set; }

        public string Message { get; set; }

        public int? GameId { get; set; }
    }

    /// <summary>
    /// Everything the lobby screens show and do: your games, the bots you can
    /// play, and the open-lobby listings, plus creating, posting, joining,
    /// leaving and cancelling. Holds the last fetched state and raises
    /// <see cref="Changed"/> when it is replaced. Free of UI, so it tests
    /// against a fake transport.
    /// </summary>
    public sealed class LobbyFlow
    {
        private readonly ApiClient _api;

        private List<GameSummary> _active = new List<GameSummary>();
        private List<GameSummary> _past = new List<GameSummary>();
        private List<Bot> _bots = new List<Bot>();
        private List<OpenGameListing> _available = new List<OpenGameListing>();
        private List<OpenGameListing> _mine = new List<OpenGameListing>();
        private List<OpenGameListing> _joined = new List<OpenGameListing>();
        private List<GameSummary> _watchable = new List<GameSummary>();

        public LobbyFlow(ApiClient api)
        {
            _api = api;
        }

        /// <summary>Games waiting on you first, then the most recently active.</summary>
        public IReadOnlyList<GameSummary> ActiveGames => _active;

        /// <summary>Most recently finished first.</summary>
        public IReadOnlyList<GameSummary> PastGames => _past;

        public IReadOnlyList<Bot> Bots => _bots;

        public IReadOnlyList<OpenGameListing> AvailableOpenGames => _available;

        public IReadOnlyList<OpenGameListing> MyOpenGames => _mine;

        public IReadOnlyList<OpenGameListing> JoinedOpenGames => _joined;

        /// <summary>In-progress games your friends are in, which you can watch.</summary>
        public IReadOnlyList<GameSummary> WatchableGames => _watchable;

        /// <summary>How many active games are waiting on you (your turn, or a decision only you can make).</summary>
        public int GamesNeedingYou => _active.Count(GameDisplay.NeedsYou);

        public event Action Changed;

        public void Clear()
        {
            _active = new List<GameSummary>();
            _past = new List<GameSummary>();
            _bots = new List<Bot>();
            _available = new List<OpenGameListing>();
            _mine = new List<OpenGameListing>();
            _joined = new List<OpenGameListing>();
            _watchable = new List<GameSummary>();
            Changed?.Invoke();
        }

        /// <summary>Fetches active and finished games together; state is replaced only if both succeed.</summary>
        public async Task<LobbyResult> RefreshGamesAsync(CancellationToken cancellationToken = default)
        {
            var activeTask = _api.ListActiveGamesAsync(cancellationToken);
            var pastTask = _api.ListPastGamesAsync(cancellationToken);
            var active = await activeTask;
            var past = await pastTask;

            if (!active.Ok)
            {
                return Failed(active.UserMessage("Couldn't load your games."));
            }

            if (!past.Ok)
            {
                return Failed(past.UserMessage("Couldn't load your finished games."));
            }

            _active = active.Value.Games
                .OrderByDescending(GameDisplay.NeedsYou)
                .ThenByDescending(g => g.LastMoveAt ?? string.Empty, StringComparer.Ordinal)
                .ToList();
            _past = past.Value.Games
                .OrderByDescending(g => g.CompletedAt ?? string.Empty, StringComparer.Ordinal)
                .ToList();
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Whether the server offers synchronous games right now; false until known, and when it can't be asked.</summary>
        public bool SynchronousModeEnabled { get; private set; }

        public async Task<LobbyResult> RefreshSynchronousModeFlagAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.GetSynchronousModeEnabledAsync(cancellationToken);
            SynchronousModeEnabled = result.Ok && result.Value.Enabled;
            Changed?.Invoke();
            return result.Ok ? new LobbyResult { Ok = true } : Failed(result.UserMessage("Couldn't check for synchronous games."));
        }

        public async Task<LobbyResult> RefreshBotsAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.ListBotsAsync(cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't load the practice bots."));
            }

            _bots = result.Value.Bots;
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Fetches the listings you could join, the ones you posted, and the ones you joined, all or nothing.</summary>
        public async Task<LobbyResult> RefreshOpenGamesAsync(CancellationToken cancellationToken = default)
        {
            var availableTask = _api.ListOpenGamesAsync(OpenGameScope.Available, cancellationToken);
            var mineTask = _api.ListOpenGamesAsync(OpenGameScope.Mine, cancellationToken);
            var joinedTask = _api.ListOpenGamesAsync(OpenGameScope.Joined, cancellationToken);
            var available = await availableTask;
            var mine = await mineTask;
            var joined = await joinedTask;

            foreach (var response in new[] { available, mine, joined })
            {
                if (!response.Ok)
                {
                    return Failed(response.UserMessage("Couldn't load the open lobby."));
                }
            }

            _available = Newest(available.Value.Listings);
            _mine = Newest(mine.Value.Listings);
            _joined = Newest(joined.Value.Listings);
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        public async Task<LobbyResult> RefreshWatchableGamesAsync(CancellationToken cancellationToken = default)
        {
            var result = await _api.ListWatchableGamesAsync(cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't load your friends' games."));
            }

            _watchable = result.Value.Games
                .OrderByDescending(g => g.LastMoveAt ?? string.Empty, StringComparer.Ordinal)
                .ToList();
            Changed?.Invoke();
            return new LobbyResult { Ok = true };
        }

        /// <summary>Looks up the game a spectate code belongs to; the id is in the result.</summary>
        public async Task<LobbyResult> ResolveSpectateCodeAsync(string code, CancellationToken cancellationToken = default)
        {
            code = (code ?? string.Empty).Trim();
            if (code.Length == 0)
            {
                return Failed("Enter a spectate code.");
            }

            var result = await _api.ResolveSpectateCodeAsync(code, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't find a game for that code."));
            }

            return new LobbyResult { Ok = true, GameId = result.Value.GameId };
        }

        /// <summary>Creates a game with the chosen opponents (bots and friends) seated straight away.</summary>
        public async Task<LobbyResult> CreateGameAsync(GameSetup setup, CancellationToken cancellationToken = default)
        {
            var problem = setup.ValidateDirectGame();
            if (problem != null)
            {
                return Failed(problem);
            }

            var result = await _api.CreateGameAsync(setup.ToDirectGameBody(), cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't create the game."));
            }

            await RefreshGamesAsync(cancellationToken);
            return new LobbyResult { Ok = true, Message = "Game created.", GameId = result.Value.GameId };
        }

        /// <summary>Posts a game to the open lobby, to start once enough players have joined.</summary>
        public async Task<LobbyResult> PostOpenGameAsync(GameSetup setup, CancellationToken cancellationToken = default)
        {
            var problem = setup.ValidateOpenGame();
            if (problem != null)
            {
                return Failed(problem);
            }

            var result = await _api.PostOpenGameAsync(setup.ToOpenGameBody(), cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't post the game."));
            }

            await RefreshOpenGamesAsync(cancellationToken);
            return new LobbyResult
            {
                Ok = true,
                Message = $"Posted. The game starts once {setup.EffectiveOpenLobbyPlayerCount} players are seated.",
            };
        }

        /// <summary>Joins a listing. If you were the last player needed, the game starts and its id is in the result.</summary>
        public async Task<LobbyResult> JoinOpenGameAsync(OpenGameListing listing, CancellationToken cancellationToken = default)
        {
            var result = await _api.JoinOpenGameAsync(listing.Id, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't join that game."));
            }

            await RefreshOpenGamesAsync(cancellationToken);
            if (result.Value.Started)
            {
                await RefreshGamesAsync(cancellationToken);
                return new LobbyResult { Ok = true, Message = "The game is starting!", GameId = result.Value.GameId };
            }

            // joined_count counts joiners, including you; the poster takes the first seat.
            var missing = Math.Max(0, result.Value.TargetPlayerCount - (result.Value.JoinedCount + 1));
            return new LobbyResult
            {
                Ok = true,
                Message = $"Joined. Waiting for {missing} more {(missing == 1 ? "player" : "players")}.",
            };
        }

        public async Task<LobbyResult> LeaveOpenGameAsync(OpenGameListing listing, CancellationToken cancellationToken = default)
        {
            var result = await _api.LeaveOpenGameAsync(listing.Id, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't leave that game."));
            }

            await RefreshOpenGamesAsync(cancellationToken);
            return new LobbyResult { Ok = true, Message = "You left the game." };
        }

        public async Task<LobbyResult> CancelOpenGameAsync(OpenGameListing listing, CancellationToken cancellationToken = default)
        {
            var result = await _api.CancelOpenGameAsync(listing.Id, cancellationToken);
            if (!result.Ok)
            {
                return Failed(result.UserMessage("Couldn't cancel that game."));
            }

            await RefreshOpenGamesAsync(cancellationToken);
            return new LobbyResult { Ok = true, Message = "Your game was taken down." };
        }

        private static List<OpenGameListing> Newest(List<OpenGameListing> listings) =>
            listings.OrderByDescending(l => l.CreatedAt ?? string.Empty, StringComparer.Ordinal).ToList();

        private static LobbyResult Failed(string message) => new LobbyResult { Ok = false, Message = message };
    }
}
