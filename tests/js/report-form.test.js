import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

import {
  blockedReason,
  canSubmit,
  ceilingDays,
  customDays,
  payload,
} from '../../resources/js/lib/report-form.js'

/**
 * The Generate control's decisions — resources/js/lib/report-form.js.
 *
 * The form was redesigned after a real user filled it in and went looking for a
 * submit button that did not exist: every range chip WAS the trigger, and the
 * focus chips plus free-text instruction had turned "pick a range" into "fill
 * this in". Range is now a SELECTION submitted by one Generate button, with the
 * focus and instruction folded in. NO RANGE IS SELECTED ON LOAD, because
 * generating fires a model call costing real money and a minute or two — an
 * accidental tap on a pre-selected widest range would burn it on a report nobody
 * wanted. All browser-side with no server-side symptom: a range this screen
 * allowed that the validator refuses is a red box nobody earned, and a dropped
 * instruction is a report that ignored what it was asked. The server's two
 * ceilings run throughout: `full` 92 (Food, or no focus) and `lean` 366 (any
 * other focus), passed in as `focus.maxRangeDays`.
 */

const MAX = { full: 92, lean: 366 }

const PRESETS = [
  { days: 3, label: '3 days' },
  { days: 7, label: '7 days' },
  { days: 14, label: '14 days' },
  { days: 30, label: '30 days' },
]

// --- no range is selected on load; Generate is dead until one is chosen ------

test('no range is selected on load, so Generate is not submittable with nothing chosen', () => {
  // On load selectedDays is null, so canSubmit refuses whatever the focus —
  // keeping the expensive Generate one deliberate range tap away.
  assert.equal(canSubmit({ days: null }, [], MAX), false)
  assert.equal(canSubmit({ days: null }, ['stress'], MAX), false)
  assert.equal(canSubmit({ days: null }, ['food'], MAX), false)
})

test('selecting any preset or a valid custom range makes Generate submittable', () => {
  // A deliberate range tap flips the button live.
  for (const preset of PRESETS) {
    assert.equal(canSubmit({ days: preset.days }, [], MAX), true)
  }

  assert.equal(canSubmit({ from: '2026-08-01', to: '2026-08-10' }, [], MAX), true)
})

// --- which ceiling is in force ----------------------------------------------

test('the ceiling is the server full for no focus and for Food, lean otherwise', () => {
  assert.equal(ceilingDays([], MAX), 92)
  assert.equal(ceilingDays(['food'], MAX), 92)
  assert.equal(ceilingDays(['stress', 'food'], MAX), 92)
  assert.equal(ceilingDays(['stress'], MAX), 366)
  assert.equal(ceilingDays(['stress', 'sleep'], MAX), 366)
})

// --- why a long range is blocked, in the words that say what to do ----------

test('a long range is blocked with the reason that names the fix', () => {
  // Fits: no reason, which is also what makes it selectable.
  assert.equal(blockedReason(30, [], 92), null)
  assert.equal(blockedReason(182, ['stress'], 366), null)
  assert.equal(blockedReason(365, ['stress'], 366), null)

  // Over the full ceiling with nothing focused: pick a focus.
  assert.equal(blockedReason(182, [], 92), 'Pick a focus first')

  // Food is the one focus that keeps the quarter, so its message is specific.
  assert.equal(
    blockedReason(182, ['food'], 92),
    'Not with Food — the food log is too big to cover this long',
  )
})

// --- the custom range's live day count --------------------------------------

test('a custom pair reads out its inclusive length, or null before it is a range', () => {
  assert.equal(customDays('2026-08-03', '2026-08-09'), 7)
  assert.equal(customDays('2026-08-10', '2026-08-10'), 1)

  // Not yet a range: no start, no end, or the two the wrong way round.
  assert.equal(customDays('', '2026-08-10'), null)
  assert.equal(customDays('2026-08-10', ''), null)
  assert.equal(customDays('2026-08-10', '2026-08-03'), null)
})

// --- the payload the button emits -------------------------------------------

test('the payload carries the selected range with the focus and instruction folded in', () => {
  assert.deepEqual(
    payload({ days: 30 }, ['stress'], 'compare my recovery before and after weigh-ins'),
    { days: 30, focus: ['stress'], focusText: 'compare my recovery before and after weigh-ins' },
  )

  // A custom range is folded the same way.
  assert.deepEqual(
    payload({ from: '2026-06-01', to: '2026-06-10' }, ['stress', 'sleep'], 'why'),
    { from: '2026-06-01', to: '2026-06-10', focus: ['stress', 'sleep'], focusText: 'why' },
  )
})

test('an instruction alone, with no focus chip, is a valid submit that keeps the instruction', () => {
  // The capability the user asked for by name: focusText rides independently.
  const body = payload({ days: 14 }, [], '  look closely at my sleep debt  ')

  assert.deepEqual(body, { days: 14, focusText: 'look closely at my sleep debt' })
  assert.equal('focus' in body, false)
  assert.equal(canSubmit({ days: 14 }, [], MAX), true)
})

test('an empty or whitespace-only instruction and no focus fold nothing in', () => {
  assert.deepEqual(payload({ days: 30 }, [], ''), { days: 30 })
  assert.deepEqual(payload({ days: 7 }, [], '   '), { days: 7 })
  assert.deepEqual(payload({ days: 7 }, [], null), { days: 7 })
})

// --- when the button may be pressed -----------------------------------------

test('a selected preset can be submitted, and one beyond the ceiling cannot', () => {
  assert.equal(canSubmit({ days: 30 }, [], MAX), true)

  // A long range with nothing focused exceeds the full ceiling.
  assert.equal(canSubmit({ days: 182 }, [], MAX), false)

  // The same range fits once a non-Food focus lifts the ceiling to lean.
  assert.equal(canSubmit({ days: 182 }, ['stress'], MAX), true)
})

test('a long range that Food puts out of reach cannot become a submittable selection', () => {
  // 6 months of Food is a range the server refuses, so the button must too.
  assert.equal(canSubmit({ days: 182 }, ['food'], MAX), false)
  assert.equal(canSubmit({ days: 365 }, ['stress', 'food'], MAX), false)
})

test('custom is submittable only with a From date and a range that fits', () => {
  // No From date: refused (the case the user cannot see is missing without it).
  assert.equal(canSubmit({ from: '', to: '2026-08-10' }, [], MAX), false)

  // A valid short custom range.
  assert.equal(canSubmit({ from: '2026-08-01', to: '2026-08-10' }, [], MAX), true)

  // Too long for the full ceiling, and still too long even for lean.
  assert.equal(canSubmit({ from: '2020-01-01', to: '2026-08-10' }, [], MAX), false)
  assert.equal(canSubmit({ from: '2020-01-01', to: '2026-08-10' }, ['stress'], MAX), false)

  // Dates the wrong way round never resolve to a range, so never submit.
  assert.equal(canSubmit({ from: '2026-08-10', to: '2026-08-01' }, [], MAX), false)
})

// --- the component wiring, read off the source ------------------------------
//
// These guard that ReportForm.vue still bolts the rules together the redesigned
// way — a selection with NO default, one button disabled until a range is chosen
// — and are whitespace-tolerant so they break on intent, not formatting.

const componentSource = (name) =>
  readFileSync(fileURLToPath(new URL(`../../resources/js/Components/${name}`, import.meta.url)), 'utf8')

test('ReportForm selects NO range on load and never generates on a chip tap', () => {
  const src = componentSource('ReportForm.vue')

  // selectedDays starts null, so the button is disabled until a range is picked.
  assert.match(src, /selectedDays = ref\(null\)/)
  assert.doesNotMatch(src, /defaultRangeDays/)

  // Tapping a chip SELECTS it and announces the selection, in both chip rows.
  assert.match(src, /@click="select\(preset\.days\)"/)
  assert.match(src, /:aria-pressed="selectedDays === preset\.days/)

  // The old instant-trigger is gone: no chip emits generate on its own click.
  assert.doesNotMatch(src, /@click="emit\('generate'/)

  // Exactly one generate emit, and it goes through payload() from the button.
  assert.equal(src.match(/emit\('generate'/g).length, 1)
  assert.match(src, /emit\('generate', payload\(currentRange\.value/)
  assert.match(src, /:disabled="!canGenerate"/)

  // The disabled button says WHY it is inert, while nothing is chosen.
  assert.match(src, /Pick a range to generate/)
  assert.match(src, /v-if="rangeHint"/)
})

test('ReportFocusChips labels its box as the custom instruction, keeping the cap and counter', () => {
  const src = componentSource('ReportFocusChips.vue')

  // Relabelled from the old "Anything particular?" to name the capability.
  assert.doesNotMatch(src, /Anything particular/)
  assert.match(src, /Tell Claude what to focus on/)

  // Placeholder and remaining-chars counter survive the relabel.
  assert.match(src, /compare my recovery before and after/)
  assert.match(src, /remaining < 80/)

  // THE CAP IS THE SERVER'S AND ONLY THE SERVER'S — it arrives with the focus
  // payload, so the component keeps no number of its own to fall out of step.
  // Nor does the box enforce it by refusing the next letter: a native
  // `maxlength` truncates mid-word and says nothing, and a note quietly cut
  // short is a report given less than it was told. HealthReportRequest's own
  // sentence arrives on blur instead, and the counter still runs (through
  // zero, now that it can).
  assert.match(src, /maxTextLength: \{ type: Number, required: true \}/)
  assert.doesNotMatch(src, /maxlength/)
  assert.match(src, /@blur="inline\.check\('focusText'\)"/)
})
