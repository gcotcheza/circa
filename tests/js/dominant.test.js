import assert from 'node:assert/strict'
import test from 'node:test'

import { DOMINANT_SHARE, PLATE_BAND_FLOOR_KCAL, dominantItem } from '../../resources/js/lib/dominant.js'

/**
 * WHICH LINE OWNS A PLATE'S RANGE — resources/js/lib/dominant.js.
 *
 * The failure this pins is the one the person watches: a plate reading
 * 923-1487 kcal where one item is 820-1380 and the other 75-135. Quadrature
 * squares each half-width, so the wide line owns nearly all of that spread and
 * a count on it is the only answer worth typing. Rows here are the sheet's own,
 * one photograph's; the arithmetic mirrors App\Services\Rollup\IntakeBand.
 *
 * Run by `npm test`, and by scripts/ci.sh alongside the PHP suite.
 */

/** One row as ProposalReview.vue holds it: absolute kcal for the plate, plus the share. */
const row = (name, kcalMin, kcalMax, share = 1) => ({
  name,
  kcal_min: kcalMin,
  kcal_max: kcalMax,
  share_fraction: share,
})

/** The share, to three places — the sheet only ever says it as a percentage. */
const shareOfResult = (result) => Math.round(result.share * 1000) / 1000

test('nothing to ask about when there are no items', () => {
  assert.equal(dominantItem([]), null)
  assert.equal(dominantItem(null), null)
  assert.equal(dominantItem(undefined), null)
})

test('the real dinner: one item owns nearly the whole range', () => {
  // 923-1487 kcal on the user's own screen, and 98.9% of that spread is the rolls.
  const result = dominantItem([
    row('chicken katsu sushi roll pieces (in tray)', 820, 1380),
    row('miso soup', 75, 135),
  ])

  assert.equal(result.index, 0)
  assert.equal(shareOfResult(result), 0.989)
})

test('a four-item plate names the widest line, not the biggest one', () => {
  const result = dominantItem([
    row('rice', 195, 325),
    row('pork belly', 270, 500),
    row('pickles', 25, 60),
    row('broth', 20, 50),
  ])

  assert.equal(result.index, 1)
  assert.equal(shareOfResult(result), 0.735)
})

test('a plate narrower than the floor is nobody`s problem', () => {
  // 30 kcal of uncertainty is not worth a question, however completely one line owns it.
  assert.equal(dominantItem([row('toast and butter', 100, 160)]), null)

  // The floor is on the PLATE's combined half-width, and it is inclusive.
  assert.equal(dominantItem([row('curry', 901, 1099)]), null)

  const atTheFloor = dominantItem([row('curry', 900, 1100)])

  assert.equal(atTheFloor.index, 0)
  assert.equal(atTheFloor.share, 1)
  assert.equal(PLATE_BAND_FLOOR_KCAL, 100)
})

test('spread evenly, no single line is worth singling out', () => {
  // Halves of 200, 180 and 170: the widest owns 39%, so answering it barely moves the plate.
  assert.equal(dominantItem([
    row('pasta', 100, 500),
    row('sauce', 140, 500),
    row('bread', 160, 500),
  ]), null)
})

test('two lines that each own exactly half: the first one is asked about', () => {
  // Neither is more than half, both are at least half, and the sheet asks about
  // one line at a time — answering either collapses the same amount of spread.
  const result = dominantItem([
    row('pizza', 200, 800),
    row('chips', 300, 900),
  ])

  assert.equal(result.index, 0)
  assert.equal(result.share, DOMINANT_SHARE)
})

test('a manual entry never dominates and never dilutes', () => {
  // min === max is a stated figure, half-width 0: it drops out of the arithmetic
  // entirely, so the rolls still own the same 98.9% they did without it.
  const result = dominantItem([
    row('protein shake (from the label)', 2000, 2000),
    row('chicken katsu sushi roll pieces (in tray)', 820, 1380),
    row('miso soup', 75, 135),
  ])

  assert.equal(result.index, 1)
  assert.equal(shareOfResult(result), 0.989)
})

test('an unfilled box is not a bound', () => {
  // Number(null) is 0, which would make a half-filled line the widest thing here.
  const result = dominantItem([
    row('unknown', null, 1380),
    row('pork belly', 200, 800),
  ])

  assert.equal(result.index, 1)
  assert.equal(result.share, 1)

  // A row carrying no kcal keys at all — a line the user has only named so far.
  assert.equal(dominantItem([{ name: 'unknown' }, row('miso soup', 75, 135)]), null)
})

test('the question is about what was eaten, not about the whole plate', () => {
  // Half the rolls is half their range too, and it stays the line to ask about.
  const shared = dominantItem([
    row('chicken katsu sushi roll pieces (in tray)', 820, 1380, 0.5),
    row('miso soup', 75, 135),
  ])

  assert.equal(shared.index, 0)
  assert.equal(shareOfResult(shared), 0.956)

  // A tenth of that plate is a 28 kcal band: under the floor, so no question.
  assert.equal(dominantItem([
    row('chicken katsu sushi roll pieces (in tray)', 820, 1380, 0.1),
    row('miso soup', 75, 135, 0.1),
  ]), null)
})
