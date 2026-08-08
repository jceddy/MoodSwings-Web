<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Auth\AuthService;
use MoodSwings\Auth\DuplicateEmailException;
use MoodSwings\Auth\DuplicateUsernameException;
use MoodSwings\Auth\EmailNotVerifiedException;
use MoodSwings\Auth\InvalidCredentialsException;
use MoodSwings\Auth\InvalidPasswordResetTokenException;
use MoodSwings\Auth\InvalidVerificationTokenException;
use MoodSwings\Config;
use MoodSwings\Database\Connection;
use MoodSwings\Database\MigrationRunner;
use MoodSwings\Deck\DecklistNotFoundException;
use MoodSwings\Deck\DecklistValidationException;
use MoodSwings\Deck\NotAuthorizedToAccessDecklistException;
use MoodSwings\Deck\UserDecklistService;
use MoodSwings\Discord\DiscordInteractionsService;
use MoodSwings\Discord\DiscordLinkException;
use MoodSwings\Discord\DiscordNotificationChannel;
use MoodSwings\Discord\DiscordOAuthService;
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
use MoodSwings\Game\ReplayStateBuilder;
use MoodSwings\Mail\Mailer;
use MoodSwings\Maintenance\MaintenanceGate;
use MoodSwings\Notifications\NotificationService;
use MoodSwings\Notifications\PushNotificationChannel;
use MoodSwings\Repository\DiscordAccountRepository;
use MoodSwings\Repository\DiscordOAuthStateRepository;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PasswordResetRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use MoodSwings\Repository\QueuedNotificationRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserDecklistRepository;
use MoodSwings\Repository\UserRepository;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\EffectNotImplementedException;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\RoundScorer;
use MoodSwings\SiteUrl;
use MoodSwings\Stats\CardStatsService;

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

/** A real 302 (not JSON) -- only /discord/oauth/* routes use this; every other route responds JSON via respond(). */
function redirectTo(string $url): never
{
    header('Location: ' . $url);
    http_response_code(302);
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
 * Unlike sendVerificationEmail(), this links to a static frontend page
 * rather than a token-consuming GET route: corporate email-security
 * scanners that pre-fetch links in inbound mail would otherwise silently
 * burn the single-use reset token before the real user ever opens it. The
 * static page reads ?token= on load but only submits (and consumes) it
 * when the user actually chooses a new password, via POST /reset-password.
 *
 * @throws \Throwable if the email fails to send
 */
function sendPasswordResetEmail(array $user, string $token): void
{
    $resetUrl = SiteUrl::root() . '/reset-password.html?token=' . urlencode($token);

    (new Mailer())->sendPasswordResetEmail($user['email'], $user['username'], $resetUrl);
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

/**
 * Applies pending database/migrations/*.sql files (see MigrationRunner),
 * called by the deploy workflows (.github/workflows/deploy*.yml) right
 * after each deploy's file upload -- production has no shell access of
 * its own to run bin/migrate.php directly (see "Applying migrations" in
 * database/README.md), so this is the only way a schema-changing deploy
 * gets its migration applied automatically rather than by hand via
 * phpMyAdmin. Gated on MIGRATION_DEPLOY_KEY (an X-Migration-Key header,
 * compared with hash_equals() to resist timing attacks) rather than any
 * user session, since this runs from a CI job with no logged-in user at
 * all; an unset/empty key fails closed rather than leaving this endpoint
 * open by accident on a deploy that hasn't configured the secret yet.
 */
if ($path === '/migrate' && $method === 'POST') {
    $expectedKey = Config::get('MIGRATION_DEPLOY_KEY', '') ?? '';
    $providedKey = $_SERVER['HTTP_X_MIGRATION_KEY'] ?? '';

    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        respond(403, ['status' => 'error', 'message' => 'Invalid or missing migration key']);
    }

    try {
        $applied = MigrationRunner::applyPending(Connection::get());
        respond(200, ['status' => 'ok', 'applied' => $applied]);
    } catch (\Throwable $e) {
        respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// /health and /migrate are exempt above because the deploy workflows'
// post-deploy smoke test (curl -fsS ".../app/health", no
// continue-on-error) and migration step both need to run regardless of
// whether the deployed VERSION and schema_version currently agree --
// /migrate's entire purpose is to resolve that exact mismatch, so gating
// it behind the same check it exists to fix would make it unreachable
// exactly when it's needed. /verify-email is exempt here too, but not
// skipped: unlike every other route it renders an HTML page for a human
// clicking an emailed link rather than JSON for our own JS, so its own
// route block below checks the gate itself and responds via
// respondHtml() instead of the generic JSON 503 here.
if ($path !== '/health' && $path !== '/migrate' && $path !== '/verify-email') {
    $maintenanceMessage = MaintenanceGate::activeMessage();
    if ($maintenanceMessage !== null) {
        header('Retry-After: 120');
        respond(503, ['status' => 'maintenance', 'message' => $maintenanceMessage]);
    }
}

$auth = new AuthService(
    new UserRepository(),
    new SessionRepository(),
    new EmailVerificationRepository(),
    new PasswordResetRepository()
);

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

if ($path === '/forgot-password' && $method === 'POST') {
    $body = requestBody();
    $email = (string) ($body['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        respond(400, ['status' => 'error', 'message' => 'A valid email address is required.']);
    }

    $result = $auth->requestPasswordReset($email);

    if ($result !== null) {
        try {
            sendPasswordResetEmail($result['user'], $result['resetToken']);
        } catch (\Throwable $e) {
            logMailError('Failed to send password reset email: ' . $e->getMessage());
            respond(502, [
                'status' => 'error',
                'message' => 'Could not send the password reset email. Please try again shortly.',
            ]);
        }
    }

    // Always the same response, whether or not an email was actually sent, so
    // this endpoint can't be used to discover which addresses are registered.
    respond(200, [
        'status' => 'ok',
        'message' => 'If an account with that email exists, a password reset link has been sent.',
    ]);
}

if ($path === '/reset-password' && $method === 'POST') {
    $body = requestBody();

    try {
        $user = $auth->resetPassword((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));
        respond(200, [
            'status' => 'ok',
            'message' => 'Your password has been reset. You can now log in with your new password.',
            'user' => publicUser($user),
        ]);
    } catch (InvalidPasswordResetTokenException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
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

$pushSubscriptions = new PushSubscriptionRepository();
$notificationPreferences = new NotificationPreferenceRepository();
$queuedNotifications = new QueuedNotificationRepository();
$notificationCooldowns = new NotificationCooldownRepository();
// $discordAccounts is constructed here (rather than alongside the rest of
// the /discord/* routes further below) so it can feed
// DiscordNotificationChannel -- the "notify me when..." preferences
// dialog applies to every linked channel at once (push and/or Discord),
// so both channels are wired into the same NotificationService rather
// than kept as two independent senders.
$discordAccounts = new DiscordAccountRepository();
$notifications = new NotificationService($notificationPreferences, $queuedNotifications, $notificationCooldowns, [
    new PushNotificationChannel($pushSubscriptions),
    new DiscordNotificationChannel($discordAccounts),
]);

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
        $notifications->notifyFriendRequest((int) $target['id'], (string) $currentUser['username']);
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
        $notifications->clearQueuedFriendRequest((int) $currentUser['id']);
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

// Browser push notifications (issue #108). The public key is handed to
// PushManager.subscribe() client-side; it isn't secret (it's the whole
// point of asymmetric VAPID auth) so no auth is required to read it, same
// reasoning as /cards/catalog being public knowledge.
if ($path === '/notifications/vapid-public-key' && $method === 'GET') {
    respond(200, ['status' => 'ok', 'public_key' => Config::get('VAPID_PUBLIC_KEY', '')]);
}

if ($path === '/notifications/subscribe' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $endpoint = (string) ($body['endpoint'] ?? '');
    $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];

    if ($endpoint === '' || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
        respond(400, ['status' => 'error', 'message' => 'A subscription endpoint and keys.p256dh/keys.auth are required.']);
    }

    $pushSubscriptions->save((int) $currentUser['id'], $endpoint, $keys['p256dh'], $keys['auth']);
    respond(201, ['status' => 'ok', 'message' => 'Subscribed to push notifications.']);
}

if ($path === '/notifications/unsubscribe' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    $pushSubscriptions->deleteByEndpoint((int) $currentUser['id'], (string) ($body['endpoint'] ?? ''));
    respond(200, ['status' => 'ok', 'message' => 'Unsubscribed from push notifications.']);
}

if ($path === '/notifications/preferences' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'preferences' => $notificationPreferences->forUser((int) $currentUser['id'])]);
}

if ($path === '/notifications/preferences' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();

    $notificationPreferences->save(
        (int) $currentUser['id'],
        (bool) ($body['notify_your_turn'] ?? true),
        (bool) ($body['notify_friend_request'] ?? true),
        (bool) ($body['notify_game_finished'] ?? true),
        (bool) ($body['disable_cooldown'] ?? false),
        (bool) ($body['notify_chat_message'] ?? true)
    );
    respond(200, ['status' => 'ok', 'preferences' => $notificationPreferences->forUser((int) $currentUser['id'])]);
}

// $discordAccounts itself was already constructed above, alongside
// $notifications.
$discordOAuth = new DiscordOAuthService($discordAccounts, new DiscordOAuthStateRepository());
$discordInteractions = new DiscordInteractionsService();

if ($path === '/discord/status' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $account = $discordAccounts->findByUserId((int) $currentUser['id']);
    respond(200, [
        'status' => 'ok',
        'linked' => $account !== null,
        'discord_username' => $account['discord_username'] ?? null,
    ]);
}

// Plain browser navigations (a "Connect Discord" link/button), not JSON
// API calls -- both end in a 302, either straight to Discord's own
// consent screen or back to the lobby once linking's done.
if ($path === '/discord/oauth/start' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    redirectTo($discordOAuth->buildAuthorizeUrl((int) $currentUser['id']));
}

if ($path === '/discord/oauth/callback' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $lobbyUrl = SiteUrl::root() . '/game/';

    try {
        $discordOAuth->handleCallback((int) $currentUser['id'], (string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
        redirectTo($lobbyUrl . '?discord_linked=1');
    } catch (DiscordLinkException $e) {
        redirectTo($lobbyUrl . '?discord_link_error=' . urlencode($e->getMessage()));
    }
}

if ($path === '/discord/unlink' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $discordAccounts->unlink((int) $currentUser['id']);
    respond(200, ['status' => 'ok', 'message' => 'Discord account unlinked.']);
}

// Discord's own Interactions Endpoint -- called by Discord itself, never
// by this site's own JS, so it's authenticated by Ed25519 signature
// (DiscordInteractionsService::verify()) instead of the session cookie
// every other route here uses. The raw, exact request body is what gets
// signed, so it has to be read (and handed to verify()) before anything
// touches requestBody()'s own json_decode()'d copy.
if ($path === '/discord/interactions' && $method === 'POST') {
    $rawBody = (string) file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? null;
    $timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null;

    if (!is_string($signature) || !is_string($timestamp) || !$discordInteractions->verify($rawBody, $signature, $timestamp)) {
        respond(401, ['status' => 'error', 'message' => 'Invalid request signature']);
    }

    $payload = json_decode($rawBody, true);
    respond(200, $discordInteractions->handle(is_array($payload) ? $payload : []));
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
$cardStats = new CardStatsService();
$games = new GameService(new BoardStateRepository($gameRegistry), new MoodPlayService($gameRegistry), new RoundScorer(), $userDecklists, new ReplayStateBuilder($gameRegistry), notifications: $notifications, cardStats: $cardStats);

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

// Online/presence indicator (issue #110): lets a user opt out of sharing
// their own derived online/offline status with friends and fellow game
// players entirely -- surfaced to others as a distinct 'hidden' status
// rather than folded into 'offline' (see PresenceService). Current value
// is already carried on GET /me's own user object (see
// AuthService::currentUser()), so this route is write-only; no
// matching GET is needed.
if ($path === '/user/presence-preference' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $input = requestBody();

    if (!array_key_exists('share_presence', $input)) {
        respond(400, ['status' => 'error', 'message' => 'share_presence is required.']);
    }

    (new UserRepository())->setSharePresence((int) $currentUser['id'], (bool) $input['share_presence']);
    respond(200, ['status' => 'ok']);
}

// "Default selections mode" as a personal preference (Settings dialog's
// "Game defaults" section) -- this user's own default for the New Game
// dialog's default-selections-mode checkbox, distinct from
// games.default_selections_mode (issue #274, the actual per-game
// setting sent as part of POST /games). Current value is already
// carried on GET /me's own user object (see AuthService::currentUser()),
// so this route is write-only, same pattern as
// /user/presence-preference above.
if ($path === '/user/default-selections-mode-preference' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $input = requestBody();

    if (!array_key_exists('default_selections_mode_preference', $input)) {
        respond(400, ['status' => 'error', 'message' => 'default_selections_mode_preference is required.']);
    }

    (new UserRepository())->setDefaultSelectionsModePreference(
        (int) $currentUser['id'],
        (bool) $input['default_selections_mode_preference']
    );
    respond(200, ['status' => 'ok']);
}

// "Auto-pass on empty hand" as a personal preference (Settings dialog's
// "Game defaults" section) -- see GameService::advanceAutomatedTurns()
// for the server-side behavior this drives. Current value is already
// carried on GET /me's own user object, so this route is write-only,
// same pattern as /user/default-selections-mode-preference above.
if ($path === '/user/auto-pass-on-empty-hand-preference' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $input = requestBody();

    if (!array_key_exists('auto_pass_on_empty_hand', $input)) {
        respond(400, ['status' => 'error', 'message' => 'auto_pass_on_empty_hand is required.']);
    }

    (new UserRepository())->setAutoPassOnEmptyHand(
        (int) $currentUser['id'],
        (bool) $input['auto_pass_on_empty_hand']
    );
    respond(200, ['status' => 'ok']);
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

// Practice bots (issue #140): the New Game dialog's own bot picker, a
// small fixed roster (migration 0090) rather than anything scoped to the
// caller specifically -- every authenticated user sees the same list, the
// same way GET /cards/catalog is public knowledge rather than per-user.
if ($path === '/games/bots' && $method === 'GET') {
    requireAuth($auth);

    respond(200, ['status' => 'ok', 'bots' => $games->listPracticeBots()]);
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
    // Meaningful for deck_type 'custom' (an alternative to decklist_text)
    // or when any of the three *_pool_source values above is 'saved_deck'
    // (issue #290, an alternative to that draft type's own
    // *_custom_pool_text) -- loading a previously-saved decklist (issue
    // #92) instead of parsing freshly-pasted/uploaded text.
    $savedDecklistId = isset($body['saved_decklist_id']) ? (int) $body['saved_decklist_id'] : null;
    // "Default selections" mode (issue #274) -- a per-game toggle, chosen
    // once here alongside format/deck_type, not a personal preference.
    $defaultSelectionsMode = (bool) ($body['default_selections_mode'] ?? false);
    // Only meaningful (and required) when deck_type is 'custom_duel' and
    // one of opponent_user_ids is a practice bot (issue #140) -- the
    // bot's own decklist, supplied by the creator since the bot can
    // never submit one itself via POST /games/decklist the way its human
    // opponent does. See createGame()'s own docblock.
    $botDecklistText = isset($body['bot_decklist_text']) ? (string) $body['bot_decklist_text'] : null;
    $botSavedDecklistId = isset($body['bot_saved_decklist_id']) ? (int) $body['bot_saved_decklist_id'] : null;

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
            $defaultSelectionsMode,
            $botDecklistText,
            $botSavedDecklistId,
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

// "Past games" (issue #84): the complement of GET /games above -- every
// completed game not still tied to an in-progress draft match. See
// GameService::listPastGamesForUser()'s own docblock for exactly where
// that line falls.
if ($path === '/games/past' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    respond(200, ['status' => 'ok', 'games' => $games->listPastGamesForUser((int) $currentUser['id'])]);
}

// Card/draft statistics (issue #315): server-wide aggregate data, not
// tied to any one game -- requireAuth() only (every page in this app
// requires an account), no game/friendship check. See
// CardStatsService::allCardStats()'s own docblock.
if ($path === '/stats/cards' && $method === 'GET') {
    requireAuth($auth);
    respond(200, ['status' => 'ok', 'cards' => $cardStats->allCardStats()]);
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

// Issue #99 "download complete serialized game data": a raw, complete
// per-table dump for offline archiving, deliberately narrower in audience
// than GET /games/log above -- seated players only (requireGamePlayer(),
// same gate GET /games/state uses), not spectators, since this is meant
// as one player's own personal archive rather than a shareable view.
if ($path === '/games/export' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);
    respond(200, ['status' => 'ok', 'export' => $games->exportGameData($gameId, $gamePlayerId)]);
}

// Issue #240 "watch game replay": the board reconstructed as of one
// specific past event -- only ever available once a game is 'completed'
// (see ReplayStateBuilder). Same no-per-viewer-customization reasoning,
// and the same canSpectateGame() authorization, as GET /games/log
// immediately above -- the frontend's own step controls reuse that same
// route for the steppable event list, this one just answers "what did the
// board look like at event N".
if ($path === '/games/replay/state' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);
    $eventId = (int) ($_GET['event_id'] ?? 0);
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;

    $isSeated = $games->gamePlayerIdFor($gameId, (int) $currentUser['id']) !== null;
    if (!$isSeated && !canSpectateGame($games, $friendships, $gameId, (int) $currentUser['id'], $code)) {
        respond(403, ['status' => 'error', 'message' => 'You are not authorized to view this game.']);
    }

    try {
        respond(200, ['status' => 'ok', ...$games->replayStateAsOf($gameId, $eventId)]);
    } catch (GameStateException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
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

// Issue #314 "view shared draft pool after a draft match completes": the
// full pool a Quick/Winston/Grid Draft match was drafted from, plus each
// seated player's own drafted cards and whatever nobody ended up with.
// Same "reviewing after the fact" spirit -- and the same seated-or-
// canSpectateGame() authorization -- as GET /games/replay/state above,
// since draftMatchPoolView() itself enforces the actual "only once the
// match is completed" gate (a 409, not a 403 -- the requester IS
// authorized to view this game, the data just isn't ready yet).
if ($path === '/games/draft-pool' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;

    $isSeated = $games->gamePlayerIdFor($gameId, (int) $currentUser['id']) !== null;
    if (!$isSeated && !canSpectateGame($games, $friendships, $gameId, (int) $currentUser['id'], $code)) {
        respond(403, ['status' => 'error', 'message' => 'You are not authorized to view this game.']);
    }

    try {
        respond(200, ['status' => 'ok', ...$games->draftMatchPoolView($gameId)]);
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
        // Practice bots (issue #140)/auto-pass on empty hand: the very
        // first turn of the game might already belong to a bot, or to an
        // opted-in player dealt an empty hand -- see the identical
        // comment on POST /games/play above.
        $autoResult = $games->advanceAutomatedTurns($gameId);
        respond(200, ['status' => 'ok', ...($autoResult ?? [])]);
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
        // Practice bots (issue #140)/auto-pass on empty hand: if this play
        // handed the turn (or a pending decision) to a bot, or to an
        // opted-in player now holding an empty hand, drive its own
        // action(s) right here -- possibly several in a row -- before
        // responding, so the human who just moved sees the game already
        // advanced past them rather than having to poll and wait. See
        // GameService::advanceAutomatedTurns()'s own docblock; a null
        // return means nothing automated ever got a turn, so $result
        // stays exactly what the human's own play produced.
        $autoResult = $games->advanceAutomatedTurns($gameId);
        if ($autoResult !== null) {
            $result = $autoResult;
        }
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
        // Practice bots (issue #140)/auto-pass on empty hand -- see the
        // identical comment on POST /games/play above.
        $autoResult = $games->advanceAutomatedTurns($gameId);
        if ($autoResult !== null) {
            $result = $autoResult;
        }
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
        // Practice bots (issue #140)/auto-pass on empty hand: a
        // resignation in a 3-4 player standard game can hand the turn
        // straight to a bot (or an opted-in empty-handed player) without
        // the game actually ending -- see the identical comment on
        // POST /games/play above.
        $autoResult = $games->advanceAutomatedTurns($gameId);
        if ($autoResult !== null) {
            $result = $autoResult;
        }
        respond(200, ['status' => 'ok', ...$result]);
    } catch (GameStateException | IllegalPlayException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// In-game notepad (issue #258): private per-seat scratch notes, never
// shared with anyone else at the table. GET always succeeds for a seated
// player regardless of the game's own status (a completed game's notes
// stay fully readable); only the POST (save) is gated to 'in_progress'.
if ($path === '/games/notes' && $method === 'GET') {
    $currentUser = requireAuth($auth);
    $gameId = (int) ($_GET['game_id'] ?? 0);

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    respond(200, ['status' => 'ok', 'note_text' => $games->getNote($gamePlayerId)]);
}

if ($path === '/games/notes' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $noteText = (string) ($body['note_text'] ?? '');

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->saveNote($gameId, $gamePlayerId, $noteText);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// In-game chat (issue #109): no GET route -- unlike /games/notes above,
// chat is delivered entirely via GET /games/state's own new
// 'chat_messages' field (piggybacked on the existing 4s poll rather than
// its own polling endpoint, see GameService::chatMessagesFor()'s own
// docblock), so sending is the only new endpoint needed. 'channel'
// defaults to 'table' -- the only option every format actually has;
// 'team' is only valid for format 'team' (Open Team Play only -- NOT
// 'closed_team', whose whole premise is that information stays closed
// between teammates, see postChatMessage()'s own docblock; enforced
// inside postChatMessage() itself, a 409 otherwise).
if ($path === '/games/chat' && $method === 'POST') {
    $currentUser = requireAuth($auth);
    $body = requestBody();
    $gameId = (int) ($body['game_id'] ?? 0);
    $channel = (string) ($body['channel'] ?? 'table');
    $messageText = (string) ($body['message_text'] ?? '');

    $gamePlayerId = requireGamePlayer($games, $gameId, (int) $currentUser['id']);

    try {
        $games->postChatMessage($gameId, $gamePlayerId, $channel, $messageText);
        respond(200, ['status' => 'ok']);
    } catch (GameStateException $e) {
        respond(409, ['status' => 'error', 'message' => $e->getMessage()]);
    } catch (\InvalidArgumentException $e) {
        respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
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
        // Practice bots (issue #140)/auto-pass on empty hand -- see the
        // identical comment on POST /games/play above.
        $autoResult = $games->advanceAutomatedTurns($gameId);
        if ($autoResult !== null) {
            $result = $autoResult;
        }
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
    $stage = (int) ($body['stage'] ?? 0);
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
