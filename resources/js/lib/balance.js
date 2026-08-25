/**
 * The day card's headline, as arithmetic — out of the component so every claim
 * it makes can be checked without a browser.
 *
 * A METER, CENTRED ON BREAK-EVEN
 *
 * The rail is an AXIS whose centre is zero. A bar grows out of that centre —
 * LEFT and cool for a day under your burn, RIGHT and warm for a day over it —
 * and its length is how far from break-even the day landed: the hero's number
 * drawn instead of printed. Two earlier pictures were rejected to get here, the
 * second by the user, who called it a slider — so do NOT restore a single
 * shared track with a floating overhang, a length measured from an unmarked
 * seam. See docs/rationale-frontend.md § "Why the balance is a centred meter".
 *
 * THE SOFT END IS THE FAR END, AND THAT IS THE HONESTY
 *
 * The server sends two unsigned magnitudes, small end first, so each side is
 * painted SOLID from the centre out to the small end — that much is certain —
 * and FADES to nothing by the large end: where the solid stops is the floor of
 * the claim, where the fade vanishes is the ceiling. A TYPED intake has no band
 * (lo === hi), so it is a flat fill — the same rule as the hero's missing `≈`,
 * a certain day drawn certain. A STRADDLING day (the band crosses zero) gets
 * both sides at once in the neutral tone, both fading from the centre outward
 * because NEITHER side has a certain part — the "≈ 0" hero as a picture.
 *
 * NO SIGN ON THE NUMBER. THE DIRECTION IS CARRIED THREE OTHER WAYS.
 *
 * The hero used to wear a direction-chosen sign; the user asked for it to go:
 * "We can remove the negative sign before the deficit and plus sign before the
 * surplus. It's red, so I know I have eaten more than I had burned off." The
 * word ("Deficit"), the arrow (▼), the colour (teal under, orange over) and the
 * side of centre the meter grows from have all said the direction before the
 * eye reaches the digits, so a sign is a fifth channel in the one place clutter
 * costs most — the 40 px glance — and magnitudes are rendered UNSIGNED. The
 * "eaten − burned" caption went with it: it only said which way round the sign
 * was, and the axis's own "under / 0 / over" states the convention in place.
 *
 * Restoring the sign "for clarity" has already been considered and rejected BY
 * THE PERSON WHO USES THIS. Do not flip it back without asking them again.
 *
 * NOTHING HERE DECIDES A DIRECTION
 *
 * `direction` arrives from App\Services\Reporting\EnergyBalance and is used,
 * not re-derived: the server picks the word AFTER rounding, so a day whose burn
 * exceeds its intake by 0.4 kcal is "balanced" there while a raw comparison
 * here would call it a deficit, and two answers to one question is the bug. The
 * meter goes further — handed the BALANCE OBJECT and nothing else, it never
 * sees an intake or a burn and so cannot subtract them even by accident, which
 * is the failure this file was split out of the component to stop.
 */

import { num } from './format.js'

/**
 * The kcal one whole rail is worth. The same 2 600 both earlier cards used, for
 * the same reason: yesterday and today on one ruler, which is most of the value
 * of a picture looked at every morning. Below it nothing shrinks; above it the
 * ruler grows.
 */
export const SCALE_FLOOR = 2600

/**
 * The kcal ONE SIDE is worth — half the rail, since the meter is centred and a
 * balance can go either way. A 590 kcal deficit is a bit under half a side every
 * day, which is what makes two mornings comparable; a day bigger than 1 300
 * either way pushes BOTH sides out together (see `meter`), never clipped.
 */
export const SIDE_FLOOR = SCALE_FLOOR / 2

/**
 * The direction colours by NAME, as in lib/stress.js: the hexes live in
 * resources/css/app.css in both themes, so the browser resolves the theme at
 * paint time and no component asks which one is on. Usable only through a
 * STYLE — an SVG presentation attribute does not substitute custom properties.
 */
const PAINT = {
  surplus: { fill: 'var(--balance-surplus)', glow: 'var(--balance-surplus-glow)' },
  deficit: { fill: 'var(--balance-deficit)', glow: 'var(--balance-deficit-glow)' },
  balanced: { fill: 'var(--balance-even)', glow: 'var(--balance-even-glow)' },
}

export function paintFor(direction) {
  return PAINT[direction] ?? PAINT.balanced
}

/**
 * The word above the number. "so far" is what `provisional` changes, and it is
 * a real caveat: at nine in the morning the burn is a third finished, so the
 * surplus is genuinely the largest it will look all day. Yesterday has no "so
 * far" about it.
 */
const WORDS = {
  surplus: 'Surplus',
  deficit: 'Deficit',
  balanced: 'About even',
}

/**
 * A PROJECTED balance is the whole day, not a "so far" figure: the rest of the
 * resting burn is added from the median of recent complete days
 * (App\Services\Reporting\DayPace). Hence its own wording, the only place on the
 * card that says the number is about a day that has not happened yet.
 */
const PACE = {
  surplus: 'On pace for a surplus',
  deficit: 'On pace for a deficit',
  balanced: 'On pace to be about even',
}

export function anchorWord(balance) {
  if (balance?.projected) {
    return PACE[balance.direction] ?? PACE.balanced
  }

  const word = WORDS[balance?.direction] ?? WORDS.balanced

  return balance?.provisional ? `${word} so far` : word
}

/**
 * The step the hero rounds to. A morning glance does not need the kcal digit,
 * and reading it back invites treating an estimate as a measurement; the honest
 * width lives in the range line under it.
 */
const HERO_STEP = 10
const toStep = (value) => Math.round(value / HERO_STEP) * HERO_STEP

/**
 * The hero: ONE unsigned number, the centre of the balance's magnitude.
 *
 * A TWO-ENDED SIGNED RANGE IS NOT A HEADLINE. An older version put the whole
 * band in the big slot, and on a real phone "+709–949" rendered as "+498−738"
 * and a straddling "−50 to +80" as "−544−638": the range dash reads as a minus
 * and the number is unparseable. The old rule — "never collapse the range to a
 * midpoint, the range IS the hero" — produced that, so it is DELIBERATELY
 * REVERSED: the hero is the midpoint of the server's two magnitudes, rounded to
 * the nearest ten, behind an `≈` that reads as an estimate's CENTRE. The
 * uncertainty is shown three honest ways instead of one unreadable one — the
 * `≈`, the range line (see `rangeLine`), and the meter's fade — and the sign
 * went too (see the header, and do not restore it without asking).
 *
 * A TYPED intake has no band (lo === hi): certain, so one figure, no `≈`, no
 * range line. The BALANCED case could genuinely go either way, so the hero is
 * "≈ 0" and `rangeLine` carries the finding — never a signed range in the big
 * slot, which is the "−50 to +80" above. Do not "restore" a range here: the
 * one-glance morning read is the point, and the honesty machinery moved rather
 * than left.
 */
export function heroFigure(balance) {
  if (!balance) return null

  // Near-zero, and the range line says how near. Never a signed range here.
  if (balance.direction === 'balanced') {
    return '≈ 0'
  }

  // `abs` makes "no sign reaches the big slot" a property of this function
  // rather than a promise about somebody else's data.
  const lo = Math.abs(balance.lo)
  const hi = Math.abs(balance.hi)

  // A PROJECTED day is never certain whatever its band looks like — part of its
  // burn has not happened — so it keeps the ≈ even when both ends agree.
  if (lo === hi && !balance.projected) {
    return num(lo)
  }

  return `≈ ${num(toStep((lo + hi) / 2))}`
}

/**
 * The support line under the hero: the honest width, formatted so a dash can
 * NEVER be read as a sign. Null when there is nothing to say — a typed day
 * (lo === hi) is certain, and a missing balance has no line.
 *
 *   surplus/deficit   "about 710–950": a leading WORD and two unsigned,
 *                     round-to-ten endpoints, so there is no sign for the dash
 *                     to be confused with.
 *   balanced          "somewhere between 50 under and 80 over": words only, no
 *                     dash. The server's signed band (burn − intake, so max is
 *                     how far UNDER and −min how far OVER) spelt out.
 */
export function rangeLine(balance) {
  if (!balance) return null

  if (balance.direction === 'balanced') {
    if (balance.min === balance.max) return null

    return `somewhere between ${num(toStep(balance.max))} under and ${num(toStep(-balance.min))} over`
  }

  if (balance.lo === balance.hi) return null

  return `about ${num(toStep(balance.lo))}–${num(toStep(balance.hi))}`
}

/**
 * The projection's audit trail: what was ADDED to the measured burn and how many
 * measured days decided it. Null when not a projection — a card stating a
 * measurement owes no such account.
 */
export function paceNote(balance) {
  if (!balance?.projected) return null

  const days = balance.basisDays

  return `assumes ${num(balance.restingRemaining)} kcal resting still to come, `
    + `from your last ${days} complete day${days === 1 ? '' : 's'}`
}

/** Percent, rounded to a tenth — a DOM attribute, not a measurement. */
function stop(fraction) {
  return Math.round(Math.max(0, Math.min(1, fraction)) * 1000) / 10
}

/**
 * Percent OF THE WHOLE RAIL for a distance from its centre, so half the rail is
 * 50 and the two sides can never overlap however big the day was.
 */
function halfStop(fraction) {
  return Math.round(Math.max(0, Math.min(1, fraction)) * 500) / 10
}

/**
 * How far the day reaches on each side of break-even, unsigned, in kcal.
 * `solid` is the least the balance can be and `reach` the most — the server's
 * two magnitudes, which is why nothing here subtracts anything. A `null` side is
 * one the day cannot be on at all.
 *
 *   deficit    under only, [lo, hi]
 *   surplus    over only,  [lo, hi]
 *   balanced   BOTH, neither with a certain part: the band crosses zero, so the
 *              least either side can be is nothing. `max` is how far under,
 *              `−min` how far over.
 *
 * An unrecognised direction falls through to the straddle, the shape that claims
 * the least, and `paintFor` paints it neutral for the same reason.
 */
function extents(balance) {
  if (balance.direction === 'deficit') {
    return { under: { solid: balance.lo, reach: balance.hi }, over: null }
  }

  if (balance.direction === 'surplus') {
    return { under: null, over: { solid: balance.lo, reach: balance.hi } }
  }

  return {
    under: { solid: 0, reach: Math.max(0, balance.max) },
    over: { solid: 0, reach: Math.max(0, -balance.min) },
  }
}

/**
 * THE METER: the balance as a direction and a distance from break-even.
 *
 * Takes the balance object and NOTHING ELSE — no intake, no burn — so nothing
 * here can re-derive a direction or redo a subtraction by accident. Null when
 * there is no balance, matching the server: the card has a sentence for those
 * days and must not draw a meter with nothing to measure.
 *
 * Both sides share ONE scale, floored at `SIDE_FLOOR` and grown symmetrically by
 * whichever side reaches furthest, so a 590 kcal deficit is the same length
 * every morning and a 1 800 kcal day pushes the ruler out on BOTH sides rather
 * than running off the end — a longer left half than right would be a lie about
 * the centre. `pct` is a percentage of the whole rail capped at 50; a tiny
 * balance stays tiny but non-zero (the card floors it at 3 px), and a side
 * reaching exactly zero is null, because the marked centre is already the right
 * picture of a day that landed on break-even.
 *
 * @param {?{direction: string, lo: number, hi: number, min: number, max: number}} balance
 * @param {number} floor  kcal one side is worth before the ruler has to grow
 */
export function meter(balance, floor = SIDE_FLOOR) {
  if (!balance) return null

  const sides = extents(balance)
  const { fill, glow } = paintFor(balance.direction)

  // One ruler for both halves, so the expansion is symmetric by construction.
  const scale = Math.max(floor, sides.under?.reach ?? 0, sides.over?.reach ?? 0)

  /**
   * One side, growing AWAY from the centre: the gradient is written centre
   * outward and `away` is the direction the fade travels. A day with no band (a
   * typed intake) is a flat fill with nothing to soften.
   */
  const side = (extent, away) => {
    if (!extent || extent.reach <= 0) return null

    return {
      pct: halfStop(extent.reach / scale),
      background: extent.solid >= extent.reach
        ? fill
        : `linear-gradient(to ${away}, ${fill} 0%, ${fill} ${stop(extent.solid / extent.reach)}%, transparent 100%)`,
    }
  }

  return {
    scale,
    glow,

    /** Cool, LEFT of centre: the day landed under its burn. */
    under: side(sides.under, 'left'),

    /** Warm, RIGHT of centre: the day landed over its burn. */
    over: side(sides.over, 'right'),
  }
}

/**
 * The meter, in words, for anybody the picture is not reaching.
 *
 * It describes the SHAPE rather than restating the card, and reuses the axis's
 * own "under" and "over", which is what makes it a description of the drawing
 * rather than a second, differently-worded headline. The magnitude is the hero's
 * own rounded midpoint, so seeing and hearing give the same figure.
 */
export function meterLabel(balance) {
  if (!balance) return ''

  // Only the tense changes, and a listener has no ≈ or colour to hear it in.
  const lead = balance.projected ? 'Balance meter, on pace:' : 'Balance meter:'

  if (balance.direction === 'balanced') {
    const range = rangeLine(balance)

    return range
      ? `${lead} about even — ${range} your burn.`
      : `${lead} exactly even with your burn.`
  }

  const way = balance.direction === 'surplus' ? 'over' : 'under'
  const lo = Math.abs(balance.lo)
  const hi = Math.abs(balance.hi)

  if (lo === hi) {
    return `${lead} ${num(lo)} kcal ${way} your burn.`
  }

  return `${lead} about ${num(toStep((lo + hi) / 2))} kcal ${way} your burn, `
    + `estimated ${num(toStep(lo))} to ${num(toStep(hi))}.`
}
