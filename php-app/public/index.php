<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Auth\AuthService;
use MoodSwings\Auth\DuplicateEmailException;
use MoodSwings\Auth\DuplicateUsernameException;
use MoodSwings\Auth\EmailNotVerifiedException;
use MoodSwings\Auth\InvalidCredentialsException;
use MoodSwings\Auth\InvalidVerificationTokenException;
use MoodSwings\Config;
use MoodSwings\Database\Connection;
use MoodSwings\Deck\DecklistNotFoundException;
use MoodSwings\Deck\DecklistValidationException;
use MoodSwings\Deck\NotAuthorizedToAccessDecklistException;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Friends\CannotFriendSelfException;
use MoodSwings\Friends\FriendshipAlreadyExistsException;
use MoodSwings\Friends\FriendshipNotFoundException;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Friends\NotAuthorizedToRespondException;
use MoodSwings\Friends\UserNotFoundException;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Mail\Mailer;
use MoodSwings\Maintenance\MaintenanceGate;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\EffectNotImplementedException;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;

header('Content-Type: application/json');

// __route is set by public/.htaccess when the app is deployed under a
// subfolder (e.g. /app on shared hosting), so routing works regardless of
// where the front controller is mounted.
$path = isset($_GET['__route'])
    ? '/' . ltrim($_GET['__route'], '/')
    : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requestBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

/**
 * Only used by /verify-email: unlike every other route, that one is meant
 * to be opened directly from an emailed link by a human, not called by our
 * own JS, so it renders a page instead of JSON. $redirectTo is an absolute
 * site path (e.g. "/"); when set, the page redirects there automatically.
 */
function respondHtml(int $status, string $title, string $heading, string $message, ?string $redirectTo = null): never
{
    header('Content-Type: text/html; charset=utf-8', true);
    http_response_code($status);

    $redirectMeta = $redirectTo !== null
        ? sprintf('<meta http-equiv="refresh" content="5;url=%s">', htmlspecialchars($redirectTo, ENT_QUOTES))
        : '';
    $link = $redirectTo !== null
        ? sprintf('<p><a href="%s">Continue to login</a></p>', htmlspecialchars($redirectTo, ENT_QUOTES))
        : '<p><a href="/">Back to login</a></p>';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        . '<link rel="stylesheet" href="/css/style.css">'
        . $redirectMeta
        . '</head><body><main>'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . $link
        . '</main></body></html>';
    exit;
}

function publicUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'phone_number' => $user['phone_number'],
    ];
}

function setSessionCookie(string $token, DateTimeImmutable $expiresAt): void
{
    setcookie(AuthService::COOKIE_NAME, $token, [
        'expires' => $expiresAt->getTimestamp(),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookie(): void
{
    setcookie(AuthService::COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Reads the session cookie, responding 401 if there's no valid session;
 * otherwise refreshes the cookie's expiry (matching /me's behavior) and
 * returns the current user.
 */
function requireAuth(AuthService $auth): array
{
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;
    $result = $token !== null ? $auth->currentUser($token) : null;

    if ($result === null) {
        respond(401, ['status' => 'error', 'message' => 'Not authenticated']);
    }

    setSessionCookie($token, $result['expiresAt']);

    return $result['user'];
}

/**
 * @throws \Throwable if the email fails to send
 */
function sendVerificationEmail(array $user, string $token): void
{
    $verificationUrl = rtrim(Config::get('APP_URL', ''), '/') . '/verify-email?token=' . urlencode($token);

    (new Mailer())->sendVerificationEmail($user['email'], $user['username'], $verificationUrl);
}

/**
 * Writes to a fixed, non-web-accessible file (src/ already has a
 * deny-all .htaccess) rather than PHP's ambient error_log destination,
 * which varies by host and isn't always what cPanel's error log UI shows.
 * Includes the resolved (non-secret) SMTP host/port/encryption so a
 * misconfigured or unset value is visible without checking GitHub secrets.
 */
function logMailError(string $message): void
{
    $config = sprintf(
        'host=%s port=%s encryption=%s',
        Config::get('SMTP_HOST', '') ?: '(empty)',
        Config::get('SMTP_PORT', '587'),
        Config::get('SMTP_ENCRYPTION', 'tls') ?: '(none)'
    );
    $line = '[' . date('Y-m-d H:i:s') . "] {$message} [{$config}]\n";
    error_log($line, 3, dirname(__DIR__) . '/src/mail-errors.log');
}

if ($path === '/health' && $method === 'GET') {
    try {
        Connection::get()->query('SELECT 1');
        respond(200, ['status' => 'ok']);
    } catch (\Throwable $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// /health is exempt above because the deploy workflows' post-deploy smoke
// test (curl -fsS ".../app/health", no continue-on-error) would hard-fail
// every migration-containing deploy otherwise -- "deploy code, apply the
// migration by hand shortly after" is the documented, intentional
// production workflow (see database/README.md). /verify-email is exempt
// here too, but not skipped: unlike every other route it renders an HTML
// page for a human clicking an emailed link rather than JSON for our own
// JS, so its own route block below checks the gate itself and responds via
// respondHtml() instead of the generic JSON 503 here.
if ($path !== '/health' && $path !== '/verify-email') {
    $maintenanceMessage = MaintenanceGate::activeMessage();
    if ($maintenanceMessage !== null) {
        header('Retry-After: 120');
        respond(503, ['status' => 'maintenance', 'message' => $maintenanceMessage]);
    }
}

$auth = new AuthService(new UserRepository(), new SessionRepository(), new EmailVerificationRepository());

if ($path === '/register' && $method === 'POST') {
    $body = requestBody();

    try {
        $result = $auth->register(
            (string) ($body['username'] ?? ''),
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? ''),
            isset($body['phone_number']) ? (string) $body['phone_number'] : null
        );
    } catch (DuplicateUsernameException | DuplicateEmailException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }

    try {
        sendVerificationEmail($result['user'], $result['verificationToken']);
    } catch (\Throwable $e) {
        logMailError('Failed to send registration verification email: ' . $e->getMessage());
        $auth->cancelRegistration((int) $result['user']['id']);
        respond(502, [
            'status' => 'error',
            'message' => 'Could not send the verification email. Please try registering again.',
        ]);
    }

    respond(201, [
        'status' => 'ok',
        'message' => 'Check your email to verify your account before logging in.',
        'user' => publicUser($result['user']),
    ]);
}

if ($path === '/resend-verification' && $method === 'POST') {
    $body = requestBody();
    $email = (string) ($body['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, ['status' => 'error', 'message' => 'A valid email address is required.']);
    }

    $result = $auth->resendVerificationEmail($email);

    if ($result !== null) {
        try {
            sendVerificationEmail($result['user'], $result['verificationToken']);
        } catch (\Throwable $e) {
            logMailError('Failed to send resend-verification email: ' . $e->getMessage());
            respond(502, [
                'status' => 'error',
                'message' => 'Could not send the verification email. Please try again shortly.',
            ]);
        }
    }

    // Always the same response, whether or not an email was actually sent, so
    // this endpoint can't be used to discover which addresses are registered.
    respond(200, [
        'status' => 'ok',
        'message' => 'If an account with that email exists and needs verification, a new email has been sent.',
    ]);
}

if ($path === '/verify-email' && $method === 'GET') {
    $maintenanceMessage = MaintenanceGate::activeMessage();
    if ($maintenanceMessage !== null) {
        respondHtml(503, 'Maintenance - MoodSwings-Web', 'Under maintenance', $maintenanceMessage);
    }

    $token = (string) ($_GET['token'] ?? '');

    try {
        $user = $auth->verifyEmail($token);
        respondHtml(
            200,
            'Email verified - MoodSwings-Web',
            'Email verified',
            "Thanks, {$user['username']}! Your account is verified and you can now log in. Redirecting you shortly...",
            '/'
        );
    } catch (InvalidVerificationTokenException $e) {
        respondHtml(400, 'Verification failed - MoodSwings-Web', 'Verification failed', $e->getMessage());
    }
}

if ($path === '/login' && $method === 'POST') {
    $body = requestBody();

    try {
        $result = $auth->login(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? ''),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        setSessionCookie($result['token'], $result['expiresAt']);
        respond(200, ['status' => 'ok', 'user' => publicUser($result['user'])]);
    } catch (InvalidCredentialsException $e) {
        respond(401, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (EmailNotVerifiedException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/logout' && $method === 'POST') {
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;

    if ($token !== null) {
        $auth->logout($token);
    }

    clearSessionCookie();
    respond(200, ['status' => 'ok']);
}

if ($path === '/me' && $method === 'GET') {
    $token = $_COOKIE[AuthService::COOKIE_NAME] ?? null;
    $result = $token !== null ? $auth->currentUser($token) : null;

    if ($result === null) {
        respond(401, ['status' => 'error', 'message' => 'Not authenticated']);
    }

    setSessionCookie($token, $result['expiresAt']);
    respond(200, ['status' => 'ok', 'user' => $result['user']]);
}

$friendships = new FriendshipService(new UserRepository(), new FriendshipRepository());

if ($path === '/friends' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'friends' => $friendships->listFriends((int) $currentUser['id'])]);
}

if ($path === '/friends/invites' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, [
        'status' => 'ok',
        'incoming' => $friendships->listIncomingInvites((int) $currentUser['id']),
        'outgoing' => $friendships->listOutgoingInvites((int) $currentUser['id']),
    ]);
}

if ($path === '/friends/invite' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $target = $friendships->sendInvite((int) $currentUser['id'], (string) ($body['username_or_email'] ?? ''));
        respond(201, [
            'status' => 'ok',
            'message' => 'Friend request sent.',
            'user' => ['id' => (int) $target['id'], 'username' => $target['username']],
        ]);
    } catch (UserNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (CannotFriendSelfException | FriendshipAlreadyExistsException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/friends/respond' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $action = (string) ($body['action'] ?? '');

    try {
        $friendships->respondToInvite((int) $currentUser['id'], (int) ($body['user_id'] ?? 0), $action);
        respond(200, ['status' => 'ok', 'message' => match ($action) {
            'accept' => 'Friend request accepted.',
            'decline' => 'Friend request declined.',
            'block' => 'User blocked.',
            default => 'Done.',
        }]);
    } catch (FriendshipNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToRespondException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/friends/remove' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $friendships->removeFriend((int) $currentUser['id'], (int) ($body['user_id'] ?? 0));
        respond(200, ['status' => 'ok', 'message' => 'Friend removed.']);
    } catch (FriendshipNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

$userDecklists = new UserDecklistService(new UserDecklistRepository(), $friendships);

// Every printed card, for the deck builder's (issue #93) own catalog-
// browsing panel -- filtering/sorting/searching all happen client-side
// against this one full list (only 133 cards total, small enough to send
// in one shot) rather than a bespoke server-side search endpoint per
// filter. Auth-gated like every other route here, but otherwise not
// scoped to any particular user/game -- the catalog itself is public
// knowledge, same reasoning as GET /games/log.
if ($path === '/cards/catalog' && $method === 'GET') {
    requireAuth($auth);
    respond(200, ['status' => 'ok', 'cards' => CardCatalog::serialize(array_keys(CardCatalog::load()['rowsById']))]);
}

if ($path === '/decklists' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', ...$userDecklists->listForViewer((int) $currentUser['id'])]);
}

if ($path === '/decklists/view' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $id = (int) ($_GET['id'] ?? 0);

    try {
        respond(200, ['status' => 'ok', 'decklist' => $userDecklists->view((int) $currentUser['id'], $id)]);
    } catch (DecklistNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToAccessDecklistException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/decklists' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $id = $userDecklists->create(
            (int) $currentUser['id'],
            (string) ($body['name'] ?? ''),
            isset($body['decklist_text']) ? (string) $body['decklist_text'] : null,
            isset($body['card_ids']) ? array_map(intval(...), (array) $body['card_ids']) : null,
            isset($body['sideboard_card_ids']) ? array_map(intval(...), (array) $body['sideboard_card_ids']) : null,
            (string) ($body['visibility'] ?? 'private'),
        );
        respond(201, ['status' => 'ok', 'decklist_id' => $id]);
    } catch (DecklistValidationException | \InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/decklists/update' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $userDecklists->update(
            (int) $currentUser['id'],
            (int) ($body['id'] ?? 0),
            (string) ($body['name'] ?? ''),
            isset($body['decklist_text']) ? (string) $body['decklist_text'] : null,
            isset($body['card_ids']) ? array_map(intval(...), (array) $body['card_ids']) : null,
            isset($body['sideboard_card_ids']) ? array_map(intval(...), (array) $body['sideboard_card_ids']) : null,
            (string) ($body['visibility'] ?? 'private'),
        );
        respond(200, ['status' => 'ok']);
    } catch (DecklistNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToAccessDecklistException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (DecklistValidationException | \InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/decklists/delete' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    try {
        $userDecklists->delete((int) $currentUser['id'], (int) ($body['id'] ?? 0));
        respond(200, ['status' => 'ok', 'message' => 'Deck deleted.']);
    } catch (DecklistNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToAccessDecklistException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

$gameRegistry = DefaultEffectRegistry::build();
$games = new GameService(new BoardStateRepository($gameRegistry), new MoodPlayService($gameRegistry), new RoundScorer(), $userDecklists);

// Lifetime game/match wins-losses (issue #106) -- see
// GameService::lifetimeStatsFor()/recordGameCompletionStats()/
// recordMatchCompletionStats(). Self only for now; no per-friend lookup
// yet.
if ($path === '/user/stats' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, [
        'status' => 'ok',
        'username' => $currentUser['username'],
        'stats' => $games->lifetimeStatsFor((int) $currentUser['id']),
    ]);
}

/**
 * Resolves the authenticated user's game_players.id for $gameId, responding
 * 403 (without confirming or denying the game's existence) if they aren't
 * seated in it.
 */
function requireGamePlayer(GameService $games, int $gameId, int $userId): int
{
    $gamePlayerId = $games->gamePlayerIdFor($gameId, $userId);
    if ($gamePlayerId === null) {
        respond(403, ['status' => 'error', 'message' => 'You are not a player in this game.']);
    }

    return $gamePlayerId;
}

// Spectator mode (issue #128): the same "friends with a seated player OR
// holds the game's own code" rule GET /games/spectate/state enforces
// inline, factored out so GET /games/deck (below) can reuse it verbatim
// rather than re-deriving it -- a spectator viewing a shared-deck game's
// board should be able to open its decklist too, not just seated players.
function canSpectateGame(GameService $games, FriendshipService $friendships, int $gameId, int $userId, ?string $code): bool
{
    if ($code !== null && $code !== '' && $games->spectateCodeFor($gameId) === $code) {
        return true;
    }
    foreach ($games->seatedUserIdsFor($gameId) as $seatedUserId) {
        if ($friendships->areFriends($userId, $seatedUserId)) {
            return true;
        }
    }

    return false;
}

if ($path === '/games' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $currentUserId = (int) $currentUser['id'];

    $opponentUserIds = array_map(intval(...), (array) ($body['opponent_user_ids'] ?? []));
    $userIds = array_values(array_unique([$currentUserId, ...$opponentUserIds]));
    $format = (string) ($body['format'] ?? 'standard');
    $winsNeeded = isset($body['wins_needed']) ? (int) $body['wins_needed'] : 3;
    // Matches GameService::createGame()'s own default -- 'standard' was
    // deck_type's name before migration 0014 renamed it to 'structure' and
    // narrowed the enum to no longer even accept 'standard'; this literal
    // default here was missed at the time, so any request that omits
    // deck_type entirely failed with a PDOException on the INSERT below,
    // caught by the generic PDOException handler and misreported as
    // "opponents could not be found" -- unrelated to the actual cause.
    $deckType = (string) ($body['deck_type'] ?? 'structure');
    $decklistText = isset($body['decklist_text']) ? (string) $body['decklist_text'] : null;
    $duelDeckRules = is_array($body['duel_deck_rules'] ?? null) ? $body['duel_deck_rules'] : null;
    // Only meaningful for format 'team' -- see createGame()'s own docblock.
    $partnerUserId = isset($body['partner_user_id']) ? (int) $body['partner_user_id'] : null;
    // Only meaningful for deck_type 'quick_draft' -- see createGame()'s own docblock.
    $quickDraftPoolSource = isset($body['quick_draft_pool_source']) ? (string) $body['quick_draft_pool_source'] : null;
    $quickDraftCustomPoolText = isset($body['quick_draft_custom_pool_text']) ? (string) $body['quick_draft_custom_pool_text'] : null;
    // Only meaningful for deck_type 'winston_draft' -- see createGame()'s own docblock.
    $winstonDraftPoolSource = isset($body['winston_draft_pool_source']) ? (string) $body['winston_draft_pool_source'] : null;
    $winstonDraftCustomPoolText = isset($body['winston_draft_custom_pool_text']) ? (string) $body['winston_draft_custom_pool_text'] : null;
    // Only meaningful for deck_type 'grid_draft' -- see createGame()'s own docblock.
    $gridDraftPoolSource = isset($body['grid_draft_pool_source']) ? (string) $body['grid_draft_pool_source'] : null;
    $gridDraftCustomPoolText = isset($body['grid_draft_custom_pool_text']) ? (string) $body['grid_draft_custom_pool_text'] : null;
    // Only meaningful for deck_type 'custom' -- an alternative to
    // decklist_text, loading a previously-saved decklist (issue #92)
    // instead of parsing freshly-pasted/uploaded text.
    $savedDecklistId = isset($body['saved_decklist_id']) ? (int) $body['saved_decklist_id'] : null;

    try {
        $gameId = $games->createGame(
            $currentUserId,
            $userIds,
            $format,
            $winsNeeded,
            $deckType,
            $decklistText,
            $duelDeckRules,
            $partnerUserId,
            $quickDraftPoolSource,
            $quickDraftCustomPoolText,
            $winstonDraftPoolSource,
            $winstonDraftCustomPoolText,
            $gridDraftPoolSource,
            $gridDraftCustomPoolText,
            $savedDecklistId,
        );
        respond(201, ['status' => 'ok', 'game_id' => $gameId]);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (DecklistNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToAccessDecklistException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\PDOException $e) {
        respond(400, ['status' => 'error', 'message' => 'One or more opponents could not be found.']);
    }
}

if ($path === '/games/decklist' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $decklistText = isset($body['decklist_text']) ? (string) $body['decklist_text'] : null;
    // An alternative to decklist_text, loading a previously-saved decklist
    // (issue #92) instead of parsing freshly-pasted/uploaded text.
    $savedDecklistId = isset($body['saved_decklist_id']) ? (int) $body['saved_decklist_id'] : null;

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->submitCustomDuelDeck($gameId, $gamePlayerId, $decklistText, $savedDecklistId);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (DecklistNotFoundException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (NotAuthorizedToAccessDecklistException $e) {
        respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'games' => $games->listGamesForUser((int) $currentUser['id'])]);
}

if ($path === '/games/state' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);

    requireGamePlayer($games, $gameId, (int) $currentUser['id']);
    respond(200, ['status' => 'ok', ...$games->getState($gameId, (int) $currentUser['id'])]);
}

// Spectator mode (issue #128): every currently-in_progress game any of
// the caller's friends is seated in, that the caller isn't -- the
// code-entry field on the "Spectate" page's other feature (below) covers
// games spectatable by code instead, friend or not.
if ($path === '/games/spectatable' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $friendUserIds = array_map(
        fn (array $friend): int => (int) $friend['friend_id'],
        $friendships->listFriends((int) $currentUser['id']),
    );
    respond(200, [
        'status' => 'ok',
        'games' => $games->listFriendsInProgressGames((int) $currentUser['id'], $friendUserIds),
    ]);
}

// Get-or-create the calling player's own game's spectate code (issue
// #128), to show/copy for sharing -- only a seated player may mint or
// reveal it (requireGamePlayer(), same as every other game_id-taking
// route that mutates/reveals something about one specific game).
if ($path === '/games/spectate/code' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);

    requireGamePlayer($games, $gameId, (int) $currentUser['id']);
    try {
        respond(200, ['status' => 'ok', 'code' => $games->getOrCreateSpectateCode($gameId)]);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Resolves a spectate code (issue #128) typed into the "Spectate" page's
// code-entry field to the game_id it belongs to, for the frontend to then
// navigate to. Deliberately no requireGamePlayer()/friendship check here
// -- holding the code is itself the authorization (see "Spectator mode"
// in php-app/README.md) -- only requireAuth(), since every page in this
// app requires an account.
if ($path === '/games/spectate/resolve' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $code = trim((string) ($body['code'] ?? ''));

    try {
        respond(200, ['status' => 'ok', 'game_id' => $games->resolveSpectateCode($code)]);
    } catch (GameStateException $e) {
        respond(404, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// The spectator-mode equivalent of GET /games/state -- deliberately the
// first route in this app that accepts a bare game_id with no
// requireGamePlayer() seat check (a spectator is by definition not
// seated). Authorized instead by either being friends with at least one
// seated player, or supplying the game's own spectate_code as a query
// param -- either one is sufficient, matching how the "Spectate" page
// offers both a friends' list and a code-entry field as independent
// paths to the same board. See GameService::getSpectatorState() for
// exactly what a spectator does/doesn't see.
if ($path === '/games/spectate/state' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;

    if (!canSpectateGame($games, $friendships, $gameId, (int) $currentUser['id'], $code)) {
        respond(403, ['status' => 'error', 'message' => 'You are not authorized to spectate this game.']);
    }

    try {
        respond(200, ['status' => 'ok', ...$games->getSpectatorState($gameId)]);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// The entire game log (issue #98) -- unlike GET /games/state, no
// per-viewer customization at all (see GameService::fullEventLog()'s own
// docblock), so a spectator (issue #128) can read it just as well as a
// seated player -- same canSpectateGame() authorization GET /games/deck
// uses for the same reason.
if ($path === '/games/log' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;

    $isSeated = $games->gamePlayerIdFor($gameId, (int) $currentUser['id']) !== null;
    if (!$isSeated && !canSpectateGame($games, $friendships, $gameId, (int) $currentUser['id'], $code)) {
        respond(403, ['status' => 'error', 'message' => 'You are not authorized to view this game.']);
    }
    respond(200, ['status' => 'ok', 'events' => $games->fullEventLog($gameId)]);
}

// Every card in a shared-deck game's single deck (issue #197) -- named
// "/games/deck" rather than "/games/decklist" to avoid colliding with the
// existing POST /games/decklist (custom_duel's own per-player deck
// submission, a completely different thing). Same no-per-viewer-filtering
// reasoning as GET /games/log immediately above -- viewSharedDeck() itself
// takes no viewer at all, so a spectator (issue #128) is just as able to
// open a shared-deck game's own "View decklist" as a seated player, via
// the same canSpectateGame() authorization GET /games/spectate/state uses.
if ($path === '/games/deck' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;

    $isSeated = $games->gamePlayerIdFor($gameId, (int) $currentUser['id']) !== null;
    if (!$isSeated && !canSpectateGame($games, $friendships, $gameId, (int) $currentUser['id'], $code)) {
        respond(403, ['status' => 'error', 'message' => 'You are not authorized to view this game.']);
    }

    try {
        respond(200, ['status' => 'ok', 'cards' => $games->viewSharedDeck($gameId)]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/start' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);

    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->startGame($gameId);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/play' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $cardId = (int) ($body['card_id'] ?? 0);
    $choices = is_array($body['choices'] ?? null) ? $body['choices'] : [];

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->playMood($gameId, $gamePlayerId, $cardId, $choices);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (InvalidChoiceException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (GameStateException | IllegalPlayException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (EffectNotImplementedException $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/pass' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->pass($gameId, $gamePlayerId);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException | IllegalPlayException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/resign' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->resignGame($gameId, $gamePlayerId);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException | IllegalPlayException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/respond' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $choices = is_array($body['choices'] ?? null) ? $body['choices'] : [];

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->respondToDecision($gameId, $gamePlayerId, $choices);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (InvalidChoiceException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (GameStateException | IllegalPlayException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (EffectNotImplementedException $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/team-decision' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $action = (string) ($body['action'] ?? '');

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = match ($action) {
            'propose' => $games->proposeTeamDecision($gameId, $gamePlayerId, (int) ($body['proposed_game_player_id'] ?? 0)),
            'confirm' => $games->confirmTeamDecision($gameId, $gamePlayerId, (bool) ($body['approve'] ?? false)),
            default => throw new GameStateException('action must be "propose" or "confirm"'),
        };
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/initial-pass' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $cardIds = array_map(intval(...), (array) ($body['card_ids'] ?? []));

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->submitInitialCardPass($gameId, $gamePlayerId, $cardIds);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/draft/pick' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $round = (int) ($body['round'] ?? 0);
    $stage = (string) ($body['stage'] ?? '');
    $cardIds = array_map(intval(...), (array) ($body['card_ids'] ?? []));

    // Quick Draft's own picks are keyed by user_id, not game_player_id
    // (see migration 0027's docblock -- this data spans up to 3 separate
    // games rows) -- requireGamePlayer() here is purely the seated-in-this-game
    // auth check every other route already uses.
    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->submitQuickDraftPick($gameId, (int) $currentUser['id'], $round, $stage, $cardIds);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/draft/deck' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $cardIds = array_map(intval(...), (array) ($body['card_ids'] ?? []));

    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->submitDraftDeck($gameId, (int) $currentUser['id'], $cardIds);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Lets the loser of a best-of-three draft match's game N opt to go first
// themselves in game N+1 -- see GameService::setPlayFirstNextMatchGame().
// Only callable once game N+1 has actually started (per the game's own
// rules, the loser doesn't have to decide until they can see their
// opening hand) -- round 1 stays frozen (nobody may play) until this
// resolves, one answer either way.
if ($path === '/games/draft/first-player-choice' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $playFirst = (bool) ($body['play_first'] ?? false);

    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->setPlayFirstNextMatchGame($gameId, (int) $currentUser['id'], $playFirst);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/draft/winston-pick' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $action = (string) ($body['action'] ?? '');

    // Winston Draft's own picks are keyed by user_id, not game_player_id
    // (same rationale as /games/draft/pick above) -- requireGamePlayer()
    // here is purely the seated-in-this-game auth check every other route
    // already uses.
    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->submitWinstonDraftPick($gameId, (int) $currentUser['id'], $action);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

if ($path === '/games/draft/grid-pick' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $axis = (string) ($body['axis'] ?? '');
    $index = (int) ($body['index'] ?? -1);

    // Grid Draft's own picks are keyed by user_id, not game_player_id
    // (same rationale as /games/draft/pick above) -- requireGamePlayer()
    // here is purely the seated-in-this-game auth check every other route
    // already uses.
    requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $result = $games->submitGridDraftPick($gameId, (int) $currentUser['id'], $axis, $index);
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

respond(404, ['status' => 'error', 'message' => 'Not found']);
