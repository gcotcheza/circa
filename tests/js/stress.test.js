import assert from 'node:assert/strict'
import test from 'node:test'

import { formatDay, formatShortDate } from '../../resources/js/lib/stress.js'

/**
 * The two dates the stress screens print. Both were rendered by the browser's
 * locale until September 2026 showed what that costs: en-GB writes "Sept" where
 * the server, the charts and the picker all write "Sep", so a tile read "1 Sept"
 * beside a report headed "1 Sep 2026". They now read from the app's own names.
 */

test('a stress day carries its weekday, in the app\u{2019}s names', () => {
  assert.equal(formatDay('2026-09-03'), 'Thu 3 Sep')
  assert.equal(formatDay('2026-08-06'), 'Thu 6 Aug')
})

test('the short form drops the weekday the context already gave', () => {
  assert.equal(formatShortDate('2026-09-06'), '6 Sep')
  assert.equal(formatShortDate('2026-08-06'), '6 Aug')
})

test('a date the server did not send prints nothing', () => {
  assert.equal(formatDay(''), '')
  assert.equal(formatDay(null), '')
  assert.equal(formatShortDate(''), '')
  assert.equal(formatShortDate('2026-02-31'), '')
})
