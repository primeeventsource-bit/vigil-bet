/*
 * The service worker. Its only job is to show a notification the server
 * pushed and to open the right page when it is tapped.
 *
 * Deliberately small. A service worker that cached pages would start
 * serving a stale odds board from disk, and a stale price is the one
 * thing this product must never show.
 */

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch (e) {
        // A push we cannot read is not shown. An empty notification is
        // worse than none.
        return;
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Alert', {
            body: payload.body || '',
            // The tag collapses repeats of the same subject into one
            // notification rather than stacking them.
            tag: payload.tag || undefined,
            data: { url: payload.url || '/' },
            badge: '/images/brand/vigil-favicon.svg',
            icon: '/images/brand/vigil-favicon.svg',
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            // Focus a tab that is already open rather than opening a
            // fourth copy of the dashboard.
            for (const client of windows) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }

            return self.clients.openWindow(url);
        })
    );
});
