<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class DiscordAccountRepository
{
    /**
     * Upserts on user_id: reconnecting (e.g. after switching which Discord
     * account is linked) replaces the existing link in place rather than
     * failing on the primary key -- see migration 0050's own docblock on
     * why there's no separate access/refresh token to also update here.
     */
    public function link(int $userId, string $discordUserId, string $discordUsername): void
    {
        Connection::get()->prepare(
            'INSERT INTO discord_accounts (user_id, discord_user_id, discord_username)
             VALUES (:user_id, :discord_user_id, :discord_username)
             ON DUPLICATE KEY UPDATE
                discord_user_id = VALUES(discord_user_id),
                discord_username = VALUES(discord_username),
                linked_at = CURRENT_TIMESTAMP'
        )->execute([
            'user_id' => $userId,
            'discord_user_id' => $discordUserId,
            'discord_username' => $discordUsername,
        ]);
    }

    public function unlink(int $userId): void
    {
        Connection::get()->prepare('DELETE FROM discord_accounts WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    /** @return array{discord_user_id: string, discord_username: string}|null */
    public function findByUserId(int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT discord_user_id, discord_username FROM discord_accounts WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The reverse lookup -- given a Discord user id (e.g. from an
     * Interactions Endpoint payload's own member/user object), which
     * MoodSwings account, if any, it's linked to.
     */
    public function findUserIdByDiscordUserId(string $discordUserId): ?int
    {
        $stmt = Connection::get()->prepare(
            'SELECT user_id FROM discord_accounts WHERE discord_user_id = :discord_user_id'
        );
        $stmt->execute(['discord_user_id' => $discordUserId]);
        $userId = $stmt->fetchColumn();

        return $userId !== false ? (int) $userId : null;
    }
}
