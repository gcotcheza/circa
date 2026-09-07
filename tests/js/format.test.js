import assert from 'node:assert/strict'
import test from 'node:test'

import { band, bestEstimate } from '../../resources/js/lib/format.js'

/**
 * resources/js/lib/format.js — the two ways this app is allowed to say a band.
 *
 * `band` prints both ends and is what the meal cards showed alone, which is the
 * bug: "588–857 kcal" was read as 857 and meals were skipped to make room for a
 * number nothing had claimed. `bestEstimate` leads with the midpoint and demotes
 * the ends, the shape lib/balance.js already gives the day card — so what has to
 * be held here is that the demotion never becomes a DROP, and that the ≈ appears
 * on exactly the figures that were rounded.
 *
 * `decimal` and `numberText` are covered in boxes.test.js, beside the edit boxes
 * they exist for. Run by `npm test` and by scripts/ci.sh.
 */

/** A real meal: six vision-estimated items summed in quadrature (IntakeBand). */
const MEAL = { min: 588, mid: 722.5, max: 857 }

/** A manual entry. The user typed 240 kcal and meant 240 kcal. */
const TYPED = { min: 240, mid: 240, max: 240 }

test('nothing logged is a dash, and there is no width to print under it', () => {
  assert.deepEqual(bestEstimate({ min: null, mid: null, max: null }), { figure: '—', width: null })
  assert.deepEqual(bestEstimate(null), { figure: '—', width: null })
})

test('a typed figure is exact, with no ≈ and no second line', () => {
  const { figure, width } = bestEstimate(TYPED)

  assert.equal(figure, '240')
  assert.ok(!figure.includes('≈'), 'a number the user typed is not an estimate.')

  // `band` would print "240" here, so a width line would only repeat the figure.
  assert.equal(width, null)
  assert.equal(band(TYPED), figure)
})

test('a vision band leads with its midpoint, rounded to ten, behind an ≈', () => {
  assert.deepEqual(bestEstimate(MEAL), { figure: '≈ 720', width: '588–857' })
})

test('the width is `band`, so the two can never disagree', () => {
  for (const value of [MEAL, { min: 0, mid: 95, max: 190 }, { min: 1180, mid: 1400, max: 1620 }]) {
    assert.equal(bestEstimate(value).width, band(value))
  }
})

/**
 * The ≈ and the rounding are one decision. A band narrower than the step is
 * already tighter than the rounding, so rounding it would move the figure
 * further than the band is wide — it keeps its exact number and says nothing
 * approximate, exactly as a typed figure does.
 */
test('a band narrower than the step keeps its exact figure and wears no ≈', () => {
  const narrow = { min: 240, mid: 243, max: 246 }
  const { figure, width } = bestEstimate(narrow)

  assert.equal(figure, '243')
  assert.ok(!figure.includes('≈'), 'a 6 kcal band is not what the ≈ is for.')

  // Demoted, not dropped: the ends are still on screen under the figure.
  assert.equal(width, '240–246')
})

test('the ≈ arrives with the rounding, at a band one step wide', () => {
  assert.equal(bestEstimate({ min: 238, mid: 243, max: 248 }).figure, '243')
  assert.equal(bestEstimate({ min: 232, mid: 243, max: 254 }).figure, '≈ 240')
})

test('thousands are grouped, because the day figure passes through here too', () => {
  assert.equal(bestEstimate({ min: 1904, mid: 2071, max: 2238 }).figure, '≈ 2,070')
})

test('a band()-only claim about grams is untouched by any of this', () => {
  assert.equal(band({ min: 118, mid: 130, max: 142 }, 0, 'g'), '118–142 g')
})
