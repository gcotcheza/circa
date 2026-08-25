/**
 * The sleep card's one sentence about a night that has not all arrived.
 *
 * A module, not a computed, because the sentence is the claim: its quantity has
 * to be the one the server measured. An earlier version used the session's own
 * total, which on a complete night with a mid-sleep gap refuted itself. Here it
 * can be checked without a browser.
 */

import { duration } from './format.js'

/**
 * "About 8h 26m of this night has not arrived yet — a late sync usually fills
 * it in", or null when the server sent nothing to say.
 *
 * `missingMinutes` is the part of the night with neither a watch reading nor a
 * sleep session on it (App\Services\Reporting\NightCoverage) — the evidence
 * itself, and no sentence at all when it is not a usable quantity.
 */
export function arrivalNote(sleep) {
  const minutes = sleep?.fragment?.missingMinutes

  if (typeof minutes !== 'number' || !Number.isFinite(minutes) || minutes <= 0) {
    return null
  }

  return `About ${duration(minutes)} of this night has not arrived yet — a late sync usually fills it in`
}
