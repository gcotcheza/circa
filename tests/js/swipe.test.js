import assert from 'node:assert/strict'
import test from 'node:test'

import { SWIPE_MAX_Y, SWIPE_MIN_X, startsInsideScroller, swipeIntent } from '../../resources/js/lib/swipe.js'

/**
 * resources/js/lib/swipe.js — when a drag on the day view is a day change.
 * The bug never showed in the gesture maths: dragging the "Log again" strip
 * sideways navigated, `go()`'s re-render reset the strip to its first card, and
 * nothing threw — only somebody using it would notice. The exemption is the fix.
 * Plain objects suffice: a parent chain, two sizes and an overflow need no
 * browser. Run by `npm test`, and by scripts/ci.sh alongside the PHP suite.
 */

/** A node in the chain: `scrolls` is the pair that makes it a real scroller. */
function node({ overflowX = 'visible', scrollWidth = 100, clientWidth = 100, parent = null } = {}) {
  return { overflowX, scrollWidth, clientWidth, parentElement: parent }
}

const styleOf = (el) => ({ overflowX: el.overflowX })

const chain = (...nodes) => {
  nodes.forEach((one, index) => {
    one.parentElement = nodes[index + 1] ?? null
  })

  return nodes[0]
}

// --- the gesture ---------------------------------------------------------

test('a short drag is not a day change', () => {
  assert.equal(swipeIntent({ dx: SWIPE_MIN_X - 1, dy: 0 }), null)
  assert.equal(swipeIntent({ dx: -(SWIPE_MIN_X - 1), dy: 0 }), null)

  // Exactly the threshold counts: it is a floor, not a gap.
  assert.equal(swipeIntent({ dx: SWIPE_MIN_X, dy: 0 }), 'previous')
})

test('a drag that is mostly down the page is a scroll', () => {
  assert.equal(swipeIntent({ dx: 200, dy: SWIPE_MAX_Y + 1 }), null)
  assert.equal(swipeIntent({ dx: 200, dy: -(SWIPE_MAX_Y + 1) }), null)
  assert.equal(swipeIntent({ dx: 200, dy: SWIPE_MAX_Y }), 'previous')
})

test('right goes back a day and left goes forward', () => {
  assert.equal(swipeIntent({ dx: 120, dy: 10 }), 'previous')
  assert.equal(swipeIntent({ dx: -120, dy: 10 }), 'next')
})

test('a drag that started in a strip is never a day change, however far it went', () => {
  // The whole bug in one assertion: a textbook swipe, owned by its scroller.
  assert.equal(swipeIntent({ dx: 400, dy: 0, exempt: true }), null)
  assert.equal(swipeIntent({ dx: -400, dy: 0, exempt: true }), null)
})

// --- what counts as a strip ----------------------------------------------

test('a drag inside a scrolled-out strip is exempt', () => {
  const root = node()
  const strip = node({ overflowX: 'auto', scrollWidth: 900, clientWidth: 340 })
  const card = node()

  chain(card, strip, root)

  assert.equal(startsInsideScroller(card, root, styleOf), true)

  // `scroll` is the same claim as `auto`; `hidden` and `clip` are not.
  strip.overflowX = 'scroll'
  assert.equal(startsInsideScroller(card, root, styleOf), true)

  strip.overflowX = 'hidden'
  assert.equal(startsInsideScroller(card, root, styleOf), false)
})

test('a strip with nothing to scroll to is not a strip', () => {
  // Declared scrollable with nothing to scroll to: an ordinary page swipe.
  const root = node()
  const strip = node({ overflowX: 'auto', scrollWidth: 320, clientWidth: 340 })

  assert.equal(startsInsideScroller(node({ parent: chain(strip, root) }), root, styleOf), false)
})

test('the walk stops at the wrapper and does not leave the page', () => {
  // A scroller above the wrapper (app shell, document) must not exempt the page.
  const outer = node({ overflowX: 'auto', scrollWidth: 900, clientWidth: 340 })
  const root = node()
  const card = node()

  chain(card, root, outer)

  assert.equal(startsInsideScroller(card, root, styleOf), false)
})

test('a drag on ordinary page content is a day change', () => {
  const root = node()
  const card = node()

  assert.equal(startsInsideScroller(chain(card, root), root, styleOf), false)

  assert.equal(startsInsideScroller(null, root, styleOf), false)
})
