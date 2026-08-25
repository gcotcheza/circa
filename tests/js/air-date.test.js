import assert from 'node:assert/strict'
import test from 'node:test'

import {
  pickerAnchor,
  pickerBounds,
  pickerDates,
  pickerSelectedDates,
  pickerSelection,
} from '../../resources/js/lib/air-date.js'

/**
 * The options that go into Air Datepicker.
 *
 * THE REGRESSION THESE EXIST FOR — `client_errors` row 7: `TypeError: null is
 * not an object (evaluating 'r.toString')` on /profile, out of `new
 * AirDatepicker`. Air Datepicker 3.6.0 merges its options with
 *   if (undefined !== value && '[object Object]' === value.toString())
 * so a NULL option value hits `null.toString()`: the component passed
 * `minDate: null` for an unbounded date of birth and the picker threw
 * mid-construction (resolved stack in lib/air-date.js). The property held down
 * here is that NO FUNCTION IN THAT MODULE EVER RETURNS A NULL WHERE THE LIBRARY
 * WILL SEE IT — `pickerSelection` excepted, being read by the component, where
 * `null` means "highlight nothing". Dates are local-midnight `Date`s from
 * lib/dates.js, which has its own timezone tests.
 */

/** Run one body under a fixed timezone and always put the old one back. */
function inTimezone(tz, body) {
  const before = process.env.TZ

  process.env.TZ = tz

  try {
    body()
  } finally {
    if (before === undefined) {
      delete process.env.TZ
    } else {
      process.env.TZ = before
    }
  }
}

/** Everything a caller can put in a prop that is typed `String` and optional. */
const EMPTIES = [null, undefined, '']

/** The four call sites as `[modelValue, min, max]`. Every one crashed before this
 * module existed, except the two DateJumps on a database with history. */
const CALL_SITES = {
  'Profile, date of birth never filled in': ['', null, null],
  'Profile, date of birth already set': ['1990-08-07', null, null],
  'ReportForm, the From box': ['', null, '2026-06-15'],
  'ReportForm, the To box': ['2026-06-15', null, '2026-06-15'],
  'DateJump on Day': ['2026-06-15', '2023-04-05', '2026-06-15'],
  'DateJump on Day, empty database': ['2026-06-15', null, '2026-06-15'],
  'DateJump on Stress': ['2026-06-08', '2024-09-01', '2026-06-15'],
  'DateJump on Stress, no HRV yet': ['2026-06-08', null, '2026-06-15'],
}

test('an open end is the empty string the library means by it, never null', () => {
  for (const empty of EMPTIES) {
    const bounds = pickerBounds(empty, empty)

    assert.equal(bounds.minDate, '', String(empty))
    assert.equal(bounds.maxDate, '', String(empty))
  }
})

test('a bound that is not a calendar day is an open end rather than a guess', () => {
  // Inventing a date here would put a floor under a picker that should have none.
  for (const value of ['2026-6-15', 'today', '2026-02-31', 26, {}]) {
    assert.equal(pickerBounds(value, value).minDate, '', String(value))
  }
})

test('a real bound arrives as local midnight on the day that was written', () => {
  for (const tz of ['Europe/Amsterdam', 'America/Los_Angeles', 'Pacific/Kiritimati']) {
    inTimezone(tz, () => {
      const { minDate, maxDate } = pickerBounds('2023-04-05', '2026-06-15')

      assert.equal(minDate.getFullYear(), 2023, tz)
      assert.equal(minDate.getDate(), 5, tz)
      assert.equal(minDate.getHours(), 0, tz)
      assert.equal(maxDate.getMonth(), 5, tz)
      assert.equal(maxDate.getDate(), 15, tz)
    })
  }
})

test('an empty model highlights nothing, and says so the way the library does', () => {
  for (const empty of EMPTIES) {
    assert.equal(pickerSelection(empty, null, null), null, String(empty))

    // `false`, not `[]`: an empty array is truthy, and `update()` does
    // `selectedDates && selectDate(selectedDates)`.
    assert.equal(pickerSelectedDates(empty, null, null), false, String(empty))
  }
})

test('a date outside the range is not highlighted', () => {
  // A selected square in a greyed-out month looks broken rather than refusing.
  assert.equal(pickerSelection('2020-01-01', '2023-04-05', '2026-06-15'), null)
  assert.equal(pickerSelection('2030-01-01', '2023-04-05', '2026-06-15'), null)
  assert.equal(pickerSelectedDates('2020-01-01', '2023-04-05', '2026-06-15'), false)
})

test('a date inside the range is highlighted, once', () => {
  const selected = pickerSelectedDates('2026-06-15', '2023-04-05', '2026-06-15')

  assert.equal(selected.length, 1)
  assert.equal(selected[0].getFullYear(), 2026)
  assert.equal(selected[0].getDate(), 15)
})

test('an empty picker opens on today', () => {
  // The profile's date of birth: no selection, no bounds, still has to open somewhere.
  const today = new Date(2026, 5, 15, 14, 44)

  assert.equal(pickerAnchor('', null, null, today), today)
  assert.equal(pickerAnchor(null, undefined, undefined, today), today)
})

test('a picker whose range ended in the past opens on the end of it', () => {
  // Not today, which would be greyed-out squares and a back arrow as the way out.
  const today = new Date(2026, 5, 15, 14, 44)
  const anchor = pickerAnchor('', null, '2025-12-31', today)

  assert.equal(anchor.getFullYear(), 2025)
  assert.equal(anchor.getMonth(), 11)
  assert.equal(anchor.getDate(), 31)
})

test('a picker whose range starts in the future opens on the start of it', () => {
  const today = new Date(2026, 5, 15, 14, 44)
  const anchor = pickerAnchor('', '2027-01-01', null, today)

  assert.equal(anchor.getFullYear(), 2027)
  assert.equal(anchor.getMonth(), 0)
  assert.equal(anchor.getDate(), 1)
})

test('a picker with a value opens on the value', () => {
  const today = new Date(2026, 5, 15, 14, 44)
  const anchor = pickerAnchor('2024-02-29', '2023-04-05', '2026-06-15', today)

  assert.equal(anchor.getFullYear(), 2024)
  assert.equal(anchor.getMonth(), 1)
  assert.equal(anchor.getDate(), 29)
})

test('a value the range rules out does not drag the calendar out of range', () => {
  // Ignored for the highlight above, so ignored for the opening month too.
  const today = new Date(2026, 5, 15, 14, 44)
  const anchor = pickerAnchor('2020-01-01', '2023-04-05', '2026-06-15', today)

  assert.equal(anchor.getFullYear(), 2026)
  assert.equal(anchor.getMonth(), 5)
})

test('NOT ONE option is ever null or undefined, for any call site', () => {
  // The property, not the symptom: a null in any of these is a TypeError out of
  // the AirDatepicker constructor and a date box with no calendar behind it.
  for (const [where, [modelValue, min, max]] of Object.entries(CALL_SITES)) {
    const options = pickerDates(modelValue, min, max)

    assert.deepEqual(
      Object.keys(options).sort(),
      ['maxDate', 'minDate', 'selectedDates', 'startDate'],
      where
    )

    for (const [key, value] of Object.entries(options)) {
      assert.notEqual(value, null, `${where}: ${key} is null`)
      assert.notEqual(value, undefined, `${where}: ${key} is undefined`)
    }
  }
})

test('NOT ONE option is ever null, however empty or malformed the props are', () => {
  const nonsense = [...EMPTIES, 'today', '2026-6-15', '2026-02-31', 0, false, {}, []]

  for (const modelValue of nonsense) {
    for (const min of nonsense) {
      for (const max of nonsense) {
        for (const value of Object.values(pickerDates(modelValue, min, max))) {
          assert.notEqual(value, null, `${modelValue} / ${min} / ${max}`)
          assert.notEqual(value, undefined, `${modelValue} / ${min} / ${max}`)
        }
      }
    }
  }
})

test('the profile crash, exactly as it was reported', () => {
  // /profile, date of birth never filled in, both bounds open. Before the fix
  // this was `{ minDate: null, maxDate: null }` → `null.toString()` in deepMerge.
  const options = pickerDates('', null, null)

  assert.equal(options.minDate, '')
  assert.equal(options.maxDate, '')
  assert.equal(options.selectedDates, false)
  assert.ok(options.startDate instanceof Date)
})
