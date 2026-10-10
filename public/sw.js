// CardTrail service worker: shows push notifications and opens the right page when one is tapped.
// v1 deliberately caches nothing for offline use.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'CardTrail', {
            body: payload.body || '',
            icon: payload.icon || '/icons/icon-192.png',
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL((event.notification.data && event.notification.data.url) || '/dashboard', self.location.origin);

    // Only ever open CardTrail's own pages, whatever a payload says.
    if (url.origin !== self.location.origin) {
        return;
    }

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of windows) {
                if ('focus' in client) {
                    await client.focus();

                    return 'navigate' in client ? client.navigate(url.href) : undefined;
                }
            }

            return self.clients.openWindow(url.href);
        })(),
    );
});
