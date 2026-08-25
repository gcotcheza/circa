/**
 * The two entry modes of one meal item, and the arithmetic that keeps them ONE
 * item rather than two forms sharing a card.
 *
 * WHAT WENT WRONG WITHOUT THIS FILE
 *
 * The Quick / Per 100 g tabs used to switch which boxes were rendered and
 * nothing else, so an item that arrived in per-100 g shape — a barcode scan, a
 * confirmed estimate, a photo hint — showed FOUR EMPTY BOXES the moment "Quick"
 * was tapped. Nothing had been lost, but there was no way to know that from the
 * screen, and the honest reading of an empty kcal box is "this has no calories".
 * The tabs are now two VIEWS of one item: Quick fills the totals from grams x
 * density, Per 100 g shows the densities, including any Quick recomputed.
 *
 * THE 100 g BASIS IS NOT A WEIGHT, AND THAT IS THE WHOLE TRICK
 *
 * `meal_items` stores densities and a portion; there is no absolutes column, so
 * Quick mode is stored as 100 g at N kcal/100 g — AT 100 g THE DENSITY IS THE
 * TOTAL, the convergence that makes a lossless toggle possible at all. It is
 * asserted server-side (TypedItem::toRow) and read back identically in three
 * places (MealSheet's loader, MealEstimateController and here). It also means
 * "grams" has two meanings that must never be confused:
 *
 *   a stated weight   120 g of rice, off a scale or off a packet. Editing a
 *                     TOTAL against it must keep it — see `densityFor`.
 *   the basis         blank, 0, or exactly 100. Nobody weighed the cappuccino;
 *                     total and density are the same number.
 *
 * `portionOf` is the one place that decision is made.
 *
 * NO DRIFT ON A ROUND TRIP. THIS IS A REQUIREMENT, NOT AN ASPIRATION.
 *
 * Toggling to the other tab and back without typing anything must post the
 * IDENTICAL payload — otherwise looking at an item changes it. Two things buy
 * that:
 *
 *   1. At the basis, `totalFor` and `densityFor` return their input UNTOUCHED
 *      rather than computing `x * 100 / 100`, which in IEEE doubles is not the
 *      identity: 123.456 comes back as 123.45599999999999, and a value that
 *      moves in the fourth decimal every time somebody taps a tab is the quiet
 *      corruption the schema's 3 dp columns would make permanent.
 *   2. On an item WITH a stated weight the densities are the canonical state.
 *      Switching to Quick fills the totals for display (rounded, because 241 is
 *      what a person reads) but does NOT write back; only an actual EDIT
 *      recomputes a density, so the rounding never reaches the payload.
 *
 * `payloadFor` finishes the job: one canonical shape per visible mode, blanking
 * the half the mode does not own, so a field left populated by a visit to the
 * other tab cannot show up in the bytes.
 *
 * WHY THIS IS NOT `forPortion` IN lib/products.js
 *
 * That helper multiplies the same two numbers for the barcode panel, where the
 * weight is always one somebody typed (required before the item can be added),
 * so a blank there is a blank and must render as "—". Here a blank weight is the
 * 100 g basis and has a meaning. Same multiplication, different question.
 */

// Extension included: `node --test` runs this module directly
// (tests/js/boxes.test.js) and Node resolves ESM by path.
import { decimal } from './format.js'

/** The portion an item is stored at when nobody weighed it. */
export const BASIS_G = 100

/** Every nutrient the two modes carry, in the order the boxes are drawn. */
export const NUTRIENTS = ['kcal', 'protein', 'carbs', 'fat']

/*
 * Every value in here goes through `decimal` (lib/format.js), not `Number`: a
 * cleared box holds '' and `Number('')` is 0 — the coercion that turns "I do not
 * know" into "zero of it" — and this keypad has a comma where the dot should be.
 */

/**
 * The single figure a form box can hold, from a stored {min, max} band.
 *
 * A form is one number per box, a stored estimate is a range, and the CENTRE is
 * the only end that does not editorialise: taking the min (which this sheet did
 * until now) quietly marked every ranged item down to the bottom of its own
 * estimate the first time somebody opened the sheet to change the time.
 * Zero-width bands — every manually typed item — are unaffected.
 *
 * Rounded to the 3 dp the columns hold, so the midpoint of two stored values is
 * itself storable and the round trip closes.
 */
export function mid(range) {
  if (range === null || range === undefined) return null

  if (typeof range !== 'object') return decimal(range)

  const min = decimal(range.min)
  const max = decimal(range.max)

  if (min === null) return max
  if (max === null) return min

  return roundTo((min + max) / 2, 3)
}

export function roundTo(value, digits) {
  if (value === null) return null

  const factor = 10 ** digits

  return Math.round(value * factor) / factor
}

/**
 * Display precision, matching what the day view prints: kcal whole, macros to a
 * tenth (DailyView::itemProps rounds the same way).
 */
export function roundFor(nutrient, value) {
  return roundTo(value, nutrient === 'kcal' ? 0 : 1)
}

/**
 * The weight somebody actually stated, or null when all this item has is the
 * 100 g basis. Exactly 100 reads as the basis, not as a weight — the same
 * inference the sheet makes when it loads a stored meal and the same one
 * MealEstimateController::typedItemFrom makes server-side, because "what did the
 * user type" has to have ONE answer or the boxes on screen and the row in the
 * table describe different food. The cost is a user who really did weigh out
 * 100 g, and their row is identical either way.
 */
export function portionOf(item) {
  const grams = decimal(item?.grams)

  return grams !== null && grams > 0 && grams !== BASIS_G ? grams : null
}

export function hasPortion(item) {
  return portionOf(item) !== null
}

/**
 * density -> total: grams x density / 100. A blank density gives a blank total,
 * NEVER 0 — the prefill shows what the item already says, and "0 kcal" is a
 * claim the user did not make. At the basis the density is returned untouched
 * (see the header).
 */
export function totalFor(per100g, grams) {
  const density = decimal(per100g)

  if (density === null) return null

  // Through `portionOf`, so a blank, a zero and the bare basis are one case
  // rather than three the caller has to remember.
  const weight = portionOf({ grams })

  return weight === null ? density : (density * weight) / 100
}

/**
 * total -> density: total / grams x 100, over the weight the item already had.
 * This is what stops the Quick tab eating a portion: an item weighed at 120 g
 * and edited to 300 kcal is 250 kcal/100 g of a 120 g portion, not a 100 g
 * cappuccino. Keeping the weight keeps the share chips, the day's arithmetic and
 * every future portion edit working.
 */
export function densityFor(total, grams) {
  const value = decimal(total)

  if (value === null) return null

  const weight = portionOf({ grams })

  return weight === null ? value : (value * 100) / weight
}

/**
 * The Quick view: the four totals, from whatever the per-100 g state says.
 * Mutates, because the caller owns a reactive form item. Rounded ONLY when there
 * is a real weight — at the basis the total IS the density, and rounding it
 * would be the one thing standing between tapping a tab and changing a number.
 */
export function toQuick(item) {
  const weighed = hasPortion(item)

  for (const nutrient of NUTRIENTS) {
    const total = totalFor(item[`${nutrient}_per_100g`], item.grams)

    item[nutrient] = weighed ? roundFor(nutrient, total) : total
  }

  item.basis = 'absolute'

  return item
}

/**
 * The per-100 g view of an item.
 *
 * With a stated weight there is nothing to compute: the densities ARE the state,
 * and every Quick edit already wrote back through `editTotal`. Recomputing them
 * from the rounded totals on screen is precisely how a toggle starts drifting.
 * Without a weight the density is the total, exactly.
 */
export function toPer100g(item) {
  if (!hasPortion(item)) {
    for (const nutrient of NUTRIENTS) {
      item[`${nutrient}_per_100g`] = densityFor(item[nutrient], null)
    }
  }

  item.basis = 'per_100g'

  return item
}

/**
 * One item as it is posted, given the tab it is showing.
 *
 * ONE CANONICAL SHAPE PER MODE: the half the visible mode does not own is
 * blanked rather than left as whatever the other tab filled in, so toggling out
 * and back is byte-identical rather than merely equivalent.
 *
 * The rule with teeth is the first line: an item showing Quick is still posted
 * as per-100 g when it has a stated weight. The server reads `grams` in that
 * mode and nowhere else (TypedItem::fromValidated), so posting `absolute` would
 * silently re-file a weighed 120 g portion as the 100 g basis — the portion, the
 * share chips and every later re-weigh gone, on an item nobody meant to change.
 */
export function payloadFor(item) {
  const perHundred = item.basis === 'per_100g' || hasPortion(item)

  const total = (nutrient) => (perHundred ? null : decimal(item[nutrient]))
  const density = (nutrient) => (perHundred ? decimal(item[`${nutrient}_per_100g`]) : null)

  return {
    name: item.name,
    basis: perHundred ? 'per_100g' : 'absolute',
    share_fraction: item.share_fraction ?? 1,
    kcal: total('kcal'),
    protein: total('protein'),
    carbs: total('carbs'),
    fat: total('fat'),
    grams: perHundred ? decimal(item.grams) : null,
    kcal_per_100g: density('kcal'),
    protein_per_100g: density('protein'),
    carbs_per_100g: density('carbs'),
    fat_per_100g: density('fat'),
    food_product_id: item.food_product_id ?? null,
  }
}
