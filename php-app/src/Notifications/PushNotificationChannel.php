<?php

declare(strict_types=1);

namespace MoodSwings\Notifications;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use MoodSwings\Config;
use MoodSwings\Repository\PushSubscriptionRepository;

/**
 * Browser push (Push API + Notifications API via a Service Worker),
 * issue #108's original notification channel -- extracted out of what
 * used to be PushNotificationService itself so NotificationService can
 * fan a notification out to this and Discord\DiscordNotificationChannel
 * alike, sharing the cooldown/queue/preference orchestration instead of
 * each channel duplicating it.
 */
final class PushNotificationChannel implements NotificationChannel
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
    ) {
    }

    public function send(int $userId, array $payload): bool
    {
        $subscriptions = $this->subscriptions->listForUser($userId);
        if ($subscriptions === []) {
            $this->logError("Not sending push (user {$userId}, tag {$payload['tag']}): no push subscriptions on file");
            return false;
        }

        $webPush = $this->webPush();
        if ($webPush === null) {
            $this->logError("Not sending push (user {$userId}, tag {$payload['tag']}): VAPID keys are not configured");
            return false;
        }

        $this->sendNow($webPush, $subscriptions, $payload);

        return true;
    }

    /**
     * @param array<int, array{id: int, endpoint: string, p256dh_key: string, auth_key: string}> $subscriptions
     * @param array{title: string, body: string, url: string, tag: string} $payload
     */
    private function sendNow(WebPush $webPush, array $subscriptions, array $payload): void
    {
        $encodedPayload = json_encode($payload);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                new Subscription($subscription['endpoint'], $subscription['p256dh_key'], $subscription['auth_key'], ContentEncoding::aes128gcm),
                $encodedPayload
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $this->pruneExpired($subscriptions, $report->getEndpoint());
                continue;
            }

            // Anything else (a VAPID key mismatch, an auth error, a
            // malformed payload, the push service itself erroring) was
            // previously dropped here with no trace at all -- the report
            // isn't a PHP exception (WebPush::flush() never throws per
            // message), so nothing upstream ever saw it, and the send
            // looked identical to a quiet success. Logging every
            // non-expired failure is what actually makes a bad send
            // diagnosable instead of just silently not happening.
            $status = $report->getResponse()?->getStatusCode();
            $this->logError(sprintf(
                'Push send failed for endpoint %s: %s (HTTP %s) %s',
                $report->getEndpoint(),
                $report->getReason(),
                $status ?? 'n/a',
                $report->getResponseContent() ?? '',
            ));
        }
    }

    /**
     * @param array<int, array{id: int, endpoint: string, p256dh_key: string, auth_key: string}> $subscriptions
     */
    private function pruneExpired(array $subscriptions, string $expiredEndpoint): void
    {
        foreach ($subscriptions as $subscription) {
            if ($subscription['endpoint'] === $expiredEndpoint) {
                $this->subscriptions->deleteById($subscription['id']);
                return;
            }
        }
    }

    /** Same convention as index.php's logMailError() -- a dedicated log file rather than the general PHP error log, so a noisy push failure (e.g. every subscription for a since-deactivated browser expiring at once) doesn't drown out unrelated errors. */
    private function logError(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
        error_log($line, 3, dirname(__DIR__) . '/notification-errors.log');
    }

    private function webPush(): ?WebPush
    {
        $publicKey = Config::get('VAPID_PUBLIC_KEY', '');
        $privateKey = Config::get('VAPID_PRIVATE_KEY', '');

        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        return new WebPush([
            'VAPID' => [
                'subject' => Config::get('VAPID_SUBJECT', 'mailto:support@example.com'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }
}
