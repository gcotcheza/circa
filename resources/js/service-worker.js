/**
 * Health Tracker — service worker.
 *
 * NOT bundled by Vite: App\Http\Controllers\Pwa\ServiceWorkerController serves
 * it verbatim, substituting the two tokens below with the build's version and
 * precache list. A hashed filename could never be updated (the browser looks
 * for the URL it registered), and importing the app's module graph would be a
 * second copy of the app with no DOM.
 *
 * THE RULE THIS WHOLE FILE EXISTS TO OBEY: nothing authenticated is ever
 * cached. A stale page shows yesterday's calories as today's with no way for
 * the user to tell, which costs far more than a cold fetch on a phone that is
 * almost always online. So the cache holds only content-hashed build assets,
 * where the URL IS the version, and a static offline page with no data in it.
 *
 * | Request                              | Strategy                          |
 * | ------------------------------------ | --------------------------------- |
 * | /build/*  (hashed, immutable)        | cache-first, runtime-filled       |
 * | precached statics (icons, manifest)  | cache-first                       |
 * | navigations (HTML)                   | network-only, /offline on failure |
 * | Inertia XHR (X-Inertia)              | not intercepted                   |
 * | /api/* (incl. /api/ingest)           | not intercepted                   |
 * | anything not GET                     | not intercepted                   |
 * | cross-origin                         | not intercepted                   |
 *
 * "Not intercepted" means neither `respondWith` nor `fetch` is called, so the
 * request behaves as if no worker were installed — stronger than a network-only
 * handler, because no code path here can answer it from a cache.
 *
 * THERE IS NO `sync` HANDLER and cannot be: Background Sync does not exist in
 * WebKit, which is what an iPhone runs whichever browser installed the app; the
 * queue is in-page IndexedDB flushed on `online` and on foreground
 * (resources/js/lib/queue.js), and a `sync` listener would be dead code that
 * reads like a working feature.
 */

const VERSION = '__SW_VERSION__'
const PREFIX = 'health-tracker-'
const CACHE = PREFIX + VERSION

const OFFLINE_URL = '/offline'

/** Injected from the Vite manifest — see App\Services\Pwa\BuildAssets. */
const PRECACHE = __SW_PRECACHE__

/**
 * Install: fill the cache, then take over immediately.
 *
 * `allSettled`, not `all`: one 404 in the precache list (a renamed icon, a
 * stale manifest mid-deploy) must not abort the install, since a failed install
 * means NO worker and the offline page is lost over a missing PNG.
 * `cache: 'reload'` bypasses the HTTP cache — otherwise the worker installs a
 * copy cached before the deploy, the one way a hashed URL still goes stale.
 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE)

      await Promise.allSettled(
        PRECACHE.map((url) => cache.add(new Request(url, { cache: 'reload' })))
      )

      await self.skipWaiting()
    })()
  )
})

/**
 * Activate: drop every older cache, then claim the open pages.
 *
 * skipWaiting + claim rather than waiting for every tab to close, which on a
 * home-screen app means "until the user swipes it away" — weeks. The page is
 * told (`controllerchange`) and offers a reload; it is NOT reloaded from under
 * the user (see resources/js/lib/sw.js). Only our prefix is deleted: a future
 * feature on THIS origin might open a cache of its own.
 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys()

      await Promise.all(
        names
          .filter((name) => name.startsWith(PREFIX) && name !== CACHE)
          .map((name) => caches.delete(name))
      )

      await self.clients.claim()
    })()
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request

  // Writes are never intercepted: the offline story for a logged meal is the
  // IndexedDB queue, not an opaque replay from here.
  if (request.method !== 'GET') return

  const url = new URL(request.url)

  // Open Food Facts, and anything else off-origin.
  if (url.origin !== self.location.origin) return

  // Authenticated JSON, plus /api/ingest, which is server-to-server and has no
  // business meeting a browser cache.
  if (url.pathname.startsWith('/api/')) return

  // Inertia's own XHR: it carries props, so a cached one would be a screenful
  // of stale numbers.
  if (request.headers.get('X-Inertia')) return

  if (isBuildAsset(url) || PRECACHE.includes(url.pathname)) {
    event.respondWith(cacheFirst(request))

    return
  }

  if (request.mode === 'navigate') {
    event.respondWith(navigate(request))
  }

  // Everything else falls through to the network untouched.
})

/**
 * Content-hashed and served `immutable` by nginx, so the cache can answer
 * without asking: a changed file is a changed URL, by construction.
 */
function isBuildAsset(url) {
  return url.pathname.startsWith('/build/')
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE)

  const hit = await cache.match(request)

  if (hit) return hit

  const response = await fetch(request)

  /*
   * A 404 ON A CONTENT-HASHED FILE IS NOT A MISSING FILE: the URL carries the
   * hash, so the only way here is a document, or a running bundle, that
   * survived a deploy which replaced its build. The user gets a button that
   * does nothing (a lazy chunk that will not import) or, for the entry chunk, a
   * blank white page, and neither recovers on its own or leaves a trace anybody
   * looks at. `php artisan build:retain` keeps the last three builds, so it
   * should be rare; the page is told because it is the only thing that can act
   * — see the `build-missing` handler in resources/js/lib/sw.js.
   */
  if (response.status === 404 && isBuildAsset(new URL(request.url))) {
    await announceMissingBuildAsset(request.url)
  }

  // 200 only: a 206 (a range-requested wasm binary) is a fragment, and caching
  // it as if it were the file is how a decoder fails to instantiate.
  if (response.status === 200 && response.type === 'basic') {
    cache.put(request, response.clone())
  }

  return response
}

/**
 * Tell every open page that this document's build is gone from the server.
 *
 * `includeUncontrolled` because the page about to break may have loaded before
 * this worker claimed it. Failures are swallowed: throwing inside a fetch
 * handler turns a recoverable 404 into a network error.
 */
async function announceMissingBuildAsset(url) {
  try {
    const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' })

    for (const client of clients) {
      client.postMessage({ type: 'build-missing', url })
    }
  } catch {
    // Nothing to do, and nowhere to say it.
  }
}

/**
 * A page: always the network, and the offline page when there isn't one.
 *
 * It never serves a cached copy of a real page: the fallback is static, holds
 * no numbers, and says the queue is holding what was logged.
 */
async function navigate(request) {
  try {
    return await fetch(request)
  } catch {
    const cache = await caches.open(CACHE)

    const offline = await cache.match(OFFLINE_URL)

    return offline ?? Response.error()
  }
}
