/**
 * Formatting helpers. The rule this file exists to enforce: an estimate is
 * rendered with its width. `band` prints both ends; `bestEstimate` leads with
 * the midpoint and keeps both ends under it. Neither drops the width — a
 * midpoint on its own is a ±400 kcal guess that looks like a measurement.
 */

const locale = 'en-GB'

/**
 * A typed figure as a number, or null. The inverse of `num`, and the only
 * parser any input box in this app should use.
 *
 * THE COMMA IS NOT A TYPO, IT IS THE KEY ON THE KEYBOARD: the phone this app is
 * used on has a Dutch keypad whose decimal key is a COMMA. `Number('28,35')` is
 * NaN, and a NaN reaching `JSON.stringify` is `null` — so a value the user
 * typed, saw and believed they had entered would arrive at the server blank,
 * with nothing on screen having said so. Blank is still blank, though: an empty
 * box means "I do not know", never 0, and that distinction is the whole basis of
 * the estimate offer (see ValidatesMealItems).
 */
export function decimal(raw) {
  if (raw === null || raw === undefined) return null

  const text = String(raw).trim().replace(',', '.')

  if (text === '') return null

  const parsed = Number(text)

  return Number.isFinite(parsed) ? parsed : null
}

/** The precision `meal_items` holds, and the floor `numberText` stops at. */
const STORED_DIGITS = 3

/**
 * The text a numeric INPUT is filled in with. The other half of `decimal`.
 *
 * A DERIVED NUMBER ARRIVES WITH A TAIL, AND THE BOX IS A FIFTH OF A PHONE WIDE:
 * `densityFor` (lib/basis.js) turns 157 kcal over a 120 g portion into
 * 130.83333333333334, and the edit sheet shows "130.833" with the rest cut off
 * at the field's edge — not readable, not correctable, and not even true, since
 * the figure it came from is a vision estimate with a ±40 kcal band. So a value
 * THIS APP computes is written at the precision its label claims, the precision
 * the day view prints the same numbers at; what the USER typed is never
 * rewritten, and that distinction is kept in lib/boxes.js.
 *
 * NOT `num()`, which is for prose and localises: `num(1200)` is "1,200" and
 * `decimal('1,200')` is 1.2, so a box that fills itself in with a thousands
 * separator would divide a 1200 kcal pizza by a thousand the next time it was
 * saved. Nothing here may produce text this file's own parser misreads. Trailing
 * zeros go as well — ".0" is a backspace in the way of typing "125".
 *
 * A REAL FIGURE IS NEVER ROUNDED AWAY TO NOTHING: 0.4 kcal shown as "0" is the
 * empty-box lie in a different font, so a non-zero value that rounds to zero is
 * given as many places as the column stores and no more — below 0.0005 the row
 * itself holds 0.000 and "0" is the truth.
 */
export function numberText(value, digits = 1) {
  if (value === null || value === undefined || value === '') return ''

  const number = Number(value)

  if (!Number.isFinite(number)) return ''

  let places = digits

  while (places < STORED_DIGITS && number !== 0 && Number(number.toFixed(places)) === 0) {
    places += 1
  }

  const text = number.toFixed(places)

  // Only when there is a point to trim back to: "10" must not become "1".
  return text.includes('.') ? text.replace(/\.?0+$/, '') : text
}

export function num(value, digits = 0) {
  if (value === null || value === undefined) return '—'

  return Number(value).toLocaleString(locale, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })
}

/**
 * A {min, mid, max} band as text. A zero-width band — every manual entry —
 * collapses to a single number, the correct claim about it: the user typed
 * 240 kcal and meant 240 kcal. Anything wider keeps both ends: this IS the
 * width, and `bestEstimate` is the only place a midpoint may lead it.
 */
export function band(value, digits = 0, unit = '') {
  if (!value || value.mid === null || value.mid === undefined) return '—'

  const suffix = unit ? ` ${unit}` : ''
  const min = Number(value.min ?? value.mid)
  const max = Number(value.max ?? value.mid)

  // Sub-unit widths are noise, not information.
  if (Math.abs(max - min) < 0.5 / 10 ** digits) {
    return `${num(value.mid, digits)}${suffix}`
  }

  return `${num(min, digits)}–${num(max, digits)}${suffix}`
}

/** Not balance.js's `toStep`: balance.js imports this file. See
 * docs/DECISIONS.md § "meal-and-day-energy-lead-with-the-best-estimate". */
const ESTIMATE_STEP = 10

/**
 * A {min, mid, max} band led by its best estimate, with `band`'s text as the
 * width underneath — the shape lib/balance.js `heroFigure` already gives the day
 * card, because the top of "588–857" was being read as the likely figure.
 *
 * The ≈ and the rounding are ONE decision, so a rounded figure always admits it:
 * only a band wider than the step gets both, and a narrower one keeps its exact
 * number, which the rounding would move further than the band is wide. `width`
 * is null exactly when `band` would do no more than repeat the figure.
 */
export function bestEstimate(value, digits = 0) {
  if (!value || value.mid === null || value.mid === undefined) {
    return { figure: '—', width: null }
  }

  const spread = Math.abs(Number(value.max ?? value.mid) - Number(value.min ?? value.mid))
  const exact = num(value.mid, digits)
  const width = band(value, digits)

  return {
    figure: spread > ESTIMATE_STEP
      ? `≈ ${num(Math.round(Number(value.mid) / ESTIMATE_STEP) * ESTIMATE_STEP, digits)}`
      : exact,
    width: width === exact ? null : width,
  }
}

export function isBanded(value) {
  if (!value || value.mid === null || value.mid === undefined) return false

  return Math.abs(Number(value.max) - Number(value.min)) >= 1
}

/** 528 minutes -> "8h 48m". Hours alone would round away half an hour. */
export function duration(minutes) {
  if (minutes === null || minutes === undefined) return '—'

  const total = Math.round(Number(minutes))
  const h = Math.floor(total / 60)
  const m = total % 60

  return h === 0 ? `${m}m` : `${h}h ${String(m).padStart(2, '0')}m`
}

/**
 * A consumption share as the chips say it: "½", "⅓", "40%". The named fractions
 * are the ones a table divides into and the ones the user tapped — showing "50%"
 * back to somebody who chose ½ would be a worse word for the same thing — and
 * anything else is a percentage, because that is what they typed. Matched with a
 * tolerance: a third is 0.3333 once through a `decimal(5,4)` column and must
 * still come back as ⅓.
 */
const FRACTIONS = [
  [1, ''],
  [0.75, '¾'],
  [0.5, '½'],
  [1 / 3, '⅓'],
  [0.25, '¼'],
]

export function shareLabel(fraction) {
  if (fraction === null || fraction === undefined) return ''

  const value = Number(fraction)

  const named = FRACTIONS.find(([candidate]) => Math.abs(candidate - value) < 0.005)

  if (named) return named[1]

  return `${num(value * 100, 0)}%`
}

export function percent(fraction, digits = 0) {
  if (fraction === null || fraction === undefined) return '—'

  return `${num(Number(fraction) * 100, digits)}%`
}
