import assert from 'node:assert/strict'
import test from 'node:test'

import { leftoverNoteAction } from '../../resources/js/lib/capture-note.js'

/**
 * resources/js/lib/capture-note.js — what happens to the note still in the box
 * when the user taps Done. Not a crash, which is why it survived: the sheet read
 * the note before the shutter, the user typed it after, and Done dropped it
 * silently. `vision_requests` shows it — an empty `input_payload` on every first
 * analysis of a photographed meal. A plate here is what the strip holds, an id
 * and a state, which is why the decision is testable without a camera or Vue.
 */

/** One entry of the strip, as `PhotoCapture` builds it. */
function plate(id, state) {
  return { id, url: `blob:${id}`, hint: '', state }
}

// --- the note is about the last plate that got somewhere -------------------

test('a leftover note is re-analysed against the last plate on the server', () => {
  const decision = leftoverNoteAction('3 eggs, 120 g drained tuna', [
    plate('first', 'sent'),
    plate('second', 'sent'),
  ])

  assert.deepEqual(decision, {
    action: 'reanalyze',
    note: '3 eggs, 120 g drained tuna',
    plateId: 'second',
  })
})

test('the note is trimmed, because the trimmed text is what travels', () => {
  const decision = leftoverNoteAction('  120 g drained tuna \n', [plate('only', 'sent')])

  assert.equal(decision.note, '120 g drained tuna')
})

test('one plate is the ordinary case, and it is the target', () => {
  const decision = leftoverNoteAction('half a portion', [plate('only', 'sent')])

  assert.equal(decision.action, 'reanalyze')
  assert.equal(decision.plateId, 'only')
})

// --- offline: the queued upload is amended, not re-analysed ----------------

test('a queued last plate has its own upload rewritten instead', () => {
  // Better than a re-analysis: the note reaches the FIRST analysis, for free.
  const decision = leftoverNoteAction('two poached eggs', [
    plate('first', 'sent'),
    plate('second', 'queued'),
  ])

  assert.deepEqual(decision, {
    action: 'amendQueued',
    note: 'two poached eggs',
    plateId: 'second',
  })
})

test('a sent plate after a queued one is still the target, and is re-analysed', () => {
  // The last plate is what the note is about; where it lives decides the route.
  const decision = leftoverNoteAction('the rice is about 200 g', [
    plate('first', 'queued'),
    plate('second', 'sent'),
  ])

  assert.equal(decision.action, 'reanalyze')
  assert.equal(decision.plateId, 'second')
})

// --- what is not a target --------------------------------------------------

test('a failed plate is skipped, and the note falls back to the one behind it', () => {
  const decision = leftoverNoteAction('120 g drained tuna', [
    plate('landed', 'sent'),
    plate('never-landed', 'failed'),
  ])

  assert.equal(decision.action, 'reanalyze')
  assert.equal(decision.plateId, 'landed')
})

test('nothing but failed plates is nothing to attach a note to', () => {
  const decision = leftoverNoteAction('120 g drained tuna', [
    plate('one', 'failed'),
    plate('two', 'failed'),
  ])

  assert.deepEqual(decision, { action: 'none', note: '120 g drained tuna', plateId: null })
})

test('a plate still in flight is not a target either', () => {
  // No server row to re-analyse and no queued payload to rewrite. Done is
  // disabled while sending, so this is a guard rather than a case.
  const decision = leftoverNoteAction('120 g drained tuna', [plate('in-flight', 'sending')])

  assert.equal(decision.action, 'none')
  assert.equal(decision.plateId, null)
})

test('no plates at all is no decision to make', () => {
  assert.equal(leftoverNoteAction('120 g drained tuna', []).action, 'none')
})

// --- the box that is already empty -----------------------------------------

test('an empty box buys nothing, and neither does a box full of spaces', () => {
  assert.deepEqual(leftoverNoteAction('', [plate('only', 'sent')]), {
    action: 'none',
    note: '',
    plateId: null,
  })

  assert.equal(leftoverNoteAction('   ', [plate('only', 'sent')]).action, 'none')
  assert.equal(leftoverNoteAction('\n\t ', [plate('only', 'sent')]).action, 'none')
  assert.equal(leftoverNoteAction(null, [plate('only', 'sent')]).action, 'none')
  assert.equal(leftoverNoteAction(undefined, [plate('only', 'sent')]).action, 'none')
})

test('a note consumed by the next photo is not sent a second time at Done', () => {
  /* The forward contract, which must NOT change: `onFiles` reads the box and
   * empties it, the plate carries the sentence, and Done then has nothing to do
   * — a second delivery would be a second analysis, charged for. */
  const box = { value: 'two poached eggs' }
  const strip = []

  // What `onFiles` does: read once, attach to the plate, empty the box.
  const note = box.value.trim()
  strip.push({ ...plate('taken', 'sent'), hint: note })
  box.value = ''

  assert.equal(strip[0].hint, 'two poached eggs')
  assert.equal(leftoverNoteAction(box.value, strip).action, 'none')
})
