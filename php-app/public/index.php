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
use MoodSwings\Friends\CannotFriendSelfException;
use MoodSwings\Friends\FriendshipAlreadyExistsException;
use MoodSwings\Friends\FriendshipNotFoundException;
use MoodSwings\Friends\FriendshipService;
use MoodSwings\Friends\NotAuthorizedToRespondException;
use MoodSwings\Friends\UserNotFoundException;
use MoodSwings\Game\BoardStateRepository;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Game\GameService;
use MoodSwings\Mail\Mailer;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\SessionRepository;
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

$gameRegistry = DefaultEffectRegistry::build();
$games = new GameService(new BoardStateRepository($gameRegistry), new MoodPlayService($gameRegistry), new RoundScorer());

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

    try {
        $gameId = $games->createGame($currentUserId, $userIds, $format, $winsNeeded, $deckType, $decklistText);
        respond(201, ['status' => 'ok', 'game_id' => $gameId]);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\PDOException $e) {
        respond(400, ['status' => 'error', 'message' => 'One or more opponents could not be found.']);
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

respond(404, ['status' => 'error', 'message' => 'Not found']);
