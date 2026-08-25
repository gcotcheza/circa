import assert from 'node:assert/strict'
import test from 'node:test'

import { applyShare, shareOf, unapplyShare } from '../../resources/js/lib/share.js'

/**
 * HALF A PLATE — resources/js/lib/share.js.
 *
 * The item holds the whole plate and the boxes hold what was eaten, so every
 * figure in either meal sheet crosses that boundary out and back. The failure
 * this pins is the one the user watches happen: a value that does not survive
 * the round trip moves under the caret while it is being corrected. It lives in
 * lib/ rather than in the two `.vue` files precisely so it can be tested at all
 * — there is no vitest and no jsdom here (see lib/air-date.js).
 *
 * Run by `npm test`, and by scripts/ci.sh alongside the PHP suite.
 */

test('a whole plate is the identity function, which is nearly every meal', () => {
  assert.equal(applyShare(163.3333, 1), 163.3333)
  assert.equal(unapplyShare(250, 1), 250)

  // A fraction above 1 is not a thing the chips can produce, and is not a
  // reason to start dividing.
  assert.equal(applyShare(250, 1.5), 250)
})

test('an absent share is the whole plate, not none of it', () => {
  // Absent means "nobody shared this", and a 0 here would blank every number
  // on every ordinary meal in the app.
  assert.equal(shareOf({}), 1)
  assert.equal(shareOf({ share_fraction: null }), 1)
  assert.equal(shareOf({ share_fraction: 0.5 }), 0.5)
  assert.equal(shareOf(undefined), 1)
})

test('half a plate is half of every figure that scales', () => {
  assert.equal(applyShare(500, 0.5), 250)
  assert.equal(applyShare(250, 0.5), 125)
  assert.equal(applyShare(33.4, 0.5), 16.7)
})

test('a blank stays blank rather than becoming a zero', () => {
  // "0 kcal" is a claim; a blank box is the absence of one, and the daily total
  // would inherit the difference.
  for (const empty of [null, undefined, '']) {
    assert.equal(applyShare(empty, 0.5), empty)
  }
})

test('the shown figure carries three decimals, which is what the column stores', () => {
  // 1/3 of a plate is not a repeating decimal on screen, and rounding further
  // would make the value fail to come back as itself.
  assert.equal(applyShare(100, 1 / 3), 33.333)
  assert.equal(applyShare(0.0004, 0.5), 0)
})

test('typing the eaten figure states the plate it came off', () => {
  // "I had 100 g" with a half selected says the plate held 200 g.
  assert.equal(unapplyShare(100, 0.5), 200)
  assert.equal(unapplyShare(125, 0.25), 500)
})

test('a value survives the round trip, which is the whole point', () => {
  // Any figure typeable into a box, out to the plate and back: unchanged, or
  // the field rewrites itself under the caret between two keystrokes.
  for (const share of [0.5, 0.25, 1 / 3, 0.75]) {
    for (const typed of [250, 33.4, 1, 0.5, 1200, 163.333]) {
      assert.equal(applyShare(unapplyShare(typed, share), share), typed)
    }
  }
})
