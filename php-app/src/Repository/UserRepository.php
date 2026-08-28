<?php

declare(strict_types=1);

namespace MoodSwings\Repository;

use MoodSwings\Database\Connection;

final class UserRepository
{
    public function create(string $username, string $email, string $passwordHash, ?string $phoneNumber): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, phone_number, password_hash)
             VALUES (:username, :email, :phone_number, :password_hash)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'password_hash' => $passwordHash,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function markEmailVerified(int $id): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    /**
     * Online/presence indicator (issue #110) -- whether this user allows
     * their derived online/offline status to be shown to friends and
     * fellow game players at all. Defaults to true (share_presence = 1);
     * see PresenceService for how a shared status is actually computed.
     */
    public function setSharePresence(int $id, bool $sharePresence): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET share_presence = :share_presence WHERE id = :id');
        $stmt->execute(['share_presence' => $sharePresence ? 1 : 0, 'id' => $id]);
    }

    /**
     * "Default selections mode" as a personal preference (Settings
     * dialog's "Game defaults" section) -- this user's own default for
     * the New Game dialog's default-selections-mode checkbox, distinct
     * from games.default_selections_mode (issue #274), the actual
     * per-game setting. Defaults to false (unchecked); see migration
     * 0093.
     */
    public function setDefaultSelectionsModePreference(int $id, bool $defaultSelectionsModePreference): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE users SET default_selections_mode_preference = :default_selections_mode_preference WHERE id = :id'
        );
        $stmt->execute([
            'default_selections_mode_preference' => $defaultSelectionsModePreference ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * "Auto-pass on empty hand" as a personal preference (Settings
     * dialog's "Game defaults" section) -- see GameService::
     * advanceAutomatedTurns() for the server-side behavior this drives.
     * Defaults to true (on); see migration 0096.
     */
    public function setAutoPassOnEmptyHand(int $id, bool $autoPassOnEmptyHand): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET auto_pass_on_empty_hand = :auto_pass_on_empty_hand WHERE id = :id');
        $stmt->execute(['auto_pass_on_empty_hand' => $autoPassOnEmptyHand ? 1 : 0, 'id' => $id]);
    }

    /**
     * "Auto-apply scoring bonuses" (issue #397) as a personal preference
     * (Settings dialog's "Game defaults" section) -- see GameService::
     * advanceAutomatedTurns() for the server-side behavior this drives.
     * Defaults to true (on); see migration 0165.
     */
    public function setAutoApplyScoringBonuses(int $id, bool $autoApplyScoringBonuses): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET auto_apply_scoring_bonuses = :auto_apply_scoring_bonuses WHERE id = :id');
        $stmt->execute(['auto_apply_scoring_bonuses' => $autoApplyScoringBonuses ? 1 : 0, 'id' => $id]);
    }

    /**
     * "Board layout" (issue #417) as a personal preference (Settings
     * dialog's "Display" section) -- 'above_play_area' (default) or
     * 'below_hand', see applyBoardLayoutPreference() in game.js for what
     * each actually does. $boardLayoutPreference is validated against
     * those two values by the route handler (index.php) before this is
     * ever called -- the users.board_layout_preference column's own
     * ENUM(...) (migration 0174) would reject anything else anyway, but
     * failing at the route with a clean 400 is friendlier than a raw SQL
     * error. Defaults to 'above_play_area'; see migration 0174.
     */
    public function setBoardLayoutPreference(int $id, string $boardLayoutPreference): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET board_layout_preference = :board_layout_preference WHERE id = :id');
        $stmt->execute(['board_layout_preference' => $boardLayoutPreference, 'id' => $id]);
    }

    /**
     * "Custom card/effect formats" (issue #405 follow-up) as a personal
     * preference (Settings dialog's "Game defaults" section) -- gates
     * whether Chaos Draft's own fan-made 133-effect pool (and any future
     * deck_type built on similarly custom/non-canonical content) is even
     * offered to this user at all, in either direction: as a New Game
     * dialog option (GameService::isDeckTypeAvailableForFormat() on the
     * frontend) and as a seat GameService::createGame() will actually
     * accept them into (every seated player must have this on, not just
     * the creator -- see createGame()'s own chaos_draft validation).
     * Defaults to false (off) -- unlike auto_pass_on_empty_hand/
     * auto_apply_scoring_bonuses's own "on by default, pure convenience"
     * shape, this is an explicit opt-IN, the same default-off shape
     * default_selections_mode_preference already uses. See migration 0184.
     */
    public function setAllowCustomContent(int $id, bool $allowCustomContent): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET allow_custom_content = :allow_custom_content WHERE id = :id');
        $stmt->execute(['allow_custom_content' => $allowCustomContent ? 1 : 0, 'id' => $id]);
    }

    /**
     * "Discoverable for open games" (issue #116) as a personal preference
     * (Settings dialog's "Game defaults" section) -- opt-in gate for
     * whether this user's own open game listings (MatchmakingService)
     * are shown to strangers via the open lobby at all. Defaults to
     * false (off), the same "must opt in" shape allow_custom_content/
     * default_selections_mode_preference already use, since posting an
     * open listing exposes this user's username to anyone browsing the
     * lobby. Joining someone else's listing needs no such opt-in of its
     * own -- that's an explicit action the joiner already takes by
     * clicking join, not something done to them. See migration 0198.
     */
    public function setMatchmakingDiscoverable(int $id, bool $matchmakingDiscoverable): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET matchmaking_discoverable = :matchmaking_discoverable WHERE id = :id');
        $stmt->execute(['matchmaking_discoverable' => $matchmakingDiscoverable ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
