/**
 * The note that was still in the box when the user tapped Done.
 *
 * WHAT WENT WRONG WITHOUT THIS FILE. The note box is read once, at send time,
 * and rides the upload (`onFiles` in Components/PhotoCapture.vue) — the path the
 * feature was designed around: type "3 eggs, 120 g drained tuna", then
 * photograph the plate. Nobody uses it that way. The server's own logs put every
 * upload 13-23 seconds after a cold launch: the food is in front of them, so the
 * picture comes FIRST and the note is typed afterwards, into a box whose caption
 * then says "Goes with the next photo". When no next photo came, Done threw that
 * sentence away without a word — and `vision_requests` shows it: every initial
 * row for a photographed meal has an empty `input_payload`, while the
 * re-analysis row a minute later, the user typing the same sentence again and
 * asking by hand, carries the hint. The app made them say it twice.
 *
 * WHY THE DECISION IS HERE AND NOT IN THE COMPONENT. "Which plate does a
 * leftover note belong to, and what should be done about it" is four rules and
 * no rendering. Kept in the sheet it would be testable only by mounting Vue,
 * driving a file input and stubbing fetch — which is why the original lifecycle
 * went untested and why the gap above survived a review. Here `node --test` can
 * ask it directly.
 *
 * THE RULES. BACKWARD ONLY AT DONE: a note typed BETWEEN two courses still goes
 * forward, because that is the contract the caption makes while there is still a
 * shutter to press, and Done is the moment that stops being true. THE LAST
 * PLATE, NOT THE FIRST: "120 g of drained tuna" typed after three courses is
 * about the third, the plate on the user's mind. A FAILED PLATE IS NOT A TARGET:
 * nothing reached the server, so there is nothing to attach a note to and no
 * analysis for it to improve, and the note falls back to the most recent plate
 * that did land. NEITHER IS ONE STILL IN FLIGHT: a `sending` plate is neither on
 * the server nor in the queue, so there is no row to amend and no photo id to
 * re-analyse — and Done is disabled while anything is sending, so this is a
 * guard rather than a case. QUEUED IS AMENDED, NOT RE-ANALYSED: an offline plate
 * has not been analysed at all, so editing `payload.hint` on the record waiting
 * in IndexedDB costs nothing, needs no connection, and gets the note into the
 * FIRST analysis — the best outcome available anywhere in this file.
 */

/**
 * @typedef {{id: string, state: 'sending'|'sent'|'queued'|'failed'}} Plate
 * @typedef {{action: 'none'|'reanalyze'|'amendQueued', note: string, plateId: string|null}} NoteAction
 */

/**
 * What to do with the note still in the box when Done is tapped.
 *
 *   none          nothing typed, or nothing it could be about.
 *   reanalyze     the last plate is on the server: ask again with the note
 *                 attached. The endpoint records it on the plate as well as
 *                 sending it, so the answer is the one the user would have got
 *                 by typing the note twice.
 *   amendQueued   the last plate is still queued: rewriting the hint there
 *                 gets the note in with the photograph itself.
 *
 * @param {string|null|undefined} note What is in the box, untrimmed.
 * @param {Plate[]} plates The strip, in the order the photos were taken.
 * @returns {NoteAction}
 */
export function leftoverNoteAction(note, plates) {
  // `trim()` travels everywhere else in this feature: a box holding three
  // spaces is an empty box, and it must not buy an analysis.
  const text = (note ?? '').trim()

  if (text === '') return { action: 'none', note: '', plateId: null }

  // Last first: the note is about the plate the user was looking at, and one
  // that reached neither server nor queue is skipped rather than stopping here.
  const target = [...(plates ?? [])]
    .reverse()
    .find((plate) => plate?.state === 'sent' || plate?.state === 'queued')

  if (!target) return { action: 'none', note: text, plateId: null }

  return {
    action: target.state === 'queued' ? 'amendQueued' : 'reanalyze',
    note: text,
    plateId: target.id,
  }
}
