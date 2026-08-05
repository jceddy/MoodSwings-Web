<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class NotificationPreferenceRepository
{
    /**
     * All-true-except-disable_cooldown defaults for a user who's never
     * touched their preferences (no row yet) -- see migration 0048's
     * docblock: a row is only ever written once a user actually changes a
     * setting. disable_cooldown defaults false (the 5-minute cooldown is
     * on) -- see migration 0051's own docblock for what turning it on
     * does.
     *
     * @return array{notify_your_turn: bool, notify_friend_request: bool, notify_game_finished: bool, notify_chat_message: bool, disable_cooldown: bool}
     */
    public function forUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT notify_your_turn, notify_friend_request, notify_game_finished, notify_chat_message, disable_cooldown
             FROM notification_preferences WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return [
                'notify_your_turn' => true,
                'notify_friend_request' => true,
                'notify_game_finished' => true,
                'notify_chat_message' => true,
                'disable_cooldown' => false,
            ];
        }

        return [
            'notify_your_turn' => (bool) $row['notify_your_turn'],
            'notify_friend_request' => (bool) $row['notify_friend_request'],
            'notify_game_finished' => (bool) $row['notify_game_finished'],
            'notify_chat_message' => (bool) $row['notify_chat_message'],
            'disable_cooldown' => (bool) $row['disable_cooldown'],
        ];
    }

    public function save(
        int $userId,
        bool $notifyYourTurn,
        bool $notifyFriendRequest,
        bool $notifyGameFinished,
        bool $disableCooldown = false,
        bool $notifyChatMessage = true,
    ): void {
        $stmt = Connection::get()->prepare(
            'INSERT INTO notification_preferences (user_id, notify_your_turn, notify_friend_request, notify_game_finished, notify_chat_message, disable_cooldown)
             VALUES (:user_id, :your_turn, :friend_request, :game_finished, :chat_message, :disable_cooldown)
             ON DUPLICATE KEY UPDATE
                notify_your_turn = VALUES(notify_your_turn),
                notify_friend_request = VALUES(notify_friend_request),
                notify_game_finished = VALUES(notify_game_finished),
                notify_chat_message = VALUES(notify_chat_message),
                disable_cooldown = VALUES(disable_cooldown)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'your_turn' => $notifyYourTurn ? 1 : 0,
            'friend_request' => $notifyFriendRequest ? 1 : 0,
            'game_finished' => $notifyGameFinished ? 1 : 0,
            'chat_message' => $notifyChatMessage ? 1 : 0,
            'disable_cooldown' => $disableCooldown ? 1 : 0,
        ]);
    }
}
