/**
 * The first few milliseconds, and the last resort. Two unrelated jobs that share
 * one property: both run around `createInertiaApp` rather than inside it, and
 * both exist because of the same reported bug — "reload the page while scrolled
 * down and it is completely white".
 *
 * 1. WHY A RELOAD MUST START AT THE TOP. `@inertiajs/core` sets
 * `history.scrollRestoration = 'manual'`, taking restoration away from the
 * browser and then doing it itself: it saves `documentScrollPosition` into
 * `history.state` on every scroll (debounced 100 ms) and, in
 * `InitialVisit.handleDefault`, restores it when
 * `performance.getEntriesByType('navigation')[0].type === 'reload'`:
 *
 *     page.set(...).then(() => {
 *       if (navigationType.isReload()) Scroll.restore(history.getScrollRegions())
 *     })
 *
 * `Scroll.restore` runs one `requestAnimationFrame` later — one frame after the
 * component swap resolves, before the meal photographs, the two charts and the
 * activity strip have laid out, so `window.scrollTo(0, 2400)` is called against
 * a document a fraction of its eventual height. A desktop engine clamps the
 * offset; on the WebKit that every browser on an iPhone is, an out-of-range
 * programmatic scroll during load leaves the compositor showing an area it has
 * not painted — a white rectangle, no header, no error, exactly the report.
 *
 * So a RELOAD boots at the top. Inertia's scroll behaviour for its own visits is
 * untouched, and so is the back/forward path (`handleBackForward` restores from
 * a history entry the browser really is returning to, against a document about
 * to be rebuilt anyway); only the restore of a position belonging to a layout
 * that does not exist yet is changed. The cost is that pull-to-refresh halfway
 * down the day now returns you to the top, which beats a blank white app.
 *
 * 2. WHY A MOUNT FAILURE MUST DRAW SOMETHING. If Vue throws while mounting,
 * `#app` stays empty forever: no second attempt, no error boundary above it, no
 * server render underneath, and the white rectangle is permanent until the user
 * thinks to force-quit. `renderBootFailure` puts a sentence and a reload link in
 * that box instead, written with `document.createElement` rather than
 * `innerHTML` and containing no interpolated error text, because the one thing
 * worse than a blank screen is a blank screen with an injection in it.
 */

/**
 * Take the scroll position out of a reload. Called before `createInertiaApp`,
 * which is what makes it work: Inertia reads `history.state` when
 * `router.init()` runs, i.e. inside that call.
 */
export function startAtTopOnReload() {
  try {
    if (typeof window === 'undefined') return

    // Belt and braces: if Inertia's module is the thing that failed to load,
    // the browser's own restore into an empty `#app` is the same white screen.
    if (window.history.scrollRestoration) {
      window.history.scrollRestoration = 'manual'
    }

    if (!isReload()) return

    const state = window.history.state

    if (state) {
      /*
       * Zero the two keys Inertia restores from, keeping everything else, above
       * all `page`: the serialised props the app boots from must survive this
       * untouched.
       */
      window.history.replaceState(
        { ...state, documentScrollPosition: { top: 0, left: 0 }, scrollRegions: [] },
        '',
      )
    }

    window.scrollTo(0, 0)
  } catch {
    // A history API that refuses is not a reason to fail to start.
  }
}

/**
 * Was this navigation a reload? The Navigation Timing entry is the modern
 * answer; `performance.navigation` is the deprecated one, kept because it is
 * what answers on older WebKit, which is this file's whole purpose.
 */
function isReload() {
  try {
    const entry = performance.getEntriesByType?.('navigation')?.[0]

    if (entry?.type) return entry.type === 'reload'

    // 1 === TYPE_RELOAD
    return performance.navigation?.type === 1
  } catch {
    return false
  }
}

/**
 * The app could not start. Say so, in the box where the app should have been.
 * A plain `<a>` rather than a button calling `location.reload()`: reloading is
 * what just failed, and a fresh navigation to `/` gets a fresh document with
 * fresh script tags — the actual fix when the cause was a build asset that no
 * longer exists.
 */
export function renderBootFailure(el) {
  try {
    if (!el) return

    el.textContent = ''

    const wrap = document.createElement('div')
    wrap.className = 'mx-auto flex min-h-dvh max-w-xl flex-col items-center justify-center gap-3 px-6 text-center'

    const heading = document.createElement('p')
    heading.className = 'text-base font-semibold'
    heading.textContent = 'This screen failed to load.'

    const body = document.createElement('p')
    body.className = 'text-sm text-stone-600 dark:text-stone-400'
    body.textContent =
      'Nothing you logged has been lost — anything waiting to send is still on this device. Opening the app again usually fixes it.'

    const link = document.createElement('a')
    link.className =
      'rounded-xl bg-teal-600 px-4 py-2 text-sm font-medium text-white transition active:scale-95'
    link.href = '/'
    link.textContent = 'Open the app again'

    wrap.append(heading, body, link)
    el.append(wrap)
  } catch {
    // If even this throws, the blade's boot splash is still on screen with its
    // own reload link — the floor, and it needs no JS.
  }
}
