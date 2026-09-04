import assert from 'node:assert/strict'
import test from 'node:test'

import { dayMonth, isoDayMonth, isWithinIsoBounds, MONTHS, parseIsoDate, toIsoDate, WEEKDAY_NAMES } from '../../resources/js/lib/dates.js'

/**
 * The conversion between a calendar day and a `Date`.
 *
 * WHY THIS AND NOT THE COMPONENT: there is no component renderer here (no
 * vitest, no jsdom — see ci.sh), so what gets a test is the logic reachable as a
 * module. It is also the half where a fault is invisible — a picker that opens
 * on the wrong day still opens and still navigates.
 *
 * THE OFF-BY-ONE THESE EXIST FOR: `new Date('2026-06-15')` is UTC midnight and
 * `toISOString()` serialises in UTC, either of which shifts the day outside
 * Greenwich. The round trips run under a PINNED TZ, because on a London machine
 * they would pass while being wrong everywhere the app is used.
 */

/** Run one body under a fixed timezone and always put the old one back. V8
 * re-reads `process.env.TZ` on the next Date construction, so setting it inside
 * the test is enough — no subprocess, no runner flag. */
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

/** The app's own zone (UTC+2 in June), and one on the other side of Greenwich. */
const ZONES = ['Europe/Amsterdam', 'America/Los_Angeles', 'UTC', 'Pacific/Kiritimati']

test('a parsed date is local midnight on the day that was written', () => {
  for (const tz of ZONES) {
    inTimezone(tz, () => {
      const date = parseIsoDate('2026-06-15')

      assert.equal(date.getFullYear(), 2026, tz)
      assert.equal(date.getMonth(), 5, tz)
      assert.equal(date.getDate(), 15, tz)
      assert.equal(date.getHours(), 0, tz)
    })
  }
})

test('a date survives the round trip in every zone', () => {
  for (const tz of ZONES) {
    inTimezone(tz, () => {
      for (const iso of ['2026-06-15', '2026-01-01', '2025-12-31', '2024-02-29', '2023-04-05']) {
        assert.equal(toIsoDate(parseIsoDate(iso)), iso, `${iso} in ${tz}`)
      }
    })
  }
})

test('a date picked at local midnight does not serialise as the day before', () => {
  // East of Greenwich, `toISOString()` on local midnight is yesterday.
  inTimezone('Europe/Amsterdam', () => {
    const picked = new Date(2026, 5, 15)

    assert.equal(picked.toISOString().slice(0, 10), '2026-06-14', 'precondition')
    assert.equal(toIsoDate(picked), '2026-06-15')
  })
})

test('a date late in the local day still serialises as that day', () => {
  inTimezone('America/Los_Angeles', () => {
    // 23:30 local is already tomorrow in UTC.
    assert.equal(toIsoDate(new Date(2026, 5, 15, 23, 30)), '2026-06-15')
  })
})

test('an unbounded end of the history stays a non-date', () => {
  // HistorySpan sends null for "not known yet"; today would be a false floor.
  assert.equal(parseIsoDate(null), null)
  assert.equal(parseIsoDate(undefined), null)
  assert.equal(parseIsoDate(''), null)
})

test('anything that is not a calendar day is refused rather than guessed at', () => {
  for (const value of ['2026-6-15', '15/06/2026', '2026-06-15T10:00:00Z', 'today', '2026-06', 26]) {
    assert.equal(parseIsoDate(value), null, String(value))
  }
})

test('a day that does not exist is refused rather than rolled forward', () => {
  // `new Date(2026, 1, 31)` is 3 March — a day nobody asked for.
  assert.equal(parseIsoDate('2026-02-31'), null)
  assert.equal(parseIsoDate('2025-02-29'), null)
  assert.equal(parseIsoDate('2026-13-01'), null)
  assert.equal(parseIsoDate('2026-00-10'), null)
  assert.equal(parseIsoDate('2026-06-00'), null)
})

test('a leap day that does exist is kept', () => {
  assert.equal(toIsoDate(parseIsoDate('2024-02-29')), '2024-02-29')
})

test('an invalid date formats as blank rather than as "Invalid Date"', () => {
  assert.equal(toIsoDate(new Date('nonsense')), '')
  assert.equal(toIsoDate(null), '')
  assert.equal(toIsoDate(undefined), '')
  assert.equal(toIsoDate(''), '')
  assert.equal(toIsoDate('2026-06-15'), '')
})

/**
 * The contract lib/air-date.js is built on, written down where it is made. The
 * picker crash in `client_errors` row 7 was NOT a fault here — it was the
 * component handing this module's honest `null` to a library that calls
 * `.toString()` on anything. Pinned here: these three keep ANSWERING rather than
 * throwing; air-date.test.js pins what the caller owes the answer.
 */
test('nothing here throws on an empty value, in any of its three spellings', () => {
  for (const empty of [null, undefined, '']) {
    assert.equal(parseIsoDate(empty), null, String(empty))
    assert.equal(toIsoDate(empty), '', String(empty))
    assert.equal(isWithinIsoBounds(empty), false, String(empty))

    // And as a BOUND, the shape the crash arrived in: an open end never narrows.
    assert.equal(isWithinIsoBounds('2026-06-15', empty, empty), true, String(empty))
  }
})

test('bounds are inclusive at both ends', () => {
  assert.equal(isWithinIsoBounds('2026-06-15', '2026-06-15', '2026-06-15'), true)
  assert.equal(isWithinIsoBounds('2026-06-14', '2026-06-15', '2026-06-20'), false)
  assert.equal(isWithinIsoBounds('2026-06-21', '2026-06-15', '2026-06-20'), false)
})

test('a missing bound is an open end, not a closed one', () => {
  assert.equal(isWithinIsoBounds('1999-01-01', null, '2026-06-20'), true)
  assert.equal(isWithinIsoBounds('2999-01-01', '2026-06-15', null), true)
  assert.equal(isWithinIsoBounds('2026-06-15'), true)
})

test('bounds compare across month and year ends', () => {
  // Lexical, not numeric: zero padding makes '2026-09-01' < '2026-10-01' true.
  assert.equal(isWithinIsoBounds('2026-09-30', '2026-09-01', '2026-10-01'), true)
  assert.equal(isWithinIsoBounds('2025-12-31', '2026-01-01', '2026-12-31'), false)
  assert.equal(isWithinIsoBounds('2026-01-01', '2025-12-31', '2026-01-02'), true)
})

test('a value that is not a calendar day is never within bounds', () => {
  assert.equal(isWithinIsoBounds('', '2026-01-01', '2026-12-31'), false)
  assert.equal(isWithinIsoBounds(null), false)
  assert.equal(isWithinIsoBounds('nonsense'), false)
})

/* THE APP'S OWN MONTH NAMES. Asked for a short month, en-GB answers "Sept" for
 * September — four letters, the only month it does not cut to three — while the
 * server (Carbon), the charts and the date picker all say "Sep". One month a
 * year the same date was written two ways on one screen. The names are ours
 * now, so the browser's locale data cannot move them, and a second browser with
 * older CLDR data cannot disagree with the first. */

test('every month name is three letters, September included', () => {
  assert.equal(MONTHS.length, 12)
  assert.deepEqual(MONTHS.filter((m) => m.length !== 3), [])
  assert.equal(MONTHS[8], 'Sep')
})

test('every weekday name is three letters, Sunday-indexed', () => {
  assert.equal(WEEKDAY_NAMES.length, 7)
  assert.deepEqual(WEEKDAY_NAMES.filter((d) => d.length !== 3), [])
  assert.equal(WEEKDAY_NAMES[0], 'Sun')
  assert.equal(WEEKDAY_NAMES[4], 'Thu')
})

test('a day and month are written from those names', () => {
  assert.equal(dayMonth(new Date(2026, 8, 6)), '6 Sep')
  assert.equal(isoDayMonth('2026-09-06'), '6 Sep')
  assert.equal(isoDayMonth('2026-08-06'), '6 Aug')
})

test('an unusable date is an empty string, never "Invalid Date"', () => {
  assert.equal(isoDayMonth('2026-02-31'), '')
  assert.equal(isoDayMonth(''), '')
  assert.equal(isoDayMonth(null), '')
  assert.equal(dayMonth(new Date('nonsense')), '')
  assert.equal(dayMonth('2026-09-06'), '')
})
