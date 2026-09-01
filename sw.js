/**
 * Kanchi Farm Stay — Service Worker
 * Cache-first for static assets, network-first for HTML pages.
 */

const CACHE_PREFIX = 'kfs-public-';
const CACHE = CACHE_PREFIX + 'v2';
const STATIC = [
  '/',
  '/index.html',
  '/accommodations.html',
  '/contact.html',
  '/gallery.html',
  '/food-menu.html',
  '/style.css',
  '/mobile.css',
  '/script.js',
  '/date-utils.js',
  '/assets/images/farm-hero.jpg',
  '/assets/images/logo.png',
  '/offline.html',
];

// Install: pre-cache shell
self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC.filter(u => !u.includes('logo'))))
  );
});

// Activate: clear old caches
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => (k === 'kfs-v1' || k.startsWith('kfs-public-')) && k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch strategy
self.addEventListener('fetch', e => {
  const { request } = e;
  const url = new URL(request.url);

  // Skip non-GET and cross-origin
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/channel-manager/') || url.pathname.endsWith('.php')) return;

  // HTML pages: network-first, fallback to cache, then offline page
  if (request.headers.get('accept')?.includes('text/html')) {
    e.respondWith(
      fetch(request)
        .then(res => {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(request, clone));
          return res;
        })
        .catch(() => caches.open(CACHE).then(cache => cache.match(request).then(r => r || cache.match('/offline.html'))))
    );
    return;
  }

  // Static assets: cache-first
  e.respondWith(
    caches.open(CACHE).then(cache => cache.match(request).then(cached => {
      if (cached) return cached;
      return fetch(request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          cache.put(request, clone);
        }
        return res;
      });
    }))
  );
});
