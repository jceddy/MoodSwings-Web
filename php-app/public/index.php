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
use MoodSwings\Mail\Mailer;
use MoodSwings\Repository\EmailVerificationRepository;
use MoodSwings\Repository\SessionRepository;
use MoodSwings\Repository\UserRepository;

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
 * @throws \Throwable if the email fails to send
 */
function sendVerificationEmail(array $user, string $token): void
{
    $verificationUrl = rtrim(Config::get('APP_URL', ''), '/') . '/verify-email?token=' . urlencode($token);

    (new Mailer())->sendVerificationEmail($user['email'], $user['username'], $verificationUrl);
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
        respond(200, ['status' => 'ok', 'message' => 'Email verified. You can now log in.', 'user' => publicUser($user)]);
    } catch (InvalidVerificationTokenException $e) {
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

respond(404, ['status' => 'error', 'message' => 'Not found']);
