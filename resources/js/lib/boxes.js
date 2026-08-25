/**
 * WHOSE NUMBER IS IN THE BOX, AND THEREFORE HOW IT IS WRITTEN.
 *
 * WHAT WENT WRONG WITHOUT THIS FILE. The edit sheet's Per 100 g row showed
 * "130.8333", "2.3456", "163.3333", tails cut off at the edge of a field a fifth
 * of a phone wide, so the user could not read the value they were being asked to
 * correct. Every one came out of an arithmetic the app did on their behalf — a
 * total over a portion (`densityFor`), a range collapsed to its midpoint, kJ
 * turned into kcal — none of them a measurement to fourteen significant figures,
 * and printing one claims precision the source never made.
 *
 * `numberText` (lib/format.js) answers the writing half. This file answers the
 * harder half: A BOX THE USER IS TYPING IN IS NOT THE APP'S TO REWRITE. Round
 * the bound value and the rounding happens on every keystroke — type "2.36" into
 * a box formatted to a tenth and Vue re-renders "2.4" over the third character,
 * caret jumping to the end — and the same rule on blur is no better.
 *
 * So the two cases are told apart by the only question that distinguishes them:
 * DOES THE TEXT IN THE BOX STILL PARSE TO THE VALUE THE ITEM HOLDS? If yes, the
 * user put it there and their text stands exactly as typed — "2,50" keeps the
 * comma the keypad types and the zero they chose to write. If no, the app has
 * written a new value into that field (a tab converted the item, a share chip
 * re-scaled it, an estimate came back, the sheet reopened) and the box is filled
 * in at the label's precision. That test is self-clearing, which is the point of
 * choosing it: no caller has to remember to invalidate anything, and a
 * population path added later gets the correct behaviour for free.
 *
 * WHAT THIS DOES NOT TOUCH IS THE STATE, AND THEREFORE THE PAYLOAD. The item
 * keeps the unrounded number and only the drawn text is rounded, so toggling
 * Quick / Per 100 g posts the identical bytes it always did (lib/basis.js is
 * explicit that this is a requirement, not an aspiration). Rounding into the
 * state would break that where the user can watch it happen: 4.9 g of protein
 * over a 250 g portion is 1.96 g/100 g, which is 2.0 to a tenth, which is 5.0 g
 * back. The honest consequence is that a box can show 131 while the item holds
 * 130.833, and typing there commits the 131 — bounded by the display precision.
 */

import { decimal, numberText } from './format.js'

/**
 * kcal whole, everything else in these sheets to a tenth — the same split
 * `roundFor` makes in lib/basis.js and `DailyView::itemProps` makes server-side,
 * so a number does not change shape on its way from the day card into the box
 * that edits it. Matched on the name so that every spelling of the same quantity
 * lands on the same precision: `kcal`, `kcal_per_100g`, `kcal_min`, `kcal_max`.
 */
export function digitsFor(field) {
  return String(field).includes('kcal') ? 0 : 1
}

/**
 * The text each field of each item was last typed with. Weak, and keyed on the
 * item object itself: form.reset(), a new meal, a removed row and a fresh
 * proposal all hand out NEW objects, so the text a previous item was typed with
 * cannot follow the row that replaces it.
 */
const typed = new WeakMap()

/** Called by the one function each component parses its input through. */
export function typedIn(owner, field, raw) {
  const fields = typed.get(owner) ?? {}

  fields[field] = raw

  typed.set(owner, fields)
}

/** What the user typed, or the value at the precision its label claims. */
export function boxText(owner, field, value) {
  const raw = typed.get(owner)?.[field]
  const current = value === null || value === undefined || value === '' ? null : Number(value)

  if (raw !== undefined && decimal(raw) === current) return raw

  return numberText(value, digitsFor(field))
}
