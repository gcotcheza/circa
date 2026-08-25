import { KIND, submitOrQueue } from './queue.js'
import { postForm } from './http.js'

/**
 * The supplements client: one tap, and the optional label reading.
 *
 * THE TAP GOES THROUGH THE QUEUE because of WHEN this button gets pressed:
 * supplements are taken in a kitchen at nine at night, on one bar of 4G or a
 * wifi network the handset has half fallen off, and a tick that failed silently
 * there would be the app quietly recording that somebody skipped their magnesium
 * on a day they did not. `submitOrQueue` is the same path the meal sheet and the
 * review screen use, so the request the server sees on a replay is
 * byte-identical to the one it would have seen at the time — safe to replay
 * because the endpoint is a PUT of the complete desired state, `{date, taken}`,
 * behind a unique index on (supplement, local_date). See
 * App\Http\Controllers\SupplementIntakeController.
 */

/**
 * Tick or un-tick one supplement for one local date. `date` is the day being
 * LOOKED AT, not today: ticking last night's magnesium over this morning's
 * coffee is the single most common reason this is called. The action id is
 * derived from the pair rather than random, so a second tap on the same row
 * while the first is still queued REPLACES it instead of stacking a tick and an
 * untick to be replayed in some order — the last thing the user said is what
 * should reach the server.
 */
export function setSupplementTaken(supplementId, date, taken) {
  return submitOrQueue({
    id: `supplement:${supplementId}:${date}`,
    kind: KIND.supplementIntake,
    url: `/api/supplements/${supplementId}/intake`,
    method: 'PUT',
    payload: { date, taken },
    date,
    meta: { label: taken ? 'Supplement taken' : 'Supplement un-ticked' },
  })
}

/**
 * "Take all": tick everything on the shelf that is not ticked yet, for one day.
 *
 * WHY THIS LOOPS THE EXISTING ENDPOINT RATHER THAN ADDING A BULK ONE. The queue
 * de-duplicates by ACTION ID, and `setSupplementTaken` derives that id from the
 * pair — `supplement:7:2026-08-10`. That is what makes "take all, then expand
 * and un-tick the fish oil" correct with no radio: the un-tick REPLACES the
 * queued tick, so one statement per bottle per day ever reaches the server and
 * it is the last one the user made. A bulk `PUT /api/supplements/intake` would
 * sit under an id of its own — nothing to replace, nothing to be replaced by —
 * so flushing it after the un-tick silently re-ticks a bottle the user just said
 * no to. The loop costs N requests, N being the bottles on a shelf (four today),
 * against no new server route and no new replay rule to get wrong.
 *
 * SEQUENTIAL, NOT `Promise.all`. The queue's own flush is sequential, and
 * `queueIt` checks its ceiling with a read-then-write that is not atomic — four
 * parallel offline writes could all read the same count and step over
 * MAX_ACTIONS together. Nobody is waiting: the card has drawn every tick before
 * the first request is sent.
 *
 * ALREADY-TICKED BOTTLES ARE SKIPPED, which makes pressing this twice free
 * rather than merely harmless. The endpoint would have absorbed the replay
 * regardless; see App\Http\Controllers\SupplementIntakeController.
 *
 * @returns {Promise<Array<{id: number, ok: boolean, queued?: boolean, message?: string}>>}
 *          one entry per bottle it actually wrote, in the order it wrote them.
 */
export async function takeAllSupplements(items, date) {
  const results = []

  for (const item of items ?? []) {
    if (item.taken) continue

    results.push({ id: item.id, ...(await setSupplementTaken(item.id, date, true)) })
  }

  return results
}

/**
 * Send one prepared label photograph. Deliberately NOT queued, unlike the tap: a
 * label reading is watched — it ends in a review screen to proof-read — and one
 * that quietly arrived tomorrow would be a review screen for a bottle already
 * put away. The button says so when there is no connection.
 */
export function uploadLabel({ clientId, idempotencyKey, blob }) {
  const form = new FormData()

  form.append('client_id', clientId)
  form.append('idempotency_key', idempotencyKey)
  form.append('photo', blob, 'label.jpg')

  return postForm('/api/supplements/label', form, {
    offline: 'Reading a label needs the network.',
    busy: 'Too many photos in a row. Wait a minute.',
    failed: 'The upload failed.',
  })
}

/**
 * One poll. Null on any failure, because a poll that cannot answer is not news
 * — the caller keeps polling and the screen still shows the last true thing.
 */
export async function pollLabel(clientId) {
  try {
    const response = await fetch(`/api/supplements/label/${clientId}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok) return null

    return await response.json()
  } catch {
    return null
  }
}
