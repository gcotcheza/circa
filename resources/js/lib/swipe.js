/**
 * Whether a drag on the day view means "show me another day".
 *
 * Any horizontal drag becomes a navigation, and a navigation is a full
 * re-render — so a drag that was really scrolling the "Log again" strip rebuilt
 * the page with every inner scroller back at zero, snapping the strip to its
 * first cards. A sideways drag belongs to the scroller it started in, and the
 * decision lives here so it can be checked without a browser.
 */

/** Far enough to be deliberate, level enough not to be a scroll that wandered. */
export const SWIPE_MIN_X = 60
export const SWIPE_MAX_Y = 45

const SCROLLS = new Set(['auto', 'scroll'])

/**
 * Did the gesture begin inside something that scrolls sideways AND has
 * somewhere to scroll to?
 *
 * Measured rather than named, so MemoryPicker's strip, the photo rows and
 * whatever is added next are covered without appearing here.
 * `scrollWidth > clientWidth` is the second half: three cards on a wide screen
 * do not scroll, and a drag across them is an ordinary page swipe. Walks once
 * per touchstart, from the touched element up to `root` inclusive.
 *
 * @param {?Element} target  what the touch landed on
 * @param {?Element} root    the wrapper the page gesture is bound to
 * @param {(node: Element) => {overflowX: string}} styleOf
 */
export function startsInsideScroller(target, root, styleOf = (node) => getComputedStyle(node)) {
  for (let node = target; node; node = node.parentElement) {
    if ((node.scrollWidth ?? 0) > (node.clientWidth ?? 0) && SCROLLS.has(styleOf(node).overflowX)) {
      return true
    }

    if (node === root) return false
  }

  return false
}

/**
 * 'previous' | 'next' | null. A direction and never a date: no route or prop
 * reaches in here, so the only thing this can get wrong is the gesture.
 */
export function swipeIntent({ dx, dy, exempt = false }) {
  if (exempt) return null

  if (Math.abs(dx) < SWIPE_MIN_X || Math.abs(dy) > SWIPE_MAX_Y) return null

  return dx > 0 ? 'previous' : 'next'
}
