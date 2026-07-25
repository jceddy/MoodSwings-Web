// Browser push notifications (issue #108) -- the Service Worker half of
// Push API + Notifications API + Service Worker. Registered from game.js
// at the site root (scope '/') so a push can be shown, and its click
// handled, regardless of which page happens to be open (or no page at
// all) when it arrives.
//
// Deliberately does nothing on 'install'/'activate' beyond the defaults
// (no asset caching/offline support -- out of scope for this feature) and
// nothing beyond 'push'/'notificationclick' -- see
// PushNotificationChannel.php for the payload shape every push carries:
// {title, body, url, tag}.

self.addEventListener('push', (event) => {
    let payload = { title: 'MoodSwings-Web', body: '' };
    try {
        payload = event.data ? event.data.json() : payload;
    } catch (e) {
        // Non-JSON payload (shouldn't happen -- PushNotificationChannel.php
        // always sends JSON) -- fall back to the generic title/empty body.
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'MoodSwings-Web', {
            body: payload.body || '',
            tag: payload.tag,
            data: { url: payload.url || '/' },
        })
    );
});

// Focuses an already-open tab on the notification's target URL if one
// exists (comparing by path so an open lobby tab is reused rather than
// always opening a fresh one), otherwise opens a new tab.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';

    event.waitUntil(
        (async () => {
            const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of allClients) {
                if (new URL(client.url).pathname === new URL(targetUrl, self.location.origin).pathname && 'focus' in client) {
                    return client.focus();
                }
            }
            return clients.openWindow(targetUrl);
        })()
    );
});
