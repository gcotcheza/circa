import assert from 'node:assert/strict'
import test from 'node:test'

import { decimal, numberText } from '../../resources/js/lib/format.js'
import { boxText, digitsFor, typedIn } from '../../resources/js/lib/boxes.js'
import { densityFor, payloadFor, roundFor, toPer100g, toQuick } from '../../resources/js/lib/basis.js'

/**
 * DISPLAY PRECISION IN THE MEAL SHEETS — resources/js/lib/boxes.js.
 *
 * The user's screenshot: a Per 100 g row reading "130.8333", "2.3456",
 * "163.3333", tails cut off at the edge of a field a fifth of a phone wide —
 * unreadable, uncorrectable and false, every one arithmetic on a figure with a
 * ±40 kcal band. The fix is display precision; the argument is WHERE. Two things
 * it must not break: (1) a value the user typed is theirs, never rewritten —
 * 2.36 becoming 2.4 under the caret is worse than the tail it fixed; (2) Quick /
 * Per 100 g must post the IDENTICAL payload (lib/basis.js). That settles it:
 * rounding into the STATE breaks on the first toggle, so it lives in the text and
 * the item keeps every digit. Run by `npm test` and by scripts/ci.sh.
 */

// --- the writing half: format.js -------------------------------------------

test('kcal is written whole and a macro to a tenth', () => {
  assert.equal(numberText(130.83333333333334, 0), '131')
  assert.equal(numberText(2.3456, 1), '2.3')
  assert.equal(numberText(163.3333, 0), '163')
  assert.equal(numberText(16.333, 1), '16.3')
})

test('a trailing zero is dropped, because the box is typed in', () => {
  // "120.0" is one backspace in the way of typing "125".
  assert.equal(numberText(120, 1), '120')
  assert.equal(numberText(2.5, 1), '2.5')
  assert.equal(numberText(2.04, 1), '2')
  assert.equal(numberText(100, 0), '100')
})

test('a round ten is not trimmed to a one', () => {
  // The zeros only go back as far as the decimal point; `10` is not `1`.
  assert.equal(numberText(10, 0), '10')
  assert.equal(numberText(1200, 0), '1200')
  assert.equal(numberText(2000, 1), '2000')
})

test('nothing it writes carries a thousands separator', () => {
  // `num(1200)` is "1,200" and `decimal('1,200')` is 1.2 — the prose formatter
  // would divide a pizza by a thousand on the next save.
  for (const value of [1200, 1200.45, 24000, 999.5]) {
    const text = numberText(value, 1)

    assert.ok(!text.includes(','), `${value} was written as ${text}`)
    assert.equal(typeof decimal(text), 'number')
  }
})

test('everything it writes is read back by the one parser', () => {
  for (const value of [0, 0.4, 2.3456, 16.333, 130.83333333333334, 1200.5, 4.9]) {
    for (const digits of [0, 1]) {
      const parsed = decimal(numberText(value, digits))

      assert.ok(Number.isFinite(parsed), `${value} at ${digits} dp did not parse back`)
      assert.ok(Math.abs(parsed - value) <= 0.5 / 10 ** digits, `${value} at ${digits} dp moved too far`)
    }
  }
})

test('a blank stays blank, and is never a zero', () => {
  assert.equal(numberText(null, 0), '')
  assert.equal(numberText(undefined, 1), '')
  assert.equal(numberText('', 1), '')
})

test('a real figure is never rounded away to nothing', () => {
  // "0 kcal" is a claim the food did not make. Extra places go only as far as the
  // row stores: below 0.0005 the column itself holds 0.000.
  assert.equal(numberText(0.4, 0), '0.4')
  assert.equal(numberText(0.04, 0), '0.04')
  assert.equal(numberText(0.004, 1), '0.004')
  assert.equal(numberText(0, 0), '0')
  assert.equal(numberText(0.0001, 0), '0')
})

// --- the policy: which precision belongs to which field ---------------------

test('every spelling of kcal is whole, and everything in grams is a tenth', () => {
  for (const field of ['kcal', 'kcal_per_100g', 'kcal_min', 'kcal_max']) {
    assert.equal(digitsFor(field), 0, field)
  }

  for (const field of ['protein', 'carbs', 'fat', 'grams', 'fat_per_100g', 'portion_g_min', 'protein_g_max']) {
    assert.equal(digitsFor(field), 1, field)
  }
})

// --- whose number is in the box --------------------------------------------

test('a value the app worked out is drawn at the label precision', () => {
  const item = {}

  assert.equal(boxText(item, 'kcal_per_100g', 130.83333333333334), '131')
  assert.equal(boxText(item, 'protein_per_100g', 2.3456), '2.3')
  assert.equal(boxText(item, 'grams', 120), '120')
  assert.equal(boxText(item, 'kcal', null), '')
})

test('what the user typed comes back exactly as they typed it', () => {
  const item = { kcal_per_100g: null }

  // The keypad types a comma; rewriting it as a dot mid-word is the same fault.
  typedIn(item, 'kcal_per_100g', '2,36')
  item.kcal_per_100g = decimal('2,36')
  assert.equal(boxText(item, 'kcal_per_100g', item.kcal_per_100g), '2,36')

  // Their trailing zero, and their half-typed decimal point.
  typedIn(item, 'protein_per_100g', '2.50')
  assert.equal(boxText(item, 'protein_per_100g', 2.5), '2.50')

  typedIn(item, 'protein_per_100g', '2.')
  assert.equal(boxText(item, 'protein_per_100g', 2), '2.')

  // Including after blur: nothing rounds a typed figure later on, either.
  assert.equal(boxText(item, 'protein_per_100g', 2), '2.')
})

test('a cleared box stays cleared rather than filling itself in', () => {
  const item = {}

  typedIn(item, 'fat', '')

  assert.equal(boxText(item, 'fat', null), '')
})

test('the app writing a new value takes the box back', () => {
  const item = {}

  typedIn(item, 'kcal_per_100g', '2.36')

  assert.equal(boxText(item, 'kcal_per_100g', 2.36), '2.36')

  // The text no longer parses to what the item holds, so it is not the user's.
  assert.equal(boxText(item, 'kcal_per_100g', 130.83333333333334), '131')
})

test('one row cannot wear another row\'s typing', () => {
  const rice = {}
  const beer = {}

  typedIn(rice, 'kcal', '250')

  assert.equal(boxText(rice, 'kcal', 250), '250')
  assert.equal(boxText(beer, 'kcal', 250.4), '250')
  assert.equal(boxText(beer, 'kcal', 130.8333), '131')
})

// --- the round trip, which is what decided the architecture -----------------

const weighed = () => ({
  name: 'rice',
  basis: 'per_100g',
  share_fraction: 1,
  kcal: null,
  protein: null,
  carbs: null,
  fat: null,
  grams: 120,
  kcal_per_100g: 130.83333333333334,
  protein_per_100g: 2.3456,
  carbs_per_100g: 16.333,
  fat_per_100g: 3.41666666,
  food_product_id: null,
})

test('the boxes read cleanly while the item keeps every digit it was given', () => {
  const item = weighed()

  assert.equal(boxText(item, 'kcal_per_100g', item.kcal_per_100g), '131')
  assert.equal(boxText(item, 'protein_per_100g', item.protein_per_100g), '2.3')

  assert.equal(payloadFor(item).kcal_per_100g, 130.83333333333334)
  assert.equal(payloadFor(item).protein_per_100g, 2.3456)
})

test('twenty tab taps post the identical payload', () => {
  const item = weighed()
  const first = JSON.stringify(payloadFor(item))

  for (let i = 0; i < 20; i += 1) {
    toQuick(item)
    toPer100g(item)
  }

  assert.equal(JSON.stringify(payloadFor(item)), first)
})

test('and so do twenty tab taps on an item with no stated weight', () => {
  // Nothing holds the exact value here: the total IS the density.
  const item = {
    name: 'cappuccino',
    basis: 'per_100g',
    share_fraction: 1,
    kcal: null,
    protein: null,
    carbs: null,
    fat: null,
    grams: null,
    kcal_per_100g: 163.333,
    protein_per_100g: 2.346,
    carbs_per_100g: 16.333,
    fat_per_100g: 3.417,
    food_product_id: null,
  }

  const first = JSON.stringify(payloadFor(item))

  for (let i = 0; i < 20; i += 1) {
    toQuick(item)
    toPer100g(item)
  }

  assert.equal(JSON.stringify(payloadFor(item)), first)
  assert.equal(boxText(item, 'kcal_per_100g', item.kcal_per_100g), '163')
})

test('ROUNDING INTO THE STATE INSTEAD WOULD CHANGE THE ITEM ON THE FIRST TAP', () => {
  // The rejected design, run, so "round where the value is set" cannot look like
  // the simpler option to the next reader.
  const item = weighed()
  const first = JSON.stringify(payloadFor(item))

  toQuick(item)

  for (const nutrient of ['kcal', 'protein', 'carbs', 'fat']) {
    item[nutrient] = roundFor(nutrient, item[nutrient])
    item[`${nutrient}_per_100g`] = roundFor(nutrient, densityFor(item[nutrient], item.grams))
  }

  toPer100g(item)

  assert.notEqual(JSON.stringify(payloadFor(item)), first)
  assert.equal(payloadFor(item).kcal_per_100g, 131)
  assert.equal(payloadFor(item).protein_per_100g, 2.3)
})

test('and it would move a number the user typed with their own hands', () => {
  // 4.9 g protein typed in Quick over 250 g: density 1.96 g/100 g, 2.0 to a
  // tenth, 5.0 g back.
  const exact = densityFor(4.9, 250)

  assert.equal(boxText({}, 'protein_per_100g', exact), '2')

  const kept = { grams: 250, protein_per_100g: exact, kcal_per_100g: null, carbs_per_100g: null, fat_per_100g: null }
  const flattened = { ...kept, protein_per_100g: roundFor('protein', exact) }

  toQuick(kept)
  toQuick(flattened)

  assert.equal(kept.protein, 4.9)
  assert.equal(flattened.protein, 5)
})
