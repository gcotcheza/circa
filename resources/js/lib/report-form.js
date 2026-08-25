/**
 * The decisions behind the Generate control, as pure functions.
 *
 * WHY THESE LIVE HERE RATHER THAN INLINE IN ReportForm.vue — the same reason
 * lib/air-date.js exists next to AirDate.vue: this is the half of the form that
 * has a right and a wrong answer, and none of it has a server-side symptom when
 * it goes wrong. A range this screen let through that the validator would refuse
 * is a red box the user did nothing to earn; a payload that dropped the
 * free-text instruction is a report that quietly ignored what it was asked. So
 * the rules sit where `node --test` can hold them down.
 *
 * THE CEILINGS ARE THE SERVER'S NUMBERS, NOT THIS FILE'S: `maxRangeDays.full`
 * and `.lean` arrive from ReportFocus::maxRangeDays(), and hardcoding 92 and 366
 * here is how the screen and the validator end up disagreeing about a range.
 * This module decides WHICH of the two numbers applies; it never invents one.
 */

/**
 * The ceiling this form is working under. The Food chip is the whole story: its
 * block is one entry per item eaten rather than one row per day, so it is the
 * only selection that keeps the ordinary quarter — everything else, in any
 * combination, is linear in days and may run to a year. Mirrors
 * ReportFocus::maxRangeDays().
 */
export function ceilingDays(focusAreas, maxRangeDays) {
  const foodSelected = focusAreas.includes('food')

  return focusAreas.length && !foodSelected ? maxRangeDays.lean : maxRangeDays.full
}

/**
 * Why a range of `days` cannot be generated under this focus, in words that say
 * what to do about it — or null when it can. Null is also what enables the
 * range: one computed answer for "can this be chosen" and "what does the label
 * say", because two would eventually disagree.
 */
export function blockedReason(days, focusAreas, ceiling) {
  if (days <= ceiling) return null

  return focusAreas.includes('food')
    ? 'Not with Food — the food log is too big to cover this long'
    : 'Pick a focus first'
}

/**
 * The inclusive day count of a custom From/To pair, or null when it is not yet a
 * range. The dates come from AirDate, which only ever emits a real calendar day,
 * so the arithmetic is the whole job; a live count under the pickers is what
 * stops a range whose length only appears after a failed submit.
 */
export function customDays(from, to) {
  if (!from || !to) return null

  const start = new Date(`${from}T00:00:00`)
  const end = new Date(`${to}T00:00:00`)

  if (Number.isNaN(start) || Number.isNaN(end) || start > end) return null

  return Math.round((end - start) / 86_400_000) + 1
}

/**
 * The request body: a range, with the focus and the free-text instruction folded
 * in when present. THE THREE ARE INDEPENDENT — a range alone is the ordinary
 * full report, a range with only an instruction is a full report steered by a
 * sentence, a range with only chips is a focused one — which is why each is
 * added on its own truthiness rather than as a group.
 */
export function payload(range, focusAreas, focusText) {
  const body = { ...range }

  if (focusAreas.length) body.focus = focusAreas

  const trimmed = (focusText ?? '').trim()

  if (trimmed) body.focusText = trimmed

  return body
}

/** A selection is custom when it carries dates rather than a preset day count. */
function isCustomRange(range) {
  return !('days' in range)
}

/**
 * Can this selection be generated under this focus? The single guard behind the
 * Generate button — ONE RULE, so the disabled state and any test of it read the
 * same answer rather than two copies that drift. Custom needs a start and a pair
 * that resolves, and every range must fit under the focus ceiling; the range
 * this returns false for is the one ReportRange would refuse to construct.
 *
 * NOTHING SELECTED IS FALSE TOO: on load the preset selection is
 * `{ days: null }` and `range.days != null` refuses it, keeping Generate
 * disabled until the user deliberately taps a range, because an expensive model
 * call must never be one accidental Generate tap away.
 */
export function canSubmit(range, focusAreas, maxRangeDays) {
  const ceiling = ceilingDays(focusAreas, maxRangeDays)

  if (isCustomRange(range)) {
    const days = customDays(range.from, range.to)

    return Boolean(range.from) && days !== null && days <= ceiling
  }

  return range.days != null && range.days <= ceiling
}
