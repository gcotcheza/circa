/**
 * Which one item owns a plate's kcal spread, and by how much.
 *
 * Half-widths combine in quadrature (App\Services\Rollup\IntakeBand), so a
 * single wide line can own nearly all of a plate's range — and that is the line
 * worth asking a count or a weight about. One PLATE, not the meal: the review
 * sheet is scoped to one photograph and so is this.
 * See docs/DECISIONS.md § "the-narrow-nudge-asks-about-one-item-and-rides-the-note"
 */

import { applyShare, shareOf } from './share.js'

/**
 * At half the summed squares, collapsing this line is the only single edit that
 * visibly narrows the plate — everything else together contributes less.
 */
export const DOMINANT_SHARE = 0.5

/**
 * And only once the plate's own band reaches this: under it the answer would
 * move the day by less than its own rounding, and a nudge nobody needs is one
 * that gets ignored on the plates that do need it.
 */
export const PLATE_BAND_FLOOR_KCAL = 100

/** A box nobody filled is not a bound, and `Number(null)` would call it zero. */
function edge(value) {
  if (value === null || value === undefined) return null

  const number = Number(value)

  return Number.isFinite(number) ? number : null
}

/**
 * Half this line's kcal range, as eaten. A manual entry states a figure rather
 * than a range, so its half-width is 0 and it neither dominates nor dilutes —
 * which is what "I already know this one" should mean.
 */
function halfWidth(item) {
  const min = edge(item?.kcal_min)
  const max = edge(item?.kcal_max)

  if (min === null || max === null) return 0

  return applyShare(Math.abs(max - min) / 2, shareOf(item))
}

/**
 * The item that owns the plate's uncertainty, or null when no single one does.
 *
 * @param items  the sheet's own rows: `{ kcal_min, kcal_max, share_fraction }`
 * @returns {{index: number, share: number}|null}  the row, and how much of the
 *   squared spread it owns (0-1), so the sheet can say it in words
 */
export function dominantItem(items) {
  if (!Array.isArray(items) || items.length === 0) return null

  const squares = items.map((item) => halfWidth(item) ** 2)
  const total = squares.reduce((sum, square) => sum + square, 0)

  // The plate's own half-width, which the floor is about. A plate of nothing
  // but manual entries totals 0 and leaves here, guarding the division below.
  if (Math.sqrt(total) < PLATE_BAND_FLOOR_KCAL) return null

  // Ties go to the first: two lines can each own exactly half, and the sheet
  // asks about one line at a time — answering either one is the same win.
  let index = 0

  squares.forEach((square, at) => {
    if (square > squares[index]) index = at
  })

  const share = squares[index] / total

  return share >= DOMINANT_SHARE ? { index, share } : null
}
