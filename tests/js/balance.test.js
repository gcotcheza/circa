import assert from 'node:assert/strict'
import test from 'node:test'

import { SCALE_FLOOR, SIDE_FLOOR, anchorWord, heroFigure, meter, meterLabel, paceNote, paintFor, rangeLine } from '../../resources/js/lib/balance.js'

/**
 * resources/js/lib/balance.js — the day card's headline, checked as arithmetic.
 *
 * The picture is a METER, described by the person who uses it as "the visual
 * slider": an axis with break-even at its centre and the day drawn as a bar
 * growing out of it, left for under the burn and right for over. Everything it
 * claims is computed here — how far each side reaches, where the solid part
 * stops and the fade across the intake's band begins, which ruler both sides
 * share. A component cannot be asked that with no browser in CI; this module
 * can, and the component is then two divs at percentages handed to it.
 *
 * Several cases use REAL DAYS read off production (2026-08-07 to 2026-08-10):
 * a synthetic surplus is one somebody chose, these are what the app had to draw.
 * Run by `npm test` and by scripts/ci.sh alongside the PHP suite.
 */

/** The shape App\Services\Reporting\EnergyBalance puts on the wire. */
const balance = (direction, lo, hi, min, max, provisional = false) => ({
  direction,
  lo,
  hi,
  min,
  max,
  provisional,
})

/* -------------------------------------------------------------------------
 * THE HERO NUMBER — ONE unsigned number, deliberately. The version this replaces
 * put the whole signed band in the big slot, rendering as "+498−738" and
 * "−544−638", where the dash reads as a minus. The hero is now the CENTRE of the
 * two magnitudes, rounded to ten, with an ≈; the last sign came off at the
 * user's request, the word, arrow, colour and side saying the direction four
 * times before the digits.
 * ---------------------------------------------------------------------------- */

/** A sign glued to a range: "+709–949", "−544−638" — the shape the hero must
 * never be. Both dash forms (en-dash and minus) are caught, so a regression
 * cannot slip back behind whichever one it picks. */
const SIGNED_RANGE = /[+−-]\d[\d,]*[–−-]\d/

/** Any sign at all. The hero is a magnitude now; direction is said elsewhere. */
const ANY_SIGN = /[+−-]/

test('a surplus hero is one number: the centre, rounded, with an ≈ and no sign', () => {
  // 2026-08-10: 1 362 burned against 2 071–2 311 eaten → 709–949, centre 830.
  assert.equal(heroFigure(balance('surplus', 709, 949, -949, -709, true)), '≈ 830')

  // 2026-08-07: 29–229, centre 129 → 130.
  assert.equal(heroFigure(balance('surplus', 29, 229, -229, -29)), '≈ 130')
})

test('a deficit hero is the same number, said the same way', () => {
  // The sign used to be the only difference; now the word, colour and side say it.
  assert.equal(heroFigure(balance('deficit', 120, 480, 120, 480)), '≈ 300')
  assert.equal(heroFigure(balance('surplus', 120, 480, -480, -120)), '≈ 300')
})

test('a typed day is certain: one exact number, no ≈ and no range', () => {
  // 2026-08-08: intake typed by hand (lo === hi), so no ≈ and no range line.
  assert.equal(heroFigure(balance('deficit', 565, 565, 565, 565)), '565')
  assert.equal(rangeLine(balance('deficit', 565, 565, 565, 565)), null)

  assert.equal(heroFigure(balance('surplus', 160, 160, -160, -160)), '160')
  assert.equal(rangeLine(balance('surplus', 160, 160, -160, -160)), null)
})

test('the hero never carries a sign, whichever way the server sent the band', () => {
  // The server computes burn − intake, so a surplus arrives NEGATIVE and a
  // deficit positive; the big slot is a magnitude either way.
  const surplus = balance('surplus', 709, 949, -949, -709)

  assert.ok(surplus.min < 0 && surplus.max < 0)
  assert.equal(ANY_SIGN.test(heroFigure(surplus)), false)

  const deficit = balance('deficit', 565, 565, 565, 565)

  assert.ok(deficit.min > 0)
  assert.equal(ANY_SIGN.test(heroFigure(deficit)), false)

  // Structural: even a magnitude that arrived signed renders as a quantity.
  assert.equal(heroFigure(balance('deficit', -565, -565, 565, 565)), '565')
})

test('a band that straddles zero reads near-zero, never a signed range', () => {
  // burn − intake between −80 and +50: which side of maintenance is unknowable,
  // so the hero is "≈ 0" and the range line carries it in words.
  assert.equal(heroFigure(balance('balanced', 0, 0, -80, 50)), '≈ 0')
  assert.equal(heroFigure(balance('balanced', 0, 0, -12, 400)), '≈ 0')
})

test('no hero is ever a sign glued to a range — not a surplus, deficit or straddle', () => {
  const cases = [
    balance('surplus', 709, 949, -949, -709),
    balance('surplus', 498, 738, -738, -498), // the exact "+498−738" from the bug report
    balance('deficit', 544, 638, 544, 638), // the exact "−544−638" from the bug report
    balance('deficit', 565, 565, 565, 565),
    balance('balanced', 0, 0, -80, 50),
  ]

  for (const b of cases) {
    assert.equal(SIGNED_RANGE.test(heroFigure(b)), false, `hero "${heroFigure(b)}" is a signed range`)
    assert.equal(ANY_SIGN.test(heroFigure(b)), false, `hero "${heroFigure(b)}" carries a sign`)
    assert.equal(SIGNED_RANGE.test(rangeLine(b) ?? ''), false, `range "${rangeLine(b)}" is a signed range`)
  }
})

test('no balance, no figure and no range', () => {
  assert.equal(heroFigure(null), null)
  assert.equal(heroFigure(undefined), null)
  assert.equal(rangeLine(null), null)
  assert.equal(rangeLine(undefined), null)
})

/* -------------------------------------------------------------------------
 * THE RANGE, DEMOTED — the honest width, formatted so a dash can never read as
 * a sign: a leading word and unsigned endpoints one-sided, words only for a
 * straddle.
 * ---------------------------------------------------------------------------- */

test('a one-sided range is "about lo–hi", endpoints rounded to ten', () => {
  assert.equal(rangeLine(balance('surplus', 709, 949, -949, -709, true)), 'about 710–950')
  assert.equal(rangeLine(balance('surplus', 29, 229, -229, -29)), 'about 30–230')
  assert.equal(rangeLine(balance('deficit', 120, 480, 120, 480)), 'about 120–480')
})

test('a straddling range is spelt out in "under" and "over", with no dash at all', () => {
  // max is how far UNDER, −min how far OVER, in eaten − burned terms.
  assert.equal(rangeLine(balance('balanced', 0, 0, -80, 50)), 'somewhere between 50 under and 80 over')

  // Endpoints round to ten: a 12 kcal edge is "10 over", not false precision.
  assert.equal(rangeLine(balance('balanced', 0, 0, -12, 400)), 'somewhere between 400 under and 10 over')

  // A straddle with no width — burn landed on a typed intake — has no range.
  assert.equal(rangeLine(balance('balanced', 0, 0, 0, 0)), null)
})

/* -------------------------------------------------------------------------
 * THE WORD ABOVE IT
 * ---------------------------------------------------------------------- */

test('a day still running says so, and a finished one does not', () => {
  assert.equal(anchorWord(balance('surplus', 709, 949, -949, -709, true)), 'Surplus so far')
  assert.equal(anchorWord(balance('surplus', 709, 949, -949, -709, false)), 'Surplus')

  assert.equal(anchorWord(balance('deficit', 565, 565, 565, 565, true)), 'Deficit so far')
  assert.equal(anchorWord(balance('deficit', 565, 565, 565, 565, false)), 'Deficit')

  // Never "Balanced", which reads as an achievement on a goal-neutral card.
  assert.equal(anchorWord(balance('balanced', 0, 0, -80, 50, true)), 'About even so far')
  assert.equal(anchorWord(balance('balanced', 0, 0, -80, 50, false)), 'About even')
})

test('an unknown or absent direction falls back to the neutral word and paint', () => {
  assert.equal(anchorWord(null), 'About even')
  assert.equal(anchorWord({ direction: 'sideways' }), 'About even')

  assert.equal(paintFor('sideways').fill, 'var(--balance-even)')
  assert.equal(paintFor('surplus').fill, 'var(--balance-surplus)')
  assert.equal(paintFor('deficit').fill, 'var(--balance-deficit)')
})

/* -------------------------------------------------------------------------
 * THE METER — a zero-centred axis. The bar grows OUT OF the centre, its length
 * how far from break-even the day landed. `pct` is a percentage of the WHOLE
 * rail, so one side can never exceed 50 and the two can never overlap.
 * ---------------------------------------------------------------------------- */

test('the side floor is half the rail, so both halves share one ruler', () => {
  assert.equal(SIDE_FLOOR, SCALE_FLOOR / 2)
  assert.equal(SIDE_FLOOR, 1300)
})

test('no balance draws no meter at all', () => {
  // Half a subtraction is not a direction, and a zero-centred axis would have to
  // invent one to place anything on it.
  assert.equal(meter(null), null)
  assert.equal(meter(undefined), null)
})

test('a deficit reaches LEFT of the centre and nowhere else', () => {
  // 2026-08-08 in production: 1 320 typed against 1 884.74 burned → 565 deficit,
  // widened here to a band to show the fade.
  const m = meter(balance('deficit', 540, 640, 540, 640))

  assert.ok(m.under !== null, 'a deficit must reach under the burn')
  assert.equal(m.over, null, 'a deficit must not reach over the burn')

  // The floor holds: nothing reached 1 300, so it shares yesterday's ruler.
  assert.equal(m.scale, SIDE_FLOOR)

  // 640 of 1 300, as a percentage of the whole rail — half of which is 50.
  assert.equal(m.under.pct, 24.6)

  // Solid as far as the balance is certain (540 of 640), fading across the
  // 100 kcal the band leaves open, written from the centre OUTWARD.
  assert.equal(
    m.under.background,
    'linear-gradient(to left, var(--balance-deficit) 0%, var(--balance-deficit) 84.4%, transparent 100%)'
  )

  assert.equal(m.glow, 'var(--balance-deficit-glow)')
})

test('a surplus is the same sentence, mirrored: RIGHT of the centre, in orange', () => {
  // 2026-08-10, exactly as production had it: 709–949 kcal over the burn.
  const m = meter(balance('surplus', 709, 949, -949, -709, true))

  assert.equal(m.under, null, 'a surplus must not reach under the burn')
  assert.ok(m.over !== null)

  assert.equal(m.scale, SIDE_FLOOR)
  assert.equal(m.over.pct, 36.5)

  assert.equal(
    m.over.background,
    'linear-gradient(to right, var(--balance-surplus) 0%, var(--balance-surplus) 74.7%, transparent 100%)'
  )

  assert.equal(m.glow, 'var(--balance-surplus-glow)')
})

test('the length is proportional to the magnitude, and the two sides mirror', () => {
  // Twice the balance, twice the bar. This is the whole claim the picture makes.
  assert.equal(meter(balance('deficit', 650, 650, 650, 650)).under.pct, 25)
  assert.equal(meter(balance('deficit', 1300, 1300, 1300, 1300)).under.pct, 50)

  // And a surplus of the same size is the same length, the other way.
  assert.equal(
    meter(balance('surplus', 650, 650, -650, -650)).over.pct,
    meter(balance('deficit', 650, 650, 650, 650)).under.pct
  )
})

test('the fade spans exactly the band, and a typed day has no fade at all', () => {
  // The stop is where the certainty ends: the rest of the bar is the estimate.
  const banded = meter(balance('deficit', 300, 600, 300, 600))

  assert.ok(banded.under.background.startsWith('linear-gradient(to left,'))
  assert.ok(banded.under.background.includes('var(--balance-deficit) 50%'))
  assert.ok(banded.under.background.endsWith('transparent 100%)'))

  // 2026-08-08: a typed intake has no band, so a flat fill end to end — the same
  // claim the hero makes by dropping its ≈.
  const typed = meter(balance('deficit', 565, 565, 565, 565))

  assert.equal(typed.under.background, 'var(--balance-deficit)')
  assert.ok(!typed.under.background.includes('gradient'))
  assert.ok(!typed.under.background.includes('transparent'))
})

test('a straddling day spans BOTH sides of the centre, faded on both tips', () => {
  // −80 to +50: it reaches both ways, and neither side has a certain part.
  const m = meter(balance('balanced', 0, 0, -80, 50))

  // `max` is how far under, `−min` how far over — rangeLine's reading in words.
  assert.equal(m.under.pct, 1.9)
  assert.equal(m.over.pct, 3.1)

  // Neutral, never a direction colour: the side is the one thing not known.
  assert.equal(
    m.under.background,
    'linear-gradient(to left, var(--balance-even) 0%, var(--balance-even) 0%, transparent 100%)'
  )
  assert.equal(
    m.over.background,
    'linear-gradient(to right, var(--balance-even) 0%, var(--balance-even) 0%, transparent 100%)'
  )

  assert.equal(m.glow, 'var(--balance-even-glow)')
})

test('a straddle with no width at all draws nothing, and the marked centre says it', () => {
  // Burn landed exactly on a typed intake: the tick already pictures that.
  const m = meter(balance('balanced', 0, 0, 0, 0))

  assert.equal(m.under, null)
  assert.equal(m.over, null)

  // No NaN reaching a gradient, which would paint the card's picture as nothing.
  assert.ok(Number.isFinite(m.scale))
})

test('a one-sided straddle only draws the side it can be on', () => {
  // −400 to 0: it cannot have been under the burn, but "over" is not known either.
  const m = meter(balance('balanced', 0, 0, -400, 0))

  assert.equal(m.under, null)
  assert.ok(m.over !== null)
  assert.ok(m.over.background.includes('var(--balance-even)'))
})

test('a big day pushes the ruler out, symmetrically, rather than being clipped', () => {
  const m = meter(balance('deficit', 1500, 1800, 1500, 1800))

  // Past the floor, so the ruler grows to fit.
  assert.equal(m.scale, 1800)
  assert.equal(m.under.pct, 50)

  // It grows on BOTH halves at once: one scale for a lopsided straddle, or the
  // axis would be lying about its centre.
  const lopsided = meter(balance('balanced', 0, 0, -2000, 1400))

  assert.equal(lopsided.scale, 2000)
  assert.equal(lopsided.over.pct, 50)
  assert.equal(lopsided.under.pct, 35)
})

test('no side ever crosses the centre, whatever the day did', () => {
  const cases = [
    balance('deficit', 1, 1, 1, 1),
    balance('deficit', 4000, 9000, 4000, 9000),
    balance('surplus', 709, 949, -949, -709),
    balance('balanced', 0, 0, -3000, 2500),
  ]

  for (const b of cases) {
    const m = meter(b)

    for (const side of [m.under, m.over]) {
      if (side === null) continue

      assert.ok(side.pct >= 0 && side.pct <= 50, `${side.pct} is not half a rail`)
      assert.ok(!side.background.includes('NaN'), `${side.background} contains NaN`)
    }
  }
})

test('a tiny balance is nearly nothing, and never nothing', () => {
  // 20 kcal is 0.8% of one side; the card floors that at 3 px, but the module
  // must return a real drawn side rather than round it out of existence.
  const m = meter(balance('deficit', 20, 20, 20, 20))

  assert.ok(m.under !== null)
  assert.ok(m.under.pct > 0)
  assert.equal(m.under.pct, 0.8)
})

test('the direction decides the paint, and the numbers never argue with it', () => {
  // Rounding happens on the server BEFORE the word is chosen, so a fraction of a
  // kcal is "balanced" there where a raw comparison says deficit. This module
  // renders the word it was given; an unknown one claims the least.
  const m = meter(balance('sideways', 0, 0, -80, 50))

  assert.ok(m.under.background.includes('var(--balance-even)'))
  assert.ok(!m.under.background.includes('var(--balance-deficit)'))
  assert.equal(m.glow, 'var(--balance-even-glow)')
})

/* -------------------------------------------------------------------------
 * THE METER, IN WORDS — the screen-reader label. It describes the SHAPE, a
 * distance and a side, in the words printed at the ends of the axis and the
 * hero's own rounded figure.
 * ---------------------------------------------------------------------------- */

test('the spoken meter is a distance and a side, then the band', () => {
  assert.equal(
    meterLabel(balance('deficit', 540, 640, 540, 640)),
    'Balance meter: about 590 kcal under your burn, estimated 540 to 640.'
  )

  assert.equal(
    meterLabel(balance('surplus', 709, 949, -949, -709, true)),
    'Balance meter: about 830 kcal over your burn, estimated 710 to 950.'
  )
})

test('a typed day is spoken as certainly as it is drawn', () => {
  assert.equal(
    meterLabel(balance('deficit', 565, 565, 565, 565)),
    'Balance meter: 565 kcal under your burn.'
  )
})

test('a straddle is spoken as both sides, in words', () => {
  assert.equal(
    meterLabel(balance('balanced', 0, 0, -80, 50)),
    'Balance meter: about even — somewhere between 50 under and 80 over your burn.'
  )

  assert.equal(
    meterLabel(balance('balanced', 0, 0, 0, 0)),
    'Balance meter: exactly even with your burn.'
  )
})

test('no balance is spoken as nothing, not as an empty meter', () => {
  assert.equal(meterLabel(null), '')
  assert.equal(meterLabel(undefined), '')
})

test('the spoken figure is the hero’s own, so the two readings never disagree', () => {
  for (const b of [
    balance('deficit', 540, 640, 540, 640),
    balance('surplus', 709, 949, -949, -709),
    balance('deficit', 565, 565, 565, 565),
    balance('surplus', 29, 229, -229, -29),
  ]) {
    const hero = heroFigure(b).replace('≈ ', '')

    assert.ok(
      meterLabel(b).includes(`${hero} kcal`),
      `spoken "${meterLabel(b)}" does not carry the hero's "${hero}"`
    )
  }
})

/* -------------------------------------------------------------------------
 * ON PACE — the in-progress day's projection. Same shape plus `projected`, from
 * App\Services\Reporting\DayPace. Checked here: the wording and the ≈, the two
 * things that make a reader treat it as a forecast rather than a measurement.
 * ---------------------------------------------------------------------- */

/** The projection, as DayPace puts it on the wire. */
const paced = (direction, lo, hi, min, max, extra = {}) => ({
  ...balance(direction, lo, hi, min, max, true),
  projected: true,
  expectedResting: 1140,
  restingRemaining: 275,
  basisDays: 14,
  ...extra,
})

test('a projected day is on pace for something, not something so far', () => {
  assert.equal(anchorWord(paced('surplus', 546, 906, -906, -546)), 'On pace for a surplus')
  assert.equal(anchorWord(paced('deficit', 120, 380, 120, 380)), 'On pace for a deficit')
  assert.equal(anchorWord(paced('balanced', 0, 0, -80, 50)), 'On pace to be about even')

  // And the measured day is untouched: still "so far" while it is running.
  assert.equal(anchorWord(balance('surplus', 546, 906, -906, -546, true)), 'Surplus so far')
  assert.equal(anchorWord(balance('surplus', 546, 906, -906, -546)), 'Surplus')
})

test('a projection keeps its ≈ even when the intake was typed', () => {
  // A MEASURED typed day is certain, so no ≈. A projected one never is: part of
  // its burn has not happened yet.
  assert.equal(heroFigure(balance('surplus', 300, 300, -300, -300)), '300')
  assert.equal(heroFigure(paced('surplus', 300, 300, -300, -300)), '≈ 300')

  // Banded, it rounds to the same ten either way.
  assert.equal(heroFigure(paced('surplus', 546, 906, -906, -546)), '≈ 730')
})

test('the projection accounts for itself in one line', () => {
  assert.equal(
    paceNote(paced('surplus', 546, 906, -906, -546)),
    'assumes 275 kcal resting still to come, from your last 14 complete days'
  )

  assert.equal(
    paceNote(paced('surplus', 546, 906, -906, -546, { restingRemaining: 0, basisDays: 1 })),
    'assumes 0 kcal resting still to come, from your last 1 complete day'
  )

  // A measurement owes no such account, and neither does an absent balance.
  assert.equal(paceNote(balance('surplus', 546, 906, -906, -546, true)), null)
  assert.equal(paceNote(null), null)
})

test('a listener is told which tense the meter is in', () => {
  assert.equal(
    meterLabel(paced('surplus', 546, 906, -906, -546)),
    'Balance meter, on pace: about 730 kcal over your burn, estimated 550 to 910.'
  )

  assert.equal(
    meterLabel(paced('balanced', 0, 0, -80, 50)),
    'Balance meter, on pace: about even — somewhere between 50 under and 80 over your burn.'
  )
})
