import { ref } from 'vue'

/**
 * Registering the service worker, and noticing when a new one takes over.
 *
 * REGISTRATION HAPPENS AFTER `load`, ALWAYS: `register()` fetches the script and
 * precaches the whole list on a first visit, competing with the CSS and entry
 * chunk that first paint waits for. Deferring costs nothing — the worker
 * controls the NEXT navigation either way.
 *
 * THE UPDATE FLOW. `skipWaiting()` in `install` and `clients.claim()` in
 * `activate` take control immediately rather than waiting for every tab to
 * close, which on a Home Screen app could be weeks. But a page somebody is
 * looking at is never reloaded: `controllerchange` sets the flag below and the
 * layout offers "Updated — reload", because the one auto-reload that lands on a
 * half-typed dinner cannot be apologised for. It matters because a deploy
 * replaces the build: a page left open still runs, but everything it has not
 * loaded yet belongs to a build that is no longer current.
 * `php artisan build:retain` keeps the last three builds on disk so those lazy
 * imports still resolve instead of 404ing; the prompt stops the drift there.
 *
 * THE BLANK-RELOAD FIX. The prompt was the ONLY way an update was ever taken,
 * and small grey text is easy to ignore across a day of deploys. Three additions
 * that never reload a page anybody is looking at: HIDDEN PAGES TAKE THE UPDATE
 * THEMSELVES, since nobody is mid-anything on a page nobody can see (guarded by
 * `holdUpdate`, so a sheet with typing in it survives a glance at a
 * notification); A bfcache RESTORE IS AN ARRIVAL, because `pageshow` with
 * `persisted` puts a DOM as old as the freeze back on screen with any update
 * since then unapplied; and A MISSING BUILD ASSET IS AN EMERGENCY, NOT A PROMPT
 * — a 404 under /build/ means this document names files that no longer exist, a
 * dead button or a blank screen that will not fix itself, so it reloads at the
 * first safe moment.
 */

/** True once a NEW worker has taken over a page that already had one. */
export const updateReady = ref(false)

/**
 * Not cosmetic: this page references build assets the server no longer has.
 * Set from the worker's `build-missing` message.
 */
export const updateUrgent = ref(false)

/**
 * How many things on screen a reload would destroy. A counter, not a boolean:
 * the review sheet can sit over a meal sheet, and the second one closing must
 * not clear the first one's claim.
 */
let holds = 0

/**
 * "Do not reload while this is on screen." Returns the release. Only the three
 * surfaces holding unsaved typing call it — meal sheet, photo capture, proposal
 * review. Everything else is a read, or the on-disk queue that survives reloads.
 */
export function holdUpdate() {
  holds += 1

  let released = false

  return () => {
    if (released) return

    released = true
    holds = Math.max(0, holds - 1)
  }
}

export function registerServiceWorker() {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return

  /*
   * Already controlled at load? That is the whole trick for telling an UPDATE
   * from a FIRST INSTALL: `clients.claim()` fires `controllerchange` in both,
   * and "Updated — reload" on a first open is a lie nobody can check.
   */
  const wasControlled = navigator.serviceWorker.controller !== null

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!wasControlled) return

    updateReady.value = true

    // Backgrounded already? Nothing to interrupt, so take it rather than
    // prompt an empty room.
    takeUpdateIfSafe()
  })

  /*
   * The worker's own reports. One message so far: a build asset 404'd, so this
   * document survived a deploy and the parts it has not loaded yet are gone.
   */
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type !== 'build-missing') return

    updateReady.value = true
    updateUrgent.value = true

    takeUpdateIfSafe()
  })

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
      // Not a failed app: it just works online-only. Worth a log line, never a
      // dialog.
      console.warn('[health] service worker registration failed:', error)
    })
  })

  /*
   * Going away is the safe moment: on a home-screen PWA, `hidden` is how every
   * session ends — there is no tab close to wait for.
   */
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') takeUpdateIfSafe()
  })

  /*
   * Back from the back/forward cache: the DOM is as old as the freeze, so an
   * update that landed since sits unapplied on a page that looks live. Re-check
   * the worker too — a frozen page never ran the navigation that would have.
   */
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return

    navigator.serviceWorker.getRegistration().then(
      (registration) => registration?.update(),
      () => {},
    )

    takeUpdateIfSafe()
  })
}

/**
 * Reload only when nothing on screen is worth keeping. "Safe" is deliberately
 * narrow: an update is ready, nothing has taken a hold, and either the page is
 * hidden or the update is urgent (a build asset is already missing, so the page
 * is broken whether or not it reloads).
 */
function takeUpdateIfSafe() {
  if (!updateReady.value) return

  if (holds > 0) return

  if (document.visibilityState !== 'hidden' && !updateUrgent.value) return

  if (!mayAutoReload()) return

  reloadForUpdate()
}

/** Auto-reloads only. A reload the user asked for is never rate-limited. */
let autoReloaded = false

const AUTO_RELOAD_KEY = 'health:last-auto-reload'

/** No second automatic reload within this many milliseconds. */
const AUTO_RELOAD_COOLDOWN = 60_000

/**
 * The loop guard. An automatic reload is triggered by the very condition it is
 * meant to fix, so when it does not fix it (the file really is gone, the deploy
 * is half-finished) the fresh page hits the same condition and reloads again,
 * never rendering long enough to be read or reported. Hence once per page, and
 * not twice inside a minute across pages: past that the user gets the ordinary
 * "Updated — reload" prompt and stays in control, which is also the state the
 * boot splash's eight-second message can appear in and say something true.
 */
function mayAutoReload() {
  if (autoReloaded) return false

  try {
    const last = Number(window.sessionStorage.getItem(AUTO_RELOAD_KEY) ?? 0)

    if (Number.isFinite(last) && Date.now() - last < AUTO_RELOAD_COOLDOWN) return false

    window.sessionStorage.setItem(AUTO_RELOAD_KEY, String(Date.now()))
  } catch {
    // Private mode, storage disabled: the once-per-page guard is the half of
    // this that cannot be taken away.
  }

  autoReloaded = true

  return true
}

export function reloadForUpdate() {
  window.location.reload()
}
