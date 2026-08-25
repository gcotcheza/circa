import assert from 'node:assert/strict'
import test from 'node:test'

import { arrivalNote } from '../../resources/js/lib/sleep.js'

/**
 * resources/js/lib/sleep.js — the sentence the sleep card says about a night
 * that has not all arrived. Not a crash: an earlier version built the sentence
 * from the SESSION's own total rather than the shortfall that triggered it, so a
 * complete 507-minute night with a mid-sleep gap rendered "Only 8h 27m of this
 * night has arrived", arguing with the figure directly above it. The quantity in
 * the claim has to be the quantity measured. Run by `npm test` and scripts/ci.sh.
 */

const night = (fragment) => ({ totalMinutes: 119, fragment })

test('the sentence counts what is MISSING, never what arrived', () => {
  // 507 minutes unaccounted behind a 119-minute session: the sentence says 506.
  assert.equal(
    arrivalNote(night({ missingMinutes: 506 })),
    'About 8h 26m of this night has not arrived yet — a late sync usually fills it in'
  )

  assert.ok(!arrivalNote(night({ missingMinutes: 506 })).includes('1h 59m'))
})

test('a night the server had nothing to say about gets no sentence', () => {
  assert.equal(arrivalNote(night(null)), null)
  assert.equal(arrivalNote(night(undefined)), null)
  assert.equal(arrivalNote({}), null)
  assert.equal(arrivalNote(null), null)
  assert.equal(arrivalNote(undefined), null)
})

test('a quantity that is not a quantity gets no sentence either', () => {
  // `duration(null)` renders an em dash, and "About — …" is worse than silence.
  for (const missingMinutes of [null, undefined, '506', NaN, Infinity, 0, -5]) {
    assert.equal(
      arrivalNote(night({ missingMinutes })),
      null,
      `missingMinutes ${String(missingMinutes)} should produce no sentence`
    )
  }
})

test('it never mentions the wrist', () => {
  // A Watch on a charger and data still on the phone are the same absence.
  const sentence = arrivalNote(night({ missingMinutes: 240 }))

  assert.ok(!/watch|wrist|charger/i.test(sentence), sentence)
  assert.ok(sentence.includes('a late sync usually fills it in'))
})
