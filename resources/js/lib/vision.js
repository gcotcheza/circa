/**
 * The photo upload and the analysis poll. Like lib/products.js, these return a
 * discriminated result rather than throwing: every outcome is a different screen
 * with a different next step, which a rejected promise would flatten into one.
 */

import { jsonHeaders, postForm, postJson } from './http.js'

/**
 * What a refusal from these three endpoints says. `failed` is the last resort
 * behind both shapes a refusal arrives in — a 422 from the form request as
 * {errors: {photo: [...]}}, one from the image decoder as {message} — read in
 * that order, the envelope first, which is the order lib/health-reports.js
 * deliberately reverses for itself.
 */
const COPY = {
  offline: 'No connection. The upload needs the network.',
  busy: 'Too many photos in a row. Wait a minute.',
  failed: 'The upload failed.',
}

/**
 * Send one prepared plate. THREE IDS, THREE JOBS: `uuid` is the MEAL, shared by
 * every plate of one dinner so a second helping joins it rather than starting a
 * new one; `clientId` is this PHOTO, so a plate flushed twice from the offline
 * queue is still one row; `idempotencyKey` is the ANALYSIS, so a retry is free.
 */
export function uploadMealPhoto({ uuid, clientId, idempotencyKey, date, time, hint, blob }) {
  const form = new FormData()

  form.append('uuid', uuid)
  form.append('client_id', clientId)
  form.append('idempotency_key', idempotencyKey)
  form.append('date', date)
  form.append('time', time)

  /*
   * What the user already knows about this plate — "3 eggs, 120 g drained
   * tuna". OMITTED rather than sent empty, so an unhinted upload stays
   * byte-identical to what this endpoint always got and the column stays NULL.
   */
  if (hint !== null && hint !== undefined && hint !== '') form.append('hint', hint)

  form.append('photo', blob, 'meal.jpg')

  return postForm('/api/meals/photo', form, COPY)
}

/**
 * Analyse ONE plate again — deliberately a NEW key, not a retry. Scoped to the
 * photo because a meal has several: "try again" means try this course again, and
 * every other plate's items stay exactly where they are.
 */
export function reanalyzePhoto(uuid, clientId, idempotencyKey, shareFraction = null, hint = null, items = null) {
  return postJson(`/api/meals/${uuid}/photos/${clientId}/vision`, {
    idempotency_key: idempotencyKey,

    /*
     * The figures the sheet is holding RIGHT NOW. The two taps are independent:
     * "that portion is wrong, it was 250 g — try again" has no Confirm between
     * the correction and the ask, so a correction living only in a text box is
     * what a re-analysis used to throw away. Omitted when there is nothing to
     * state, keeping an untouched re-analysis byte-identical to the old request.
     */
    ...(items === null || items.length === 0 ? {} : { items }),

    /*
     * A hint thought of after the photograph reaches the model only when the
     * model is asked again. `''` is a deletion and the server stores NULL;
     * omitted means nothing to say, leaving whatever the plate already had.
     */
    ...(hint === null ? {} : { hint }),
    /*
     * The plate's share, so a re-analysed shared plate stays shared. The model
     * is never told about it — a photograph can only settle what is on the plate
     * — so the answer returns at full size and the server re-applies the
     * fraction. Omitted rather than null: absent leaves the plate as it is,
     * null would reset a plate already marked ½ back to All.
     */
    ...(shareFraction === null ? {} : { share_fraction: shareFraction }),
  }, COPY)
}

/**
 * Take one plate off a meal. `removeItems` answers "and the food that came off
 * it?" — the default keeps anything already confirmed, because deleting a
 * photograph is a statement about the photograph.
 */
export async function removeMealPhoto(uuid, clientId, removeItems = false) {
  let response

  try {
    response = await fetch(`/api/meals/${uuid}/photos/${clientId}`, {
      method: 'DELETE',
      headers: jsonHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify({ remove_items: removeItems }),
    })
  } catch {
    return { ok: false, status: 'offline', message: 'Removing a photo needs the network.' }
  }

  if (response.ok) return { ok: true, ...(await response.json().catch(() => null)) }

  return { ok: false, status: 'failed', message: 'That photo could not be removed.' }
}

/**
 * Estimate the blanks on a meal that is already saved. A NEW key each time, as
 * when re-analysing a photo: "ask again" and "the same request arrived twice"
 * are different intentions and `vision_requests` has to tell them apart. Not
 * queued, deliberately — unlike the save it follows, an estimate is asked for
 * while looking at the meal, and one arriving tomorrow is not what the button
 * offered. The button says so when there is no connection.
 */
export function estimateMeal(uuid, idempotencyKey, items = null) {
  return postJson(`/api/meals/${uuid}/estimate`, {
    idempotency_key: idempotencyKey,

    /*
     * What the review sheet is holding, when the ask comes from there. Without
     * it the endpoint rebuilds the question from the STORED items — the previous
     * answer — so a portion just corrected from 2 g to 250 g was re-asked as 2 g
     * and came back wrong again, looking like the app reverting the correction
     * on purpose. Omitted from "Save & estimate", which has just WRITTEN what it
     * knows: there the stored rows are the user's own words.
     */
    ...(items === null || items.length === 0 ? {} : { items }),
  }, COPY)
}

/**
 * One poll. Null on any failure: a poll that cannot answer is not news — the
 * caller keeps polling, and the page still shows the last thing that was true.
 */
export async function pollAnalysis(uuid) {
  try {
    const response = await fetch(`/api/meals/${uuid}/vision`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok) return null

    return await response.json()
  } catch {
    return null
  }
}
