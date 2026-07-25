<?php

declare(strict_types=1);

namespace MoodSwings;

/**
 * The deployed site's own domain root -- distinct from APP_URL, which
 * (per its own documented convention) includes the PHP app's fixed
 * '/app' path prefix (see "Deployment" in the top-level README). Used
 * anywhere a link needs to point at the static frontend rather than a
 * php-app/ route -- the post-Discord-link lobby redirect
 * (public/index.php) and Discord DM notification links
 * (Discord/DiscordNotificationChannel.php) both need this same
 * derivation, so it lives here rather than being duplicated in each.
 */
final class SiteUrl
{
    public static function root(): string
    {
        $siteUrl = trim((string) Config::get('SITE_URL', ''));
        if ($siteUrl !== '') {
            return rtrim($siteUrl, '/');
        }

        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        return str_ends_with($appUrl, '/app') ? substr($appUrl, 0, -4) : $appUrl;
    }
}
