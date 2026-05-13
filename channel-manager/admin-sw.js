/**
 * Kanchi Farm Stay Admin — Service Worker
 * Enables PWA install + offline shell for the PMS dashboard.
 */

const CACHE = 'kfs-admin-v1';
const SHELL = [
  '/channel-manager/admin.php',
  'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const { request } = e;
  if (request.method !== 'GET') return;

  // Chart.js CDN — cache-first (rarely changes)
  if (request.url.includes('cdn.jsdelivr.net')) {
    e.respondWith(
      caches.match(request).then(r => r || fetch(request).then(res => {
        caches.open(CACHE).then(c => c.put(request, res.clone()));
        return res;
      }))
    );
    return;
  }

  // Admin pages — network-first so data is always fresh
  if (request.url.includes('/channel-manager/')) {
    e.respondWith(
      fetch(request)
        .then(res => {
          if (res.ok) caches.open(CACHE).then(c => c.put(request, res.clone()));
          return res;
        })
        .catch(() => caches.match(request))
    );
  }
});
