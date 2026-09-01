<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class GameChatRepository
{
    /**
     * Issue #463: once a game belongs to a draft match ($draftMatchId
     * non-null), every message anywhere in that match is fetched --
     * spanning the drafting/deck-building phase and every game within a
     * 2-player best-of-three -- rather than just $gameId's own. A
     * non-match game (no draft_match_id at all) keeps the original
     * per-game_id scoping unchanged. Sender identity is always
     * sender_user_id (match-stable -- see this table's own migration
     * docblock), never sender_game_player_id, since a message from an
     * earlier game in the match would otherwise have no way to resolve
     * back to a still-valid seat once that earlier game's own
     * game_players rows are gone.
     *
     * Every 'table'-channel message, plus every 'team'-channel one whose
     * team_id matches $viewerTeamId -- a NULL $viewerTeamId (every
     * non-team format, or a team-format seat with no team_id set yet)
     * naturally excludes every 'team' row via SQL's own NULL comparison
     * semantics (`team_id = NULL` is never true), so no special-casing is
     * needed here for the non-team case. Oldest first, matching
     * game_events' own ordering.
     *
     * @return array<int, array{id:int, sender_user_id:int, channel:string, message_text:string, created_at:string}>
     */
    public function messagesFor(int $gameId, ?int $draftMatchId, ?int $viewerTeamId): array
    {
        $stmt = $draftMatchId !== null
            ? Connection::get()->prepare(
                "SELECT id, sender_user_id, channel, message_text, created_at FROM game_chat_messages
                 WHERE draft_match_id = :match_id AND (channel = 'table' OR (channel = 'team' AND team_id = :viewer_team_id))
                 ORDER BY id ASC"
            )
            : Connection::get()->prepare(
                "SELECT id, sender_user_id, channel, message_text, created_at FROM game_chat_messages
                 WHERE game_id = :game_id AND (channel = 'table' OR (channel = 'team' AND team_id = :viewer_team_id))
                 ORDER BY id ASC"
            );
        $stmt->execute($draftMatchId !== null
            ? ['match_id' => $draftMatchId, 'viewer_team_id' => $viewerTeamId]
            : ['game_id' => $gameId, 'viewer_team_id' => $viewerTeamId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sender_user_id' => (int) $row['sender_user_id'],
                'channel' => $row['channel'],
                'message_text' => $row['message_text'],
                'created_at' => $row['created_at'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * $gameId/$senderGamePlayerId still record which specific game/seat
     * this message was physically sent from (unchanged FK/cascade, so a
     * message is still cleaned up whenever THAT specific stale game is
     * deleted -- see GameService::deleteStaleCompletedGames()); $draftMatchId/
     * $senderUserId are the match-stable identifiers messagesFor() above
     * actually reads by once a match is involved.
     */
    public function insert(int $gameId, ?int $draftMatchId, int $senderGamePlayerId, int $senderUserId, string $channel, ?int $teamId, string $messageText): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO game_chat_messages (game_id, draft_match_id, sender_game_player_id, sender_user_id, channel, team_id, message_text)
             VALUES (:game_id, :draft_match_id, :sender_game_player_id, :sender_user_id, :channel, :team_id, :message_text)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'draft_match_id' => $draftMatchId,
            'sender_game_player_id' => $senderGamePlayerId,
            'sender_user_id' => $senderUserId,
            'channel' => $channel,
            'team_id' => $teamId,
            'message_text' => $messageText,
        ]);
    }
}
