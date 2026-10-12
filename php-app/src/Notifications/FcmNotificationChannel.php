<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use MoodSwings\Config;
use MoodSwings\Repository\DevicePushTokenRepository;

/**
 * Native push through Firebase Cloud Messaging (HTTP v1), for the Unity app's Android installs: every
 * registration token a user has (DevicePushTokenRepository) gets the notification. Configured with
 * FCM_SERVICE_ACCOUNT_FILE (a Firebase service-account JSON key; its project_id is used unless FCM_PROJECT_ID
 * overrides it). Without one -- or for a user with no registered device -- it is a no-op (false), like the other
 * channels, never a failure.
 *
 * The payload's title/body show as the notification; its 'url' and 'tag' ride along as data so the app can open
 * the right screen when it's tapped (and a repeat of the same tag replaces the earlier notification on the device).
 */
final class FcmNotificationChannel implements NotificationChannel
{
    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /**
     * Error statuses FCM gives for a token that will never work again. (INVALID_ARGUMENT is left out on purpose: it
     * also means a malformed message, and a bug there must not delete every user's good token.)
     */
    private const DEAD_TOKEN_STATUSES = ['UNREGISTERED', 'NOT_FOUND'];

    public function __construct(
        private readonly DevicePushTokenRepository $tokens,
        private readonly ?FcmAccessTokenProvider $accessTokens = null,
        private readonly Client $http = new Client(),
    ) {
    }

    public function send(int $userId, array $payload): bool
    {
        $provider = $this->accessTokens ?? $this->providerFromConfig();
        if ($provider === null) {
            return false;
        }

        $devices = $this->tokens->listForUser($userId);
        if ($devices === []) {
            return false;
        }

        $projectId = Config::get('FCM_PROJECT_ID', '') ?: $provider->projectId();
        $accessToken = $provider->token();
        if ($projectId === null || $projectId === '' || $accessToken === null) {
            $this->logError("Not sending FCM push (user {$userId}): the Firebase project or credentials aren't usable");
            return false;
        }

        foreach ($devices as $device) {
            $this->sendTo($device, $payload, $projectId, $accessToken);
        }

        return true;
    }

    /**
     * @param array{id: int, platform: string, token: string} $device
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function sendTo(array $device, array $payload, string $projectId, string $accessToken): void
    {
        try {
            $this->http->post(sprintf(self::SEND_URL, $projectId), [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
                'json' => ['message' => [
                    'token' => $device['token'],
                    'notification' => ['title' => $payload['title'], 'body' => $payload['body']],
                    'data' => ['url' => $payload['url'], 'tag' => $payload['tag']],
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => ['tag' => $payload['tag']],
                    ],
                ]],
            ]);
        } catch (BadResponseException $e) {
            $status = $this->errorStatus($e);
            if (in_array($status, self::DEAD_TOKEN_STATUSES, true)) {
                $this->tokens->deleteById($device['id']);
                return;
            }

            $this->logError(sprintf('FCM send failed for device %d: HTTP %d %s', $device['id'], $e->getResponse()->getStatusCode(), (string) $e->getResponse()->getBody()));
        } catch (GuzzleException $e) {
            $this->logError("FCM send failed for device {$device['id']}: " . $e->getMessage());
        }
    }

    /** The error.status string FCM puts in its error body ('UNREGISTERED', 'INVALID_ARGUMENT', ...), or null. */
    private function errorStatus(BadResponseException $e): ?string
    {
        $body = json_decode((string) $e->getResponse()->getBody(), true);
        $status = $body['error']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function providerFromConfig(): ?FcmAccessTokenProvider
    {
        $file = Config::get('FCM_SERVICE_ACCOUNT_FILE', '');

        return $file === null || $file === '' ? null : new FcmAccessTokenProvider($file);
    }

    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/notification-errors.log');
    }
}
