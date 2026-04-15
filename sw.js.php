<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/javascript');
header('Cache-Control: no-cache'); // Service worker should not be cached

$base = rtrim($config['base_url'], '/');
?>
const APP_VERSION = '2.5.9';
const CACHE_NAME = `safetyflash-v${APP_VERSION}`;
const BASE_URL = '<?= $base ?>';

const STATIC_ASSETS = [
    '<?= $base ?>/offline.html',
    '<?= $base ?>/assets/css/global.css',
    '<?= $base ?>/assets/css/nav.css',
    '<?= $base ?>/assets/css/list.css',
    '<?= $base ?>/assets/css/modals.css',
    '<?= $base ?>/assets/js/mobile.js',
    '<?= $base ?>/assets/js/vendor/html2canvas.min.js',
    '<?= $base ?>/assets/fonts/OpenSans-Light.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-Regular.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-SemiBold.woff2',
    '<?= $base ?>/assets/fonts/OpenSans-Bold.woff2',
    '<?= $base ?>/assets/img/icons/pwa-icon-192.png',
    '<?= $base ?>/assets/img/icons/pwa-icon-512.png',
    '<?= $base ?>/assets/img/icons/list_icon.png',
    '<?= $base ?>/assets/img/icons/add_new_icon.png'
];

// Install - cache static assets and activate immediately
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return Promise.all(
                    STATIC_ASSETS.map(url => {
                        return cache.add(url).catch(err => {
                            console.warn('Failed to cache:', url, err);
                        });
                    })
                );
            })
            .then(() => self.skipWaiting()) // Activate new SW immediately
    );
});

// Activate - clean old caches and notify clients
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key.startsWith('safetyflash-') && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => {
            // Notify all clients about the update
            return self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'SW_UPDATED', version: APP_VERSION });
                });
            });
        }).then(() => self.clients.claim())
    );
});

// Fetch - network first, fallback to cache
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    if (event.request.url.includes(BASE_URL + '/app/api/')) return;

    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.ok) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request)
                    .then(cachedResponse => {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        if (event.request.mode === 'navigate') {
                            return caches.match('<?= $base ?>/offline.html');
                        }
                        return new Response('Offline - no cached version available', {
                            status: 503,
                            statusText: 'Service Unavailable',
                            headers: new Headers({ 'Content-Type': 'text/plain' })
                        });
                    });
            })
    );
});

// Listen for messages from clients
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({ version: APP_VERSION });
    }
});