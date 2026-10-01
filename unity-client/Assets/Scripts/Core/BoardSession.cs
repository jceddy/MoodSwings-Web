using System;
using System.Threading;
using System.Threading.Tasks;
using MoodSwings.Networking;
using Newtonsoft.Json;

namespace MoodSwings.Core
{
    public sealed class BoardRefreshResult
    {
        public bool Ok { get; set; }

        public string Message { get; set; }
    }

    /// <summary>
    /// One open game on the board: which game, whether you're playing or
    /// watching, and the last state fetched. The board polls
    /// <see cref="RefreshAsync"/> while it's showing; that isn't just a
    /// refresh, because the server advances bot turns and enforces timers
    /// as part of answering it. A failed refresh keeps the previous state, so
    /// a dropped connection leaves the last known board up instead of a blank.
    /// </summary>
    public sealed class BoardSession
    {
        private readonly ApiClient _api;
        private readonly string _spectateCode;
        private string _lastSnapshot;

        private BoardSession(ApiClient api, int gameId, bool spectating, string spectateCode)
        {
            _api = api;
            GameId = gameId;
            IsSpectating = spectating;
            _spectateCode = spectateCode;
        }

        public static BoardSession ForPlayer(ApiClient api, int gameId) => new BoardSession(api, gameId, false, null);

        /// <param name="spectateCode">Needed for a game you can't see through a friendship; null for a friend's game.</param>
        public static BoardSession ForSpectator(ApiClient api, int gameId, string spectateCode = null) =>
            new BoardSession(api, gameId, true, spectateCode);

        public int GameId { get; }

        /// <summary>Watching rather than playing: no hand, no actions.</summary>
        public bool IsSpectating { get; }

        /// <summary>Null until the first successful refresh.</summary>
        public GameState State { get; private set; }

        /// <summary>Raised when a refresh brought a state different from the last one.</summary>
        public event Action Changed;

        public async Task<BoardRefreshResult> RefreshAsync(CancellationToken cancellationToken = default)
        {
            var result = IsSpectating
                ? await _api.GetSpectatorStateAsync(GameId, _spectateCode, cancellationToken)
                : await _api.GetGameStateAsync(GameId, cancellationToken);

            if (!result.Ok)
            {
                return new BoardRefreshResult { Ok = false, Message = result.UserMessage("Couldn't load the game.") };
            }

            State = result.Value;

            // The board polls every few seconds; most polls find nothing new, and
            // redrawing identical content would only flicker.
            var snapshot = JsonConvert.SerializeObject(State);
            if (snapshot != _lastSnapshot)
            {
                _lastSnapshot = snapshot;
                Changed?.Invoke();
            }

            return new BoardRefreshResult { Ok = true };
        }
    }
}
