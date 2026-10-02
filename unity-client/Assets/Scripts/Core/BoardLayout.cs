using System;
using System.Collections.Generic;
using System.Linq;
using MoodSwings.Networking;

namespace MoodSwings.Core
{
    /// <summary>Where a player sits relative to the viewer, as the web client's in-play board names them.</summary>
    public enum SeatZone
    {
        /// <summary>The viewer.</summary>
        South,

        /// <summary>Directly across, in 2- and 4-player games.</summary>
        North,

        /// <summary>The viewer's left in a 3-player game.</summary>
        Northwest,

        /// <summary>The viewer's right in a 3-player game.</summary>
        Northeast,

        /// <summary>The viewer's left in a 4-player game.</summary>
        West,

        /// <summary>The viewer's right in a 4-player game.</summary>
        East,
    }

    /// <summary>
    /// Who sits where. This mirrors web-static/js/game.js's
    /// inPlayZoneAssignments() exactly, and it matters for more than looks:
    /// the engine defines a player's "left-hand neighbor" (Confusion,
    /// Avoidance...) as the NEXT seat in turn order, and ascending seat order
    /// is clockwise, so that next seat is drawn at the viewer's left. A board
    /// that seated people differently would contradict what those cards say.
    /// </summary>
    public static class BoardLayout
    {
        public const int MaxSeats = 4;

        // Indexed by the number of clockwise steps from the viewer.
        private static readonly Dictionary<int, SeatZone[]> ZonesByPlayerCount = new Dictionary<int, SeatZone[]>
        {
            [1] = new[] { SeatZone.South },
            [2] = new[] { SeatZone.South, SeatZone.North },
            [3] = new[] { SeatZone.South, SeatZone.Northwest, SeatZone.Northeast },
            [4] = new[] { SeatZone.South, SeatZone.West, SeatZone.North, SeatZone.East },
        };

        /// <summary>
        /// The zone of each seated player, keyed by game_player_id. A
        /// spectator has no seat to anchor "south" on, so they get the player in
        /// the first seat there, which keeps their view stable.
        /// </summary>
        /// <param name="viewerGamePlayerId">The viewer's seat, or null for a spectator.</param>
        public static Dictionary<int, SeatZone> Assign(IReadOnlyList<BoardPlayer> players, int? viewerGamePlayerId)
        {
            if (players.Count < 1 || players.Count > MaxSeats)
            {
                throw new ArgumentOutOfRangeException(nameof(players), $"A game seats 1 to {MaxSeats} players, not {players.Count}.");
            }

            // Position by rank rather than by raw seat_order, so a gap in the numbering can't shift anyone.
            var inTurnOrder = players.OrderBy(p => p.SeatOrder).ToList();
            var viewerRank = inTurnOrder.FindIndex(p => p.GamePlayerId == viewerGamePlayerId);
            if (viewerRank < 0)
            {
                viewerRank = 0;
            }

            var zones = ZonesByPlayerCount[players.Count];
            var result = new Dictionary<int, SeatZone>();
            for (var rank = 0; rank < inTurnOrder.Count; rank++)
            {
                var stepsClockwise = (rank - viewerRank + inTurnOrder.Count) % inTurnOrder.Count;
                result[inTurnOrder[rank].GamePlayerId] = zones[stepsClockwise];
            }

            return result;
        }

        /// <summary>The seats other than the viewer's, in the order their zones read from left to right across the table.</summary>
        public static IReadOnlyList<SeatZone> OpponentZonesLeftToRight(int playerCount)
        {
            switch (playerCount)
            {
                case 2: return new[] { SeatZone.North };
                case 3: return new[] { SeatZone.Northwest, SeatZone.Northeast };
                case 4: return new[] { SeatZone.West, SeatZone.North, SeatZone.East };
                default: return Array.Empty<SeatZone>();
            }
        }
    }
}
