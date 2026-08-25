/**
 * The barcode -> product call, and the per-100 g arithmetic behind a portion.
 *
 * ITS LADDER IS DELIBERATELY NOT lib/http.js's, though it looks like one. This
 * is a GET, it carries no `ok` flag, it has no 419 branch — there is no token to
 * go stale on a read — it never reads a field-errors bag, and its last resort is
 * `unavailable` rather than `failed`. That is five overrides to share one rung,
 * so it keeps its own. What IS shared is the sentence a signed-out user reads,
 * because that one is the same statement here as everywhere else and two copies
 * of it can only drift.
 */

import { SIGNED_OUT } from './http.js'

/**
 * Look a barcode up. A discriminated result rather than a throw: each outcome
 * is a different screen and a different thing for the user to do next, which a
 * rejected promise would flatten back into one.
 *
 * @returns {Promise<{status: string, source?: string, product?: object, message?: string}>}
 */
export async function lookupProduct(barcode, { refresh = false } = {}) {
  const query = refresh ? '?refresh=1' : ''

  let response

  try {
    response = await fetch(`/api/products/${encodeURIComponent(barcode)}${query}`, {
      headers: { Accept: 'application/json' },
      // Session-authenticated; without the cookie it is a 401.
      credentials: 'same-origin',
    })
  } catch {
    // Offline or cut off — not the same as "OFF is down".
    return { status: 'offline', message: 'No connection. The lookup needs the network.' }
  }

  const body = await response.json().catch(() => null)

  if (response.ok && body?.status === 'found') return body

  if (response.status === 401) {
    return { status: 'unauthenticated', message: SIGNED_OUT }
  }

  if (response.status === 429) {
    return { status: 'rate_limited', message: 'Too many lookups in a row. Wait a few seconds.' }
  }

  return {
    status: body?.status ?? 'unavailable',
    message: body?.message ?? 'The lookup failed.',
  }
}

/**
 * A per-100 g density scaled to a portion, or null if there is no density.
 *
 * null is not zero: Open Food Facts routinely omits a macro, and "0 g of
 * protein" for "nobody filled this in" is a lie the daily total would inherit.
 */
export function forPortion(per100g, grams) {
  if (per100g === null || per100g === undefined || !Number.isFinite(Number(grams))) return null

  return (Number(per100g) * Number(grams)) / 100
}
