<?php

declare(strict_types=1);

namespace MoodSwings\Achievements;

use MoodSwings\Database\Connection;
use MoodSwings\Notifications\NotificationService;

/**
 * Phase 1 of the achievements design doc (see the "MoodSwings-Web
 * Achievements -- Draft List" doc and its own "Implementation notes"
 * section for the full catalog and suggested build order). Covers every
 * achievement whose condition is knowable at game-completion time
 * (categories A-E, I) or tournament-completion time (F), plus the four
 * meta rows. The remaining account/social triggers (G, and H's non-game
 * rows) are wired from their own respective call sites -- see each
 * public on*() method's own docblock for where it's called from.
 *
 * Every achievement is looked up by its `slug` (achievements.slug, e.g.
 * 'first-steps'), never a numeric id, so call sites read the same way
 * the design doc's own tables do. Three generic primitives cover nearly
 * every row:
 *  - unlock(): a direct one-shot condition (target IS NULL, or a
 *    target-bearing row whose threshold is being hit right now).
 *  - bumpProgress(): a cumulative counter (e.g. "win 10 X games") --
 *    increments by $delta and auto-unlocks once target is reached.
 *  - setProgressLevel(): a high-water-mark level, not a per-event count
 *    (e.g. "have 10 accepted friends" can go up AND down as friends are
 *    removed -- this only ever raises progress, matching "you did reach
 *    10 at some point").
 *
 * No backfill from existing user_lifetime_stats: every counter starts at
 * zero from this deploy forward, same as user_lifetime_stats/card_stats
 * themselves were when they first shipped -- see the design doc's own
 * resolved comment thread on this.
 *
 * Known simplification: the D/E "final board" checks (majority color,
 * Rainbow Connection, Common Touch, David vs. Goliath) read the played
 * cards' PRINTED color/base_value/rarity straight off the `cards`
 * catalog via `game_cards.card_id`, not a live BoardState's *effective*
 * values -- so a Creativity copy, an Imagination color override, or a
 * chosen dice/alt value isn't reflected. Cheap and correct for the
 * overwhelming majority of boards; revisit with a real BoardState reload
 * at completion time if that ever matters enough to be worth the cost.
 */
final class AchievementService
{
    private const META_SLUGS = ['emotionally-balanced', 'spin-cycle', 'mood-ring', 'completionist'];

    private const COLOR_SYNONYM_SLUGS = ['good-samaritan', 'spiral-of-dread', 'inconsolable', 'hulk-smash', 'walking-on-sunshine'];

    private const CARD_CYCLE_SLUGS = ['vanilla-extract', 'birds-of-a-feather', 'bad-blood', 'encore', 'in-good-company', 'thats-gotta-hurt'];

    /** Catalog card ids (cards.id) for every deck-membership cluster (D's synonym rows + I's cycles), keyed by slug. */
    private const DECK_CLUSTERS = [
        'good-samaritan' => [1, 2, 3, 17],           // Altruism, Benevolence, Charity, Kindness
        'spiral-of-dread' => [28, 38, 52, 48, 46],    // Anxiety, Fear, Worry, Panic, Neurosis
        'inconsolable' => [65, 69, 70, 74],           // Grief, Melancholy, Misery, Sadness
        'hulk-smash' => [80, 91, 98, 105],            // Anger, Fury, Rage, Wrath
        'walking-on-sunshine' => [122, 125, 108, 111, 117], // Happiness, Joy, Bliss, Delight, Euphoria
        'vanilla-extract' => [5, 44, 55, 83, 126],    // Complacency, Indifference, Apathy, Boredom, Laziness
        'birds-of-a-feather' => [18, 47, 72, 88, 115], // Loyalty, Obsession, Pity, Excitement, Enjoyment
        'bad-blood' => [9, 27, 63, 90, 113],          // Discipline, Ambivalence, Disgust, Frustration, Disregard
        'encore' => [3, 38, 53, 84, 128],             // Charity, Fear, Ambition, Bravado, Nostalgia
        'in-good-company' => [12, 52, 54, 94, 122],   // Faith, Worry, Angst, Hostility, Happiness
        'thats-gotta-hurt' => [14, 41, 59, 82, 118],  // Guilt, Hesitation, Contempt, Arrogance, Fascination
    ];

    private const COLOR_MAJORITY_SLUGS = [
        'white' => 'white-knight',
        'blue' => 'true-blue',
        'black' => 'dark-arts',
        'red' => 'seeing-red',
        'green' => 'green-thumb',
    ];

    private const FORMAT_WIN_SLUGS = [
        'standard' => 'traditionalist',
        'duel' => 'duelist',
        'team' => 'team-spirit',
        'closed_team' => 'inner-circle',
        'draft' => 'drafted',
    ];

    private const DRAFT_DECK_TYPE_SLUGS = [
        'quick_draft' => 'quick-draw',
        'winston_draft' => 'pile-driver',
        'grid_draft' => 'grid-iron',
        'rotisserie_draft' => 'around-the-table',
        'tiered_rotisserie_draft' => 'tiered-up',
        'chaos_draft' => 'chaos-agent',
    ];

    private const TOTAL_MYTHIC_CARDS = 15;

    /** @var array<string, array{id:int, target:?int, title:string, tier:string}>|null */
    private ?array $bySlug = null;

    private ?int $totalAchievementCount = null;

    public function __construct(private readonly ?NotificationService $notifications = null)
    {
    }

    /**
     * GET /user/achievements' own data source: the full static catalog
     * (achievements) left-joined against this one viewer's own progress
     * (user_achievements) -- a user with no row yet for a given
     * achievement reads as progress 0/not unlocked, the same "lazily
     * created" convention the rest of this class writes under. A hidden
     * row (Mood Ring/Completionist -- spoiler-y meta achievements) has
     * its title/description redacted to a generic "???" placeholder
     * until the viewer actually earns it, so the list still hints at
     * something to find without spoiling what it is.
     *
     * @return array<string, array<int, array{slug:string, title:string, description:string, tier:string, target:?int, progress:int, unlocked_at:?string, hidden:bool}>> category letter => achievements in catalog order
     */
    public function catalogForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT a.slug, a.category, a.title, a.description, a.tier, a.target, a.hidden,
                    COALESCE(ua.progress, 0) AS progress, ua.unlocked_at
             FROM achievements a
             LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = :u
             ORDER BY a.category, a.id'
        );
        $stmt->execute(['u' => $userId]);

        $byCategory = [];
        foreach ($stmt->fetchAll() as $row) {
            $hidden = (bool) $row['hidden'];
            $unlocked = $row['unlocked_at'] !== null;
            $byCategory[$row['category']][] = [
                'slug' => $row['slug'],
                'title' => ($hidden && !$unlocked) ? '???' : $row['title'],
                'description' => ($hidden && !$unlocked) ? 'A hidden achievement -- keep playing to find out.' : $row['description'],
                'tier' => $row['tier'],
                'target' => $row['target'] !== null ? (int) $row['target'] : null,
                'progress' => (int) $row['progress'],
                'unlocked_at' => $row['unlocked_at'],
                'hidden' => $hidden,
            ];
        }

        return $byCategory;
    }

    // ---------------------------------------------------------------
    // Generic primitives
    // ---------------------------------------------------------------

    /** @return bool true only if this call is what newly unlocked it */
    public function unlock(int $userId, string $slug): bool
    {
        $meta = $this->meta($slug);
        $pdo = Connection::get();

        $existing = $pdo->prepare('SELECT unlocked_at FROM user_achievements WHERE user_id = :u AND achievement_id = :a');
        $existing->execute(['u' => $userId, 'a' => $meta['id']]);
        $row = $existing->fetch();
        if ($row !== false && $row['unlocked_at'] !== null) {
            return false;
        }

        $progress = $meta['target'] ?? 1;
        $stmt = $pdo->prepare(
            'INSERT INTO user_achievements (user_id, achievement_id, progress, unlocked_at)
             VALUES (:u, :a, :p, NOW())
             ON DUPLICATE KEY UPDATE progress = GREATEST(progress, :p2), unlocked_at = COALESCE(unlocked_at, NOW())'
        );
        $stmt->execute(['u' => $userId, 'a' => $meta['id'], 'p' => $progress, 'p2' => $progress]);

        $this->onUnlocked($userId, $slug);

        return true;
    }

    /** @return bool true only if this call is what newly crossed the target and unlocked it */
    public function bumpProgress(int $userId, string $slug, int $delta = 1): bool
    {
        $meta = $this->meta($slug);
        if ($meta['target'] === null) {
            throw new \LogicException("bumpProgress() requires a target; '{$slug}' has none -- use unlock() instead");
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO user_achievements (user_id, achievement_id, progress)
             VALUES (:u, :a, :d)
             ON DUPLICATE KEY UPDATE progress = IF(unlocked_at IS NULL, progress + :d2, progress)'
        );
        $stmt->execute(['u' => $userId, 'a' => $meta['id'], 'd' => $delta, 'd2' => $delta]);

        $check = $pdo->prepare('SELECT progress FROM user_achievements WHERE user_id = :u AND achievement_id = :a AND unlocked_at IS NULL');
        $check->execute(['u' => $userId, 'a' => $meta['id']]);
        $row = $check->fetch();
        if ($row !== false && (int) $row['progress'] >= $meta['target']) {
            return $this->unlock($userId, $slug);
        }

        return false;
    }

    /** A high-water-mark level (can be reported repeatedly, only ever raises progress) rather than a per-event count. */
    public function setProgressLevel(int $userId, string $slug, int $value): bool
    {
        $meta = $this->meta($slug);
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO user_achievements (user_id, achievement_id, progress)
             VALUES (:u, :a, :v)
             ON DUPLICATE KEY UPDATE progress = IF(unlocked_at IS NULL, GREATEST(progress, :v2), progress)'
        );
        $stmt->execute(['u' => $userId, 'a' => $meta['id'], 'v' => $value, 'v2' => $value]);

        if ($meta['target'] !== null && $value >= $meta['target']) {
            return $this->unlock($userId, $slug);
        }

        return false;
    }

    private function onUnlocked(int $userId, string $slug): void
    {
        $meta = $this->meta($slug);
        $this->notifications?->notifyAchievementUnlocked($userId, $slug, $meta['title'], $meta['tier']);

        if (!in_array($slug, self::META_SLUGS, true)) {
            $this->evaluateMeta($userId);
        }
    }

    // ---------------------------------------------------------------
    // Meta achievements
    // ---------------------------------------------------------------

    private function evaluateMeta(int $userId): void
    {
        $unlocked = $this->unlockedSlugsWithCategories($userId);
        $bonus = 0;

        if (!isset($unlocked['emotionally-balanced']) && $this->hasAll($unlocked, self::COLOR_SYNONYM_SLUGS)) {
            if ($this->unlock($userId, 'emotionally-balanced')) {
                $bonus++;
            }
        }

        if (!isset($unlocked['spin-cycle']) && $this->hasAll($unlocked, self::CARD_CYCLE_SLUGS)) {
            if ($this->unlock($userId, 'spin-cycle')) {
                $bonus++;
            }
        }

        if (!isset($unlocked['mood-ring'])) {
            $categoriesPresent = array_unique(array_values($unlocked));
            $others = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'I'];
            if (count(array_intersect($others, $categoriesPresent)) === count($others)) {
                if ($this->unlock($userId, 'mood-ring')) {
                    $bonus++;
                }
            }
        }

        if (!isset($unlocked['completionist'])) {
            $totalOthers = $this->totalAchievementCount() - 1;
            if ((count($unlocked) + $bonus) >= $totalOthers) {
                $this->unlock($userId, 'completionist');
            }
        }
    }

    /** @param array<string, string> $unlocked slug => category */
    private function hasAll(array $unlocked, array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (!isset($unlocked[$slug])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> slug => category, every achievement this user has already unlocked */
    private function unlockedSlugsWithCategories(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT a.slug, a.category FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
             WHERE ua.user_id = :u AND ua.unlocked_at IS NOT NULL'
        );
        $stmt->execute(['u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['slug']] = $row['category'];
        }

        return $out;
    }

    private function totalAchievementCount(): int
    {
        if ($this->totalAchievementCount === null) {
            $this->totalAchievementCount = (int) Connection::get()->query('SELECT COUNT(*) FROM achievements')->fetchColumn();
        }

        return $this->totalAchievementCount;
    }

    // ---------------------------------------------------------------
    // Game completion (categories A, B, C, D, E, I)
    // ---------------------------------------------------------------

    /**
     * Called once from GameService::recordGameCompletionStats(), right
     * after it bumps user_lifetime_stats -- same call site, same
     * "completed games are deleted after 7 days" reasoning for why
     * everything here has to be recorded incrementally right now rather
     * than computed later.
     *
     * @param array<string, mixed> $game the full `games` row
     * @param int[] $winningUserIds
     * @param int[] $losingUserIds
     */
    public function onGameCompleted(int $gameId, array $game, array $winningUserIds, array $losingUserIds, bool $containsBot): void
    {
        $pdo = Connection::get();
        $gpStmt = $pdo->prepare('SELECT id, user_id FROM game_players WHERE game_id = :g');
        $gpStmt->execute(['g' => $gameId]);
        $gamePlayerIdByUserId = [];
        foreach ($gpStmt->fetchAll() as $row) {
            $gamePlayerIdByUserId[(int) $row['user_id']] = (int) $row['id'];
        }

        // Bot-practice achievements are gated the OPPOSITE way from
        // everything else below (they specifically WANT a bot opponent),
        // so they're checked first and independently.
        if ($containsBot) {
            foreach ($winningUserIds as $userId) {
                $this->bumpProgress($userId, 'practice-makes-perfect');
                if ((bool) $game['diagnostic_mode']) {
                    $this->unlock($userId, 'diagnostic-detective');
                }
            }

            return;
        }

        // Chaos Draft is excluded the same way user_lifetime_stats/
        // card_stats already exclude it (its per-round random effect
        // attachments mean a card's own play pattern no longer reflects
        // its printed ability -- see recordGameCompletionStats()'s own
        // docblock).
        if ($game['deck_type'] === 'chaos_draft') {
            return;
        }

        foreach ($losingUserIds as $userId) {
            $this->bumpProgress($userId, 'good-sport');
        }

        // Win-or-lose completion counters -- every seated user, not just
        // the winner(s).
        $allUserIds = [...$winningUserIds, ...$losingUserIds];
        foreach ($allUserIds as $userId) {
            $this->unlock($userId, 'foot-in-the-door');
            $this->bumpProgress($userId, 'regular');
            $this->bumpProgress($userId, 'no-days-off');
            $this->checkFormatPurist($userId, (string) $game['format']);
            $this->checkMarathonSession($userId);
        }
        $this->checkRematch($allUserIds);

        foreach ($winningUserIds as $userId) {
            $this->checkVolumeAndFormatWins($userId, $game);

            $gamePlayerId = $gamePlayerIdByUserId[$userId] ?? null;
            if ($gamePlayerId === null) {
                continue;
            }

            $this->checkColorAndCardFeats($userId, $gameId, $gamePlayerId, $game);
            $this->checkInGameSkillFeats($userId, $gameId, $gamePlayerId, $game, $gamePlayerIdByUserId);
        }
    }

    private function checkFormatPurist(int $userId, string $format): void
    {
        $pdo = Connection::get();
        $pdo->prepare(
            'INSERT INTO user_format_play_counts (user_id, format, games_played) VALUES (:u, :f, 1)
             ON DUPLICATE KEY UPDATE games_played = games_played + 1'
        )->execute(['u' => $userId, 'f' => $format]);

        $stmt = $pdo->prepare('SELECT MAX(games_played) FROM user_format_play_counts WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        if ((int) $stmt->fetchColumn() >= 100) {
            $this->unlock($userId, 'format-purist');
        }
    }

    private function checkMarathonSession(int $userId): void
    {
        $pdo = Connection::get();
        $pdo->prepare(
            'INSERT INTO user_daily_game_counts (user_id, play_date, games_played) VALUES (:u, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE games_played = games_played + 1'
        )->execute(['u' => $userId]);

        $stmt = $pdo->prepare('SELECT games_played FROM user_daily_game_counts WHERE user_id = :u AND play_date = CURDATE()');
        $stmt->execute(['u' => $userId]);
        $this->setProgressLevel($userId, 'marathon-session', (int) $stmt->fetchColumn());
    }

    /** @param int[] $allUserIds every user seated in the just-completed game */
    private function checkRematch(array $allUserIds): void
    {
        if (count($allUserIds) < 2) {
            return;
        }

        $pdo = Connection::get();
        $insert = $pdo->prepare(
            'INSERT INTO user_opponent_game_counts (user_id, opponent_user_id, games_played) VALUES (:u, :o, 1)
             ON DUPLICATE KEY UPDATE games_played = games_played + 1'
        );
        $maxStmt = $pdo->prepare('SELECT MAX(games_played) FROM user_opponent_game_counts WHERE user_id = :u');

        foreach ($allUserIds as $userId) {
            foreach ($allUserIds as $opponentUserId) {
                if ($opponentUserId !== $userId) {
                    $insert->execute(['u' => $userId, 'o' => $opponentUserId]);
                }
            }
            $maxStmt->execute(['u' => $userId]);
            $this->setProgressLevel($userId, 'rematch', (int) $maxStmt->fetchColumn());
        }
    }

    private function checkVolumeAndFormatWins(int $userId, array $game): void
    {
        $this->unlock($userId, 'first-steps');
        $this->bumpProgress($userId, 'getting-the-hang-of-it');
        $this->bumpProgress($userId, 'seasoned-player');
        $this->bumpProgress($userId, 'veteran');
        $this->bumpProgress($userId, 'legend');

        $formatSlug = self::FORMAT_WIN_SLUGS[$game['format']] ?? null;
        if ($formatSlug !== null) {
            $this->bumpProgress($userId, $formatSlug);
        }
        $formatProgress = $this->progressOf($userId, array_values(self::FORMAT_WIN_SLUGS));
        if ($this->allAtLeast($formatProgress, 1)) {
            $this->unlock($userId, 'jack-of-all-formats');
        }
        if ($this->allAtLeast($formatProgress, 10)) {
            $this->unlock($userId, 'renaissance-player');
        }

        $draftSlug = self::DRAFT_DECK_TYPE_SLUGS[$game['deck_type']] ?? null;
        if ($draftSlug !== null) {
            $this->bumpProgress($userId, $draftSlug);
            $draftProgress = $this->progressOf($userId, array_values(self::DRAFT_DECK_TYPE_SLUGS));
            if ($this->allAtLeast($draftProgress, 1)) {
                $this->unlock($userId, 'draft-completionist');
            }
        }

        if ($game['deck_type'] === 'structure') {
            $this->bumpProgress($userId, 'structure-purist');
        } elseif ($game['deck_type'] === 'jceddys_75') {
            $this->bumpProgress($userId, 'jceddy-stan');
        } elseif (in_array($game['deck_type'], ['custom', 'custom_duel'], true)) {
            $this->bumpProgress($userId, 'homebrewer');
        } elseif ($game['deck_type'] === 'sealed_deck') {
            $this->bumpProgress($userId, 'sealed-with-a-kiss');
        } elseif ($game['deck_type'] === 'sealed_pool_of_the_day') {
            $this->bumpProgress($userId, 'pool-party');
        }

        if ((bool) $game['default_selections_mode']) {
            $this->bumpProgress($userId, 'default-setting');
        }
        if ($game['timeout_minutes'] !== null) {
            $this->unlock($userId, 'against-the-clock');
        }
        if ($game['total_time_limit_minutes'] !== null) {
            $this->unlock($userId, 'clockwork');
        }

        $completedAt = $game['completed_at'] ?? null;
        if (is_string($completedAt)) {
            $hour = (int) (new \DateTimeImmutable($completedAt))->format('G');
            if ($hour >= 0 && $hour < 4) {
                $this->unlock($userId, 'night-owl');
            } elseif ($hour >= 5 && $hour < 7) {
                $this->unlock($userId, 'early-bird');
            }
        }
    }

    private function checkColorAndCardFeats(int $userId, int $gameId, int $gamePlayerId, array $game): void
    {
        $pdo = Connection::get();

        $boardStmt = $pdo->prepare(
            "SELECT c.color, c.base_value, c.rarity FROM game_cards gc JOIN cards c ON c.id = gc.card_id
             WHERE gc.game_id = :g AND gc.owner_game_player_id = :p AND gc.zone = 'in_play'"
        );
        $boardStmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);
        $board = $boardStmt->fetchAll();

        if ($board !== []) {
            $colorCounts = ['white' => 0, 'blue' => 0, 'black' => 0, 'red' => 0, 'green' => 0];
            $onlyCommonUncommon = true;
            foreach ($board as $row) {
                $colorCounts[$row['color']]++;
                if (!in_array($row['rarity'], ['common', 'uncommon'], true)) {
                    $onlyCommonUncommon = false;
                }
            }

            if (count(array_filter($colorCounts)) === 5) {
                $this->unlock($userId, 'rainbow-connection');
            }
            if ($onlyCommonUncommon) {
                $this->unlock($userId, 'common-touch');
            }

            arsort($colorCounts);
            $topColor = array_key_first($colorCounts);
            $topCount = $colorCounts[$topColor];
            if ($topCount > count($board) / 2) {
                $this->bumpProgress($userId, self::COLOR_MAJORITY_SLUGS[$topColor]);
                $colorProgress = $this->progressOf($userId, array_values(self::COLOR_MAJORITY_SLUGS));
                if ($this->allAtLeast($colorProgress, 10)) {
                    $this->unlock($userId, 'color-wheel');
                }
            }
        }

        // Deck-membership synonym/card-cycle clusters -- non-Traditional
        // formats only (Traditional's shared/preset deck leaves no room
        // for the deliberate choice these are meant to reward).
        if ($game['format'] !== 'standard') {
            $deckStmt = $pdo->prepare('SELECT DISTINCT card_id FROM game_cards WHERE game_id = :g AND owner_game_player_id = :p');
            $deckStmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);
            $deckSet = array_flip(array_map('intval', array_column($deckStmt->fetchAll(), 'card_id')));

            foreach (self::DECK_CLUSTERS as $slug => $catalogIds) {
                $hasAll = true;
                foreach ($catalogIds as $catalogId) {
                    if (!isset($deckSet[$catalogId])) {
                        $hasAll = false;
                        break;
                    }
                }
                if ($hasAll) {
                    $this->unlock($userId, $slug);
                }
            }
        }

        // Mythic Hunter (cumulative count played) / Rarity Collector
        // (distinct set played, ever) / The Copycat (Creativity copied a
        // Mythic) -- all from this game's own played-mythic mood_played
        // events, joined to the catalog for rarity.
        $mythicStmt = $pdo->prepare(
            "SELECT DISTINCT c.id FROM game_events ge JOIN game_cards gc ON gc.id = ge.card_id JOIN cards c ON c.id = gc.card_id
             WHERE ge.game_id = :g AND ge.event_type = 'mood_played' AND ge.acting_game_player_id = :p AND c.rarity = 'mythic'"
        );
        $mythicStmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);
        $mythicIds = array_map('intval', array_column($mythicStmt->fetchAll(), 'id'));
        if ($mythicIds !== []) {
            $this->bumpProgress($userId, 'mythic-hunter', count($mythicIds));

            $insert = $pdo->prepare('INSERT IGNORE INTO user_played_mythic_cards (user_id, catalog_card_id) VALUES (:u, :c)');
            foreach ($mythicIds as $catalogId) {
                $insert->execute(['u' => $userId, 'c' => $catalogId]);
            }
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_played_mythic_cards WHERE user_id = :u');
            $countStmt->execute(['u' => $userId]);
            if ((int) $countStmt->fetchColumn() >= self::TOTAL_MYTHIC_CARDS) {
                $this->unlock($userId, 'rarity-collector');
            }
        }

        $creativityCopiedMythicStmt = $pdo->prepare(
            "SELECT 1 FROM game_events ge
             JOIN game_cards creativity ON creativity.id = ge.card_id
             JOIN game_cards copied ON copied.id = creativity.copied_card_id
             JOIN cards copiedCatalog ON copiedCatalog.id = copied.card_id
             WHERE ge.game_id = :g AND ge.event_type = 'mood_played' AND ge.acting_game_player_id = :p
               AND copiedCatalog.rarity = 'mythic' LIMIT 1"
        );
        $creativityCopiedMythicStmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);
        if ($creativityCopiedMythicStmt->fetch() !== false) {
            $this->unlock($userId, 'the-copycat');
        }

        // Simple "was this specific card played" checks, reused for a
        // handful of single-card feats whose mandatory effect makes the
        // card's own play a reasonable stand-in for the fuller described
        // condition (Betrayal's reclaim, Sneakiness's swap are both
        // unconditional parts of playing those cards).
        $playedCatalogIds = $this->catalogIdsPlayedBy($gameId, $gamePlayerId);
        if (in_array(100, $playedCatalogIds, true)) { // Recklessness
            $this->unlock($userId, 'reckless-abandon');
        }
        if (in_array(56, $playedCatalogIds, true)) { // Betrayal
            $this->unlock($userId, 'betrayer');
        }
        if (in_array(51, $playedCatalogIds, true)) { // Sneakiness
            $this->unlock($userId, 'sneak-attack');
        }
    }

    /** @return int[] catalog card ids this game_player played (mood_played) this game */
    private function catalogIdsPlayedBy(int $gameId, int $gamePlayerId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT DISTINCT gc.card_id FROM game_events ge JOIN game_cards gc ON gc.id = ge.card_id
             WHERE ge.game_id = :g AND ge.event_type = 'mood_played' AND ge.acting_game_player_id = :p"
        );
        $stmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'card_id'));
    }

    /** @param array<int, int> $gamePlayerIdByUserId */
    private function checkInGameSkillFeats(int $userId, int $gameId, int $gamePlayerId, array $game, array $gamePlayerIdByUserId): void
    {
        $pdo = Connection::get();

        $roundsStmt = $pdo->prepare('SELECT * FROM game_rounds WHERE game_id = :g ORDER BY round_number');
        $roundsStmt->execute(['g' => $gameId]);
        $rounds = $roundsStmt->fetchAll();
        if ($rounds === []) {
            return;
        }

        $wonEveryRound = true;
        $playedAnyRound = false;
        foreach ($rounds as $round) {
            $wonThisRound = (int) $round['winner_game_player_id'] === $gamePlayerId
                || ($round['winner_team_id'] !== null && $this->sameTeam($gameId, $gamePlayerId, (int) $round['winner_team_id']));

            $scoreStmt = $pdo->prepare('SELECT score FROM game_round_scores WHERE game_round_id = :r AND game_player_id = :p');
            $scoreStmt->execute(['r' => $round['id'], 'p' => $gamePlayerId]);
            $myScore = $scoreStmt->fetchColumn();
            if ($myScore === false) {
                continue;
            }
            $playedAnyRound = true;
            $myScore = (int) $myScore;

            if ($myScore >= 30) {
                $this->unlock($userId, 'high-roller');
            }
            if ($myScore >= 45) {
                $this->unlock($userId, 'point-explosion');
            }

            if ($wonThisRound) {
                $othersStmt = $pdo->prepare('SELECT MAX(score) FROM game_round_scores WHERE game_round_id = :r AND game_player_id != :p');
                $othersStmt->execute(['r' => $round['id'], 'p' => $gamePlayerId]);
                $nextHighest = $othersStmt->fetchColumn();
                if ($nextHighest !== false && $myScore - (int) $nextHighest === 1) {
                    $this->unlock($userId, 'nail-biter');
                }
            } else {
                $wonEveryRound = false;
            }

            if ((int) ($round['hurt_feelings_game_player_id'] ?? 0) === $gamePlayerId && $wonThisRound) {
                $this->unlock($userId, 'hurt-feelings-survivor');
            }

            if ((bool) $round['awards_extra_win'] && (int) $round['awards_extra_win_owner_game_player_id'] === $gamePlayerId && $wonThisRound) {
                $this->unlock($userId, 'corruption-incarnate');
            }

            if ((bool) $round['skip_scoring'] && (int) ($round['skip_scoring_owner_game_player_id'] ?? 0) === $gamePlayerId) {
                $sourceCardId = $round['skip_scoring_source_card_id'] ?? null;
                if ($sourceCardId !== null && $this->cardEffectKey((int) $sourceCardId) === 'awe') {
                    $this->unlock($userId, 'awe-some');
                }
            }
        }

        if ($playedAnyRound && $wonEveryRound) {
            $this->unlock($userId, 'perfect-round-record');
        }
        if (count($rounds) >= 10) {
            $this->unlock($userId, 'the-long-game');
        }
        if (count($rounds) <= 3) {
            $this->unlock($userId, 'speedrun');
        }

        // David vs. Goliath -- 2-player games only (a clean "your total
        // vs. their total" comparison doesn't generalize cleanly to team
        // formats with this simplified printed-base-value approximation).
        if (count($gamePlayerIdByUserId) === 2) {
            $myTotal = $this->finalBoardBaseValue($gameId, $gamePlayerId);
            $opponentGamePlayerId = null;
            foreach ($gamePlayerIdByUserId as $otherGamePlayerId) {
                if ($otherGamePlayerId !== $gamePlayerId) {
                    $opponentGamePlayerId = $otherGamePlayerId;
                }
            }
            if ($opponentGamePlayerId !== null) {
                $opponentTotal = $this->finalBoardBaseValue($gameId, $opponentGamePlayerId);
                if ($myTotal < $opponentTotal) {
                    $this->unlock($userId, 'david-vs-goliath');
                }
            }
        }
    }

    private function finalBoardBaseValue(int $gameId, int $gamePlayerId): int
    {
        $stmt = Connection::get()->prepare(
            "SELECT COALESCE(SUM(c.base_value), 0) FROM game_cards gc JOIN cards c ON c.id = gc.card_id
             WHERE gc.game_id = :g AND gc.owner_game_player_id = :p AND gc.zone = 'in_play'"
        );
        $stmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);

        return (int) $stmt->fetchColumn();
    }

    private function sameTeam(int $gameId, int $gamePlayerId, int $teamId): bool
    {
        $stmt = Connection::get()->prepare('SELECT team_id FROM game_players WHERE game_id = :g AND id = :p');
        $stmt->execute(['g' => $gameId, 'p' => $gamePlayerId]);

        return (int) $stmt->fetchColumn() === $teamId;
    }

    private function cardEffectKey(int $gameCardInstanceId): ?string
    {
        $stmt = Connection::get()->prepare(
            'SELECT c.effect_key FROM game_cards gc JOIN cards c ON c.id = gc.card_id WHERE gc.id = :id'
        );
        $stmt->execute(['id' => $gameCardInstanceId]);
        $key = $stmt->fetchColumn();

        return $key !== false ? (string) $key : null;
    }

    /** @param string[] $slugs @return array<string, int> */
    private function progressOf(int $userId, array $slugs): array
    {
        $ids = array_map(fn (string $s): int => $this->meta($s)['id'], $slugs);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT achievement_id, progress FROM user_achievements WHERE user_id = ? AND achievement_id IN ({$placeholders})"
        );
        $stmt->execute([$userId, ...$ids]);

        $out = array_fill_keys($slugs, 0);
        $slugById = [];
        foreach ($slugs as $slug) {
            $slugById[$this->meta($slug)['id']] = $slug;
        }
        foreach ($stmt->fetchAll() as $row) {
            $slug = $slugById[(int) $row['achievement_id']] ?? null;
            if ($slug !== null) {
                $out[$slug] = (int) $row['progress'];
            }
        }

        return $out;
    }

    /** @param array<string, int> $progress */
    private function allAtLeast(array $progress, int $min): bool
    {
        foreach ($progress as $p) {
            if ($p < $min) {
                return false;
            }
        }

        return true;
    }

    /**
     * Called from GameService::advanceGameMatch() the moment a
     * best-of-three game_matches row itself completes (not each
     * individual game within it -- Match Point/Match Maker/Grand
     * Champion/Comeback Kid/Flawless Victory are all about the OVERALL
     * match, not any one game in it).
     */
    public function onBestOfThreeMatchCompleted(int $gameMatchId, bool $isTeamFormat): void
    {
        $pdo = Connection::get();

        $botStmt = $pdo->prepare(
            'SELECT 1 FROM game_players gp JOIN games g ON g.id = gp.game_id JOIN users u ON u.id = gp.user_id
             WHERE g.game_match_id = :m AND u.is_bot = 1 LIMIT 1'
        );
        $botStmt->execute(['m' => $gameMatchId]);
        if ($botStmt->fetch() !== false) {
            return;
        }

        $gamesStmt = $pdo->prepare(
            'SELECT id, match_game_number, winner_game_player_id, winner_team_id FROM games
             WHERE game_match_id = :m AND status = :status ORDER BY match_game_number'
        );
        $gamesStmt->execute(['m' => $gameMatchId, 'status' => 'completed']);
        $games = $gamesStmt->fetchAll();
        if ($games === []) {
            return;
        }

        $matchStmt = $pdo->prepare('SELECT winner_user_id FROM game_matches WHERE id = :id');
        $matchStmt->execute(['id' => $gameMatchId]);
        $winnerUserId = (int) $matchStmt->fetchColumn();
        if ($winnerUserId === 0) {
            return;
        }

        $winnerGamePlayerIdByGameId = [];
        foreach ($games as $g) {
            $stmt = $pdo->prepare('SELECT id FROM game_players WHERE game_id = :g AND user_id = :u');
            $stmt->execute(['g' => $g['id'], 'u' => $winnerUserId]);
            $winnerGamePlayerIdByGameId[$g['id']] = (int) $stmt->fetchColumn();
        }

        $wonGame1 = null;
        $lostAnyGame = false;
        foreach ($games as $g) {
            $winnerGamePlayerId = $winnerGamePlayerIdByGameId[$g['id']];
            $wonThisGame = $isTeamFormat
                ? $this->sameTeam($g['id'], $winnerGamePlayerId, (int) $g['winner_team_id'])
                : (int) $g['winner_game_player_id'] === $winnerGamePlayerId;
            if ((int) $g['match_game_number'] === 1) {
                $wonGame1 = $wonThisGame;
            }
            if (!$wonThisGame) {
                $lostAnyGame = true;
            }
        }

        $this->unlock($winnerUserId, 'match-point');
        $this->bumpProgress($winnerUserId, 'match-maker');
        $this->bumpProgress($winnerUserId, 'grand-champion');
        if ($wonGame1 === false) {
            $this->unlock($winnerUserId, 'comeback-kid');
        }
        if (!$lostAnyGame) {
            $this->unlock($winnerUserId, 'flawless-victory');
        }
    }

    // ---------------------------------------------------------------
    // Tournament completion (category F)
    // ---------------------------------------------------------------

    /**
     * Called from TournamentService::finishTournament().
     *
     * @param array<string, mixed> $tournament the full `tournaments` row (match_params already json_decode()d)
     */
    public function onTournamentCompleted(array $tournament): void
    {
        $userId = (int) $tournament['winner_user_id'];
        $this->unlock($userId, 'tournament-champion');
        $this->bumpProgress($userId, 'grand-slam');

        $bracketSlug = match ($tournament['bracket_type']) {
            'swiss' => 'swiss-movement',
            'double_elimination' => 'double-or-nothing',
            'single_elimination' => 'single-minded',
            default => null,
        };
        if ($bracketSlug !== null) {
            $this->unlock($userId, $bracketSlug);
        }

        if (($tournament['match_params']['deck_type'] ?? null) === 'booster_draft') {
            $this->unlock($userId, 'booster-buster');
        }
        if ($tournament['registration_mode'] === 'open') {
            $this->unlock($userId, 'open-door-policy');
        }
    }

    /** Called from TournamentService when a participant joins (status becomes 'joined'). */
    public function onTournamentJoined(int $userId): void
    {
        $this->unlock($userId, 'bracketology');
    }

    /** Called from TournamentService::startTournament() once the roster is locked in. */
    public function onTournamentStarted(int $creatorUserId, int $joinedParticipantCount): void
    {
        if ($joinedParticipantCount >= 8) {
            $this->unlock($creatorUserId, 'host-with-the-most');
        }
    }

    // ---------------------------------------------------------------
    // Account / social (category G, and H's non-game rows)
    // ---------------------------------------------------------------

    /** Called from FriendService when a request is accepted, for BOTH sides. */
    public function onFriendAdded(int $userId, int $acceptedFriendCount): void
    {
        $this->unlock($userId, 'making-friends');
        $this->setProgressLevel($userId, 'social-butterfly', $acceptedFriendCount);
    }

    /** Called from UserDecklistService::save() for a brand-new decklist. */
    public function onDecklistSaved(int $userId, int $savedDecklistCount): void
    {
        $this->setProgressLevel($userId, 'deck-curator', $savedDecklistCount);
    }

    /** Called from UserDecklistService::update() for an existing decklist. */
    public function onDecklistEdited(int $userId): void
    {
        $this->unlock($userId, 'deck-doctor');
    }

    /** Called wherever a saved decklist is shared with a friend. */
    public function onDecklistShared(int $userId): void
    {
        $this->unlock($userId, 'sharing-is-caring');
    }

    public function onGameSpectated(int $userId): void
    {
        $this->unlock($userId, 'spectator-sport');
    }

    public function onDiscordLinked(int $userId): void
    {
        $this->unlock($userId, 'discord-connected');
    }

    public function onReplayImported(int $userId): void
    {
        $this->unlock($userId, 'replay-enthusiast');
    }

    public function onBotGameCreated(int $userId): void
    {
        $this->unlock($userId, 'bot-wrangler');
    }

    public function onCardStatsPageViewed(int $userId): void
    {
        $this->unlock($userId, 'card-counter');
    }

    // ---------------------------------------------------------------
    // Lookup
    // ---------------------------------------------------------------

    /** @return array{id:int, target:?int, title:string, tier:string} */
    private function meta(string $slug): array
    {
        $this->bySlug ??= $this->loadAll();

        return $this->bySlug[$slug] ?? throw new \RuntimeException("Unknown achievement slug '{$slug}'");
    }

    /** @return array<string, array{id:int, target:?int, title:string, tier:string}> */
    private function loadAll(): array
    {
        $stmt = Connection::get()->query('SELECT id, slug, target, title, tier FROM achievements');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['slug']] = [
                'id' => (int) $row['id'],
                'target' => $row['target'] !== null ? (int) $row['target'] : null,
                'title' => $row['title'],
                'tier' => $row['tier'],
            ];
        }

        return $out;
    }
}
