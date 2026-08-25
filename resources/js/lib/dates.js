/**
 * ISO calendar dates, in and out of a JavaScript `Date`.
 *
 * Dates on the wire are CALENDAR DAYS in the reporting timezone; the browser
 * only offers instants, and both popular conversions land a day out rather than
 * obviously breaking. `new Date(iso)` parses as UTC MIDNIGHT, which west of
 * Greenwich is the previous day; `toISOString()` serialises in UTC, so east of
 * Greenwich a date picked at local midnight comes back as the day before — the
 * trap that is live here, the app having run in Europe/Amsterdam (UTC+1/+2)
 * since the first commit. Air Datepicker hands out a `Date` where the
 * `<input type=date>` it replaces spoke YYYY-MM-DD both ways, so the conversion
 * lives here, with tests, and both functions work in LOCAL time on purpose: the
 * user taps a square on a local calendar and means the day with that number.
 */

/** `2026-06-15`, and nothing else. Anything looser is not a date we sent. */
const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

const pad = (n) => String(n).padStart(2, '0')

/**
 * A YYYY-MM-DD string as a `Date` at LOCAL midnight, or null.
 *
 * An unbounded end of history arrives as `null` from the server (HistorySpan)
 * and must stay a non-date: a bound that silently became "today" would hide
 * real history behind the picker. The `getMonth()` round-trip is what rejects
 * `2026-02-31`, which the constructor would roll forward to 3 March.
 */
export function parseIsoDate(value) {
  if (typeof value !== 'string') return null

  const match = ISO_DATE.exec(value)

  if (!match) return null

  const [, year, month, day] = match
  const date = new Date(Number(year), Number(month) - 1, Number(day))

  if (
    date.getFullYear() !== Number(year) ||
    date.getMonth() !== Number(month) - 1 ||
    date.getDate() !== Number(day)
  ) {
    return null
  }

  return date
}

/**
 * A `Date` as the YYYY-MM-DD of the LOCAL day it falls on, or '' if unusable.
 *
 * `''` rather than null: this feeds a v-model the forms treat as a string, and
 * an empty box is how every optional date says "not answered" (Profile.vue).
 */
export function toIsoDate(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return ''

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * Is `value` inside [min, max]? An absent bound is an open end.
 *
 * Decides whether a server date is worth seeding the picker's SELECTION with:
 * Air Datepicker selects dates outside its own minDate/maxDate when told to
 * programmatically, leaving a highlighted square in a greyed-out range that
 * cannot be re-picked. YYYY-MM-DD is fixed-width, zero-padded and big-endian,
 * so string order IS chronological order; parsed Dates would reintroduce the
 * timezone question this module answers.
 */
export function isWithinIsoBounds(value, min = null, max = null) {
  if (!ISO_DATE.test(String(value))) return false

  if (typeof min === 'string' && ISO_DATE.test(min) && value < min) return false

  if (typeof max === 'string' && ISO_DATE.test(max) && value > max) return false

  return true
}
