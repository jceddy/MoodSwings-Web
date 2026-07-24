#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MoodSwings\Notifications\PushNotificationService;
use MoodSwings\Repository\NotificationCooldownRepository;
use MoodSwings\Repository\NotificationPreferenceRepository;
use MoodSwings\Repository\PushSubscriptionRepository;
use MoodSwings\Repository\QueuedNotificationRepository;

// Meant to run every ~15 minutes via cron -- delivers whatever
// PushNotificationService::notify() queued instead of sending during
// another notification's 5-minute cooldown (see that class's own
// docblock, and migration 0048's queued_notifications table, scoped per
// (user, game) by migration 0049). Example crontab line:
//   */15 * * * * /usr/bin/php /path/to/php-app/bin/send_queued_notifications.php >> /var/log/moodswings-notifications.log 2>&1

$notifications = new PushNotificationService(
    new PushSubscriptionRepository(),
    new NotificationPreferenceRepository(),
    new QueuedNotificationRepository(),
    new NotificationCooldownRepository()
);

$sent = $notifications->flushQueuedNotifications();

echo $sent === 0 ? "No queued notifications to send.\n" : "Sent {$sent} queued notification(s).\n";
