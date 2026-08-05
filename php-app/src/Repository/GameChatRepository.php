<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class GameChatRepository
{
    /**
     * Every 'table'-channel message in $gameId, plus every 'team'-channel
     * one whose team_id matches $viewerTeamId -- a NULL $viewerTeamId
     * (every non-team format, or a team-format seat with no team_id set
     * yet) naturally excludes every 'team' row via SQL's own NULL
     * comparison semantics (`team_id = NULL` is never true), so no
     * special-casing is needed here for the non-team case. Oldest first,
     * matching game_events' own ordering.
     *
     * @return array<int, array{id:int, sender_game_player_id:int, channel:string, message_text:string, created_at:string}>
     */
    public function messagesFor(int $gameId, ?int $viewerTeamId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT id, sender_game_player_id, channel, message_text, created_at FROM game_chat_messages
             WHERE game_id = :game_id AND (channel = 'table' OR (channel = 'team' AND team_id = :viewer_team_id))
             ORDER BY id ASC"
        );
        $stmt->execute(['game_id' => $gameId, 'viewer_team_id' => $viewerTeamId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sender_game_player_id' => (int) $row['sender_game_player_id'],
                'channel' => $row['channel'],
                'message_text' => $row['message_text'],
                'created_at' => $row['created_at'],
            ],
            $stmt->fetchAll()
        );
    }

    public function insert(int $gameId, int $senderGamePlayerId, string $channel, ?int $teamId, string $messageText): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO game_chat_messages (game_id, sender_game_player_id, channel, team_id, message_text)
             VALUES (:game_id, :sender_game_player_id, :channel, :team_id, :message_text)'
        );
        $stmt->execute([
            'game_id' => $gameId,
            'sender_game_player_id' => $senderGamePlayerId,
            'channel' => $channel,
            'team_id' => $teamId,
            'message_text' => $messageText,
        ]);
    }
}
