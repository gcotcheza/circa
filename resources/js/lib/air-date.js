// With the extension, like lib/inertia-guard.js: this module is loaded directly
// by Node in tests/js, whose ESM resolver does not guess extensions as Vite does.
import { isWithinIsoBounds, parseIsoDate } from './dates.js'

/**
 * The options Air Datepicker is handed that depend on a VALUE.
 *
 * THE BUG THIS FILE EXISTS FOR
 *
 * `client_errors` row 7, kind `vue`, on build e3586ad222ff, from /profile:
 * `TypeError: null is not an object (evaluating 'r.toString')`. Resolved against
 * the deployed source map, its five frames are the callback inside `deepMerge`,
 * a native forEach, `deepMerge` itself, `this.opts = deepMerge({}, defaults,
 * opts)`, and AirDate.vue:143 — `new AirDatepicker(el, { ... })` in onMounted.
 * The line that throws, from air-datepicker 3.6.0:
 *
 *   for (let [key, value] of Object.entries(source))
 *     if (undefined !== value && '[object Object]' === value.toString()) {
 *
 * `undefined !== null` is TRUE, so a null option value goes straight into
 * `null.toString()`. Air Datepicker cannot be handed a null for ANY option — not
 * at construction, and not through `update()`, which merges the same way.
 *
 * AirDate.vue was handing it two: `minDate: parseIsoDate(props.min)` and
 * `maxDate: parseIsoDate(props.max)`, both null whenever that end of the range
 * is open. The day view and the stress page survived because the server sends
 * them two real dates out of a database with history in it; the profile's date
 * of birth carries no bounds at all, so BOTH were null and the picker threw
 * before finishing construction — which is what "the datepicker doesn't work
 * anymore" looks like from outside. The report's custom range (`max` only) and
 * any picker on an empty database were broken the same way, untapped.
 *
 * WHY A MODULE RATHER THAN TWO `?? ''`s IN THE COMPONENT
 *
 * Same reason lib/dates.js is a module: there is no component renderer in this
 * project (no vitest, no jsdom — see ci.sh), so anything left inside a `.vue`
 * file is untestable by construction, and this is a fault a test would have
 * caught before a phone did. Everything that turns a prop into a value the
 * library sees lives here, and tests/js/air-date.test.js asserts the one
 * property that matters: NOTHING THESE FUNCTIONS RETURN IS EVER NULL.
 */

/**
 * Air Datepicker's own spelling of "this end is open" — not null, and not
 * omission either. `''` is the library's own default for both bounds and
 * `_createMinMaxDates` reads them as
 * `this.minDate = !!minDate && createDate(minDate)`, so a falsy value is exactly
 * "no bound". Omitting the key would work at construction and be wrong on
 * `update()`, where a key not in the patch is a bound that never gets cleared —
 * a floor that disappeared would stay on the calendar.
 */
const OPEN_END = ''

/** The ends of the range, as the library wants them. Never null. */
export function pickerBounds(min, max) {
  return {
    minDate: parseIsoDate(min) ?? OPEN_END,
    maxDate: parseIsoDate(max) ?? OPEN_END,
  }
}

/**
 * The date to HIGHLIGHT, or null for none. Out-of-range values are deliberately
 * not highlighted: Air Datepicker will select a date outside its own min/max
 * when told to programmatically, and the result is a highlighted square inside a
 * greyed-out month that cannot be picked again — a control that looks broken
 * rather than one that says no.
 */
export function pickerSelection(modelValue, min, max) {
  return isWithinIsoBounds(modelValue, min, max) ? parseIsoDate(modelValue) : null
}

/**
 * The `selectedDates` option: a one-element array, or `false` for none. `false`
 * rather than `[]` because that is the library's own default and because `[]` is
 * truthy — `update()` does `selectedDates && selectDate(...)`, so an empty array
 * would take the re-select branch with nothing in it.
 */
export function pickerSelectedDates(modelValue, min, max) {
  const selection = pickerSelection(modelValue, min, max)

  return selection ? [selection] : false
}

/**
 * The month the calendar OPENS on, a different question from what it highlights:
 * an empty date of birth still has to open somewhere. Today, clamped into the
 * range — today is where the user is, and every date they might pick is some
 * number of taps from it; clamping stops a picker whose range ends in the past
 * from opening on a month where every square is greyed out and the only way
 * forward is the back arrow.
 */
export function pickerAnchor(modelValue, min, max, today = new Date()) {
  const selection = pickerSelection(modelValue, min, max)

  if (selection) return selection

  const floor = parseIsoDate(min)
  const ceiling = parseIsoDate(max)

  if (ceiling && today > ceiling) return ceiling

  if (floor && today < floor) return floor

  return today
}

/**
 * Every value-derived option for `new AirDatepicker`, in one object. The
 * component spreads this rather than assembling it inline, so there is one place
 * where a prop becomes something the library reads and one place a test can
 * point at.
 */
export function pickerDates(modelValue, min, max, today = new Date()) {
  return {
    ...pickerBounds(min, max),
    selectedDates: pickerSelectedDates(modelValue, min, max),
    startDate: pickerAnchor(modelValue, min, max, today),
  }
}
