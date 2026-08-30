/** Admin worker: private PMS responses are always handled by the network. */
const CACHE_PREFIX = 'kfs-admin-';
const CACHE = CACHE_PREFIX + 'v2';
const IMMUTABLE_CDN_HOSTS = new Set(['cdn.jsdelivr.net']);

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(Promise.resolve());
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => key.startsWith('kfs-admin-') && key !== CACHE).map(key => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  // Only versioned third-party libraries are cacheable. Admin HTML, APIs,
  // receipts, and exports fall through to the browser's normal network path.
  if (!IMMUTABLE_CDN_HOSTS.has(url.hostname)) return;
  event.respondWith(
    caches.match(request).then(cached => cached || fetch(request).then(response => {
      if (response.ok) caches.open(CACHE).then(store => store.put(request, response.clone()));
      return response;
    }))
  );
});
