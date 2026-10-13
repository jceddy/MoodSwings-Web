<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Mints the OAuth2 access token Firebase Cloud Messaging's HTTP v1 API needs, from a Google service-account JSON
 * key: a short-lived RS256-signed JWT is exchanged at Google's token endpoint. Done with ext-openssl and Guzzle
 * (both already required) rather than adding Google's client library for one call.
 *
 * Tokens last an hour; one is kept in a file in the system temp directory (keyed by the key's client email) so a
 * burst of notifications across separate PHP requests doesn't exchange a JWT each time.
 */
final class FcmAccessTokenProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Renew this long before the token really expires, so a send never races the expiry. */
    private const EXPIRY_MARGIN_SECONDS = 120;

    /**
     * @param string $serviceAccount The service-account key: a path to its JSON file, or the JSON text itself (what a
     *                               deployment keeps in one .env line, since .env can't hold a multi-line file).
     */
    public function __construct(
        private readonly string $serviceAccount,
        private readonly Client $http = new Client(),
        private readonly ?string $cacheDirectory = null,
    ) {
    }

    /** The project id the key belongs to, or null if the key file can't be read. */
    public function projectId(): ?string
    {
        $key = $this->loadKey();

        return $key['project_id'] ?? null;
    }

    /** A usable access token, or null if the key is unreadable or Google refused the exchange. */
    public function token(): ?string
    {
        $key = $this->loadKey();
        if ($key === null) {
            return null;
        }

        $cacheFile = $this->cachePath($key['client_email']);
        $cached = $this->readCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $assertion = $this->signedJwt($key);
        if ($assertion === null) {
            return null;
        }

        try {
            $response = $this->http->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logError('FCM token exchange failed: ' . $e->getMessage());
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        $token = $body['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->logError('FCM token exchange returned no access_token');
            return null;
        }

        $this->writeCache($cacheFile, $token, time() + (int) ($body['expires_in'] ?? 3600) - self::EXPIRY_MARGIN_SECONDS);

        return $token;
    }

    /** @return array{client_email: string, private_key: string, project_id?: string}|null */
    private function loadKey(): ?array
    {
        $json = str_starts_with(ltrim($this->serviceAccount), '{')
            ? $this->serviceAccount
            : (is_readable($this->serviceAccount) ? (string) file_get_contents($this->serviceAccount) : '');

        $key = json_decode($json, true);
        if (!is_array($key) || !isset($key['client_email'], $key['private_key'])) {
            return null;
        }

        return $key;
    }

    /** @param array{client_email: string, private_key: string} $key */
    private function signedJwt(array $key): ?string
    {
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(json_encode([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        if (!openssl_sign("{$header}.{$claims}", $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            $this->logError('FCM JWT signing failed (is the service account private_key valid?)');
            return null;
        }

        return "{$header}.{$claims}." . $this->base64Url($signature);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function cachePath(string $clientEmail): string
    {
        $directory = $this->cacheDirectory ?? sys_get_temp_dir();

        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'moodswings-fcm-' . hash('sha256', $clientEmail) . '.json';
    }

    private function readCache(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $cached = json_decode((string) file_get_contents($path), true);
        if (is_array($cached) && is_string($cached['token'] ?? null) && ($cached['expires_at'] ?? 0) > time()) {
            return $cached['token'];
        }

        return null;
    }

    private function writeCache(string $path, string $token, int $expiresAt): void
    {
        @file_put_contents($path, json_encode(['token' => $token, 'expires_at' => $expiresAt]), LOCK_EX);
    }

    /** Same convention as PushNotificationChannel::logError() -- the shared notification-errors.log. */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/notification-errors.log');
    }
}
