import assert from 'node:assert/strict'
import test from 'node:test'

import { errorLine } from '../../resources/js/lib/error-rows.js'

/**
 * The prefix has one job: make two identical sentences tell two rows apart.
 *
 * Everything here is about the list at the foot of the meal sheets, which is
 * where every refusal lands. The sentence itself is the server's and is never
 * touched — the tests that prove THAT are the agreement pair; these prove only
 * what is put in front of it.
 */

const items = [{ name: 'Rijst' }, { name: '' }, { name: '  ' }, {}]

test('a row that has a name is named', () => {
  assert.equal(
    errorLine('items.0.kcal', 'The calories field must not be greater than 20000.', items),
    'Rijst — The calories field must not be greater than 20000.',
  )
})

test('a row with nothing typed in it is numbered the way the screen numbers it', () => {
  // Key 1 is the SECOND row on screen: the keys count from zero and people do not.
  assert.equal(
    errorLine('items.1.name', 'The food name field is required.', items),
    'Item 2 — The food name field is required.',
  )

  // A name of spaces is not a name, here or on the server.
  assert.equal(errorLine('items.2.kcal', 'x', items), 'Item 3 — x')

  // No name key at all — the blank row every sheet keeps open at the end.
  assert.equal(errorLine('items.3.kcal', 'x', items), 'Item 4 — x')
})

test('a refusal about a row that is no longer on the sheet still says which one it was', () => {
  // A queued save answered after the row was deleted: there is no name to read,
  // and a bare sentence would be a refusal about nothing at all.
  assert.equal(errorLine('items.9.kcal', 'x', items), 'Item 10 — x')
  assert.equal(errorLine('items.0.kcal', 'x', undefined), 'Item 1 — x')
})

test('anything that is not a row is left exactly as it is', () => {
  for (const key of ['date', 'time', 'notes', 'items', 'share_fraction', 'items.notanumber.kcal']) {
    assert.equal(errorLine(key, 'The date field is required.', items), 'The date field is required.')
  }
})

test('the sentence itself is never edited', () => {
  const sentence = 'The portion range end field must not be greater than 10000.'

  assert.ok(errorLine('items.5.portion_g_max', sentence, items).endsWith(sentence))
})
