const CACHE_VERSION = '2026-06-26';
const STATIC_CACHE = `padik-static-${CACHE_VERSION}`;
const DEFAULT_ACTION_URL = '/r/general';
const PWA_ASSETS = [
    '/favicon.ico',
    '/favicon.svg',
    '/manifest.webmanifest',
    '/pwa-apple-touch-icon.png',
    '/pwa-icon-192.png',
    '/pwa-icon-512.png',
    '/pwa-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(PWA_ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((cacheNames) =>
                Promise.all(
                    cacheNames
                        .filter((cacheName) => cacheName.startsWith('padik-'))
                        .filter((cacheName) => cacheName !== STATIC_CACHE)
                        .map((cacheName) => caches.delete(cacheName)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetchNavigation(request));

        return;
    }

    const url = new URL(request.url);

    if (url.origin === self.location.origin && isCacheableAsset(url)) {
        event.respondWith(cacheFirst(request));
    }
});

self.addEventListener('push', (event) => {
    event.waitUntil(showPushNotification(event));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(openNotificationTarget(event.notification));
});

async function fetchNavigation(request) {
    try {
        return await fetch(request, { cache: 'no-store' });
    } catch (error) {
        return new Response(offlineHtml(), {
            status: 503,
            headers: {
                'Cache-Control': 'no-store',
                'Content-Type': 'text/html; charset=utf-8',
            },
        });
    }
}

async function cacheFirst(request) {
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        return cachedResponse;
    }

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        const cache = await caches.open(STATIC_CACHE);
        await cache.put(request, response.clone());
    }

    return response;
}

function isCacheableAsset(url) {
    return (
        url.pathname.startsWith('/build/') ||
        PWA_ASSETS.includes(url.pathname)
    );
}

async function showPushNotification(event) {
    if (!self.registration.showNotification) {
        return;
    }

    const payload = parsePushPayload(event.data);
    const actionUrl = safeActionUrl(payload.action_url);
    const title = asString(payload.title, 'Padik');
    const body = asString(payload.body, 'Open Padik to view the latest update.');
    const tag = asString(payload.tag, 'padik-notification');
    const timestamp = Date.parse(asString(payload.timestamp, '')) || Date.now();

    await self.registration.showNotification(title, {
        body,
        tag,
        timestamp,
        icon: '/pwa-icon-192.png',
        badge: '/pwa-icon-192.png',
        data: {
            action_url: actionUrl,
            conversation_id: payload.conversation_id ?? null,
            notification_id: payload.notification_id ?? null,
            sender_id: payload.sender_id ?? null,
            type: payload.type ?? null,
        },
    });
}

function parsePushPayload(data) {
    if (!data) {
        return {};
    }

    try {
        const payload = data.json();

        return isRecord(payload) ? payload : {};
    } catch (error) {
        return {};
    }
}

async function openNotificationTarget(notification) {
    const targetUrl = new URL(
        safeActionUrl(notification.data?.action_url),
        self.location.origin,
    );
    const windowClients = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });

    for (const client of windowClients) {
        if (client.url === targetUrl.href && 'focus' in client) {
            return client.focus();
        }
    }

    if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl.href);
    }

    return undefined;
}

function safeActionUrl(value) {
    const url = asString(value, DEFAULT_ACTION_URL);

    try {
        const parsedUrl = new URL(url, self.location.origin);

        if (parsedUrl.origin !== self.location.origin) {
            return DEFAULT_ACTION_URL;
        }

        return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
    } catch (error) {
        return DEFAULT_ACTION_URL;
    }
}

function asString(value, fallback) {
    return typeof value === 'string' && value.length > 0 ? value : fallback;
}

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function offlineHtml() {
    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">
    <title>Padik is offline</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { align-items: center; background: #111827; color: #f9fafb; display: flex; min-height: 100vh; margin: 0; padding: 24px; }
        main { max-width: 420px; }
        h1 { font-size: 24px; line-height: 1.2; margin: 0 0 12px; }
        p { color: #d1d5db; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    <main>
        <h1>Padik is offline</h1>
        <p>Reconnect to open chats and receive the latest messages. Offline messaging is not available in this version.</p>
    </main>
</body>
</html>`;
}
