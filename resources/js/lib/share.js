/**
 * ONE PLATE, TWO NUMBERS — the conversion both meal sheets do to every box.
 *
 * A shared plate is stored WHOLE: `meal_items` holds what was on the table and
 * `share_fraction` how much of it was eaten. The sheets show and edit the EATEN
 * half, so each box crosses this boundary twice — out on the way to the screen,
 * back on the way to the item — and the two directions have to be inverses at
 * the precision the column stores or a figure moves under the cursor while it is
 * being typed. That is why both live here rather than once per sheet: they are
 * one rule, and a rule stated twice is a rule half-changed.
 *
 * Logic in a `.vue` file is untestable here — there is no vitest and no jsdom —
 * so moving it into lib/ is what buys it tests/js/share.test.js.
 */

/** No share recorded means the whole plate, which is nearly every meal. */
export function shareOf(item) {
  return item?.share_fraction ?? 1
}

/**
 * The eaten figure out of the plate. Blanks come back blank rather than as 0,
 * which would be a claim nobody made. Three decimals because that is what
 * `meal_items` stores, so anything typeable round-trips through `plate × share`
 * unchanged. At share 1 this is the identity function, blanks included.
 *
 * ONLY WHAT SCALES GOES THROUGH IT — the totals, and the weight — because a
 * density is a fact about the food and half a portion of it is the same food.
 */
export function applyShare(value, share) {
  if (share >= 1 || value === null || value === undefined || value === '') return value

  return Math.round(Number(value) * share * 1000) / 1000
}

/**
 * The plate a typed figure implies: what the user typed is what they ATE, so "I
 * had 100 g" with ½ selected says the plate held 200 g. The inverse of
 * `applyShare`, and the reason that one rounds where the column does.
 */
export function unapplyShare(value, share) {
  return share >= 1 ? value : value / share
}
