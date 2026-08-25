/**
 * Starting a health report, and watching it happen.
 *
 * NOT lib/report.js, the client-side CRASH reporter. The two were briefly
 * `report.js` and `reports.js` — one character between "tell the server the
 * browser broke" and "ask the server to write a health report" — so this one
 * carries the longer name. Same discriminated-result shape as lib/vision.js and
 * lib/products.js: every outcome is a different thing on screen with a different
 * next step, which a rejected promise would flatten into "something went wrong".
 */

import { postJson } from './http.js'

/** Ask for a report. */
export function startReport(body) {
  return postJson('/api/reports', body, {
    offline: 'No connection. Writing a report needs the network.',
    busy: 'Too many reports in a row. Wait a minute.',
    failed: 'The report could not be started.',

    /*
     * THE FIELD ERROR WINS OVER THE ENVELOPE — the opposite order to
     * lib/vision.js, deliberately. A 422 from the form request carries BOTH a
     * generic `message` ("The given data was invalid.") and `errors.from[0]`,
     * ReportRange's own sentence: "A report can cover at most 92 days." Range
     * legality is decided by constructing a ReportRange precisely so that
     * sentence reaches the user. The controller's own 422s carry `message` and
     * no `errors`, so they fall through to the envelope unchanged.
     */
    fieldErrorFirst: true,

    extra: {
      /*
       * A 409 is NOT an error and gets its own result status: the server means
       * "one is already in flight, here it is", so the screen follows that one
       * instead of showing a red box. That is the case a second tab produces,
       * and the reason the guard lives on the server rather than in a disabled
       * button.
       */
      409: (payload) => ({ status: 'already_generating', message: payload?.message, report: payload?.report }),
    },
  })
}

/**
 * One poll. A network failure comes back as `pending: true`, not as an error: a
 * dropped poll is no evidence that anything went wrong, and treating it as one
 * would take a healthy generation off screen. The row on the server is the
 * truth, and the next poll will find it.
 */
export async function pollReport(id) {
  try {
    const response = await fetch(`/api/reports/${id}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })

    if (!response.ok) return { pending: true, unreachable: true }

    return await response.json()
  } catch {
    return { pending: true, unreachable: true }
  }
}

/**
 * Poll until the report stops moving, then hand back the last state.
 *
 * A FIXED INTERVAL, NOT A BACKOFF: the expected wait is thirty to ninety seconds
 * and the user is watching, so a backoff would spend the back half of it not
 * asking and a report that finished at 40 s would appear at 64 s for no visible
 * reason. Four seconds is quiet enough on a phone and never leaves a finished
 * report unnoticed for longer than one of them. The ceiling is a real bound:
 * `GenerateHealthReport` has a 900 s queue timeout, past which its `failed()`
 * hook writes a terminal state, so polling on would poll a settled row.
 */
export async function watchReport(id, { onState, intervalMs = 4000, maxMs = 900_000 } = {}) {
  const startedAt = Date.now()

  // One poll before the first wait: a reload can land here on a row that
  // completed while the page was closed, and a spinner nobody needed.
  for (;;) {
    const state = await pollReport(id)

    onState?.(state)

    if (!state.pending) return state

    if (Date.now() - startedAt > maxMs) return { ...state, timedOut: true }

    await new Promise((resolve) => setTimeout(resolve, intervalMs))
  }
}

/**
 * "3–9 Aug" for a preset chip, from two ISO dates. The server already sends a
 * formatted `rangeLabel` for every stored report; this is only for the chips,
 * whose ranges are computed rather than stored.
 */
export function shortRange(start, end) {
  const from = new Date(`${start}T00:00:00`)
  const to = new Date(`${end}T00:00:00`)

  const day = (d) => d.toLocaleDateString('en-GB', { day: 'numeric' })
  const dayMonth = (d) => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })

  if (start === end) return dayMonth(to)

  return from.getMonth() === to.getMonth() ? `${day(from)}–${dayMonth(to)}` : `${dayMonth(from)} – ${dayMonth(to)}`
}

/**
 * "6m" / "2w" / "7d" — a span said the way somebody would say it. ONLY for the
 * archive line, a list of thirty rows where "182 days" next to "Stress + Sleep"
 * is two numbers competing for the same glance; the report's own header keeps
 * the exact figure and the exact dates.
 */
export function spanLabel(days) {
  if (days === null || days === undefined) return null

  if (days >= 330) return `${Math.round(days / 365)}y`
  if (days >= 28) return `${Math.round(days / 30)}m`
  if (days >= 14) return `${Math.round(days / 7)}w`

  return `${days}d`
}

/**
 * "Stress + Sleep · 6m", or null for an ordinary full report. NULL RATHER THAN
 * "Everything", deliberately: most rows have no focus — every weekly one, every
 * manual one from before the chips — and "Everything · 1w" on twenty consecutive
 * rows is the badge-reading-"ready" noise the status dot was designed around.
 */
export function focusLine(report) {
  if (!report?.focusLabel) return null

  const span = spanLabel(report.days)

  return span ? `${report.focusLabel} · ${span}` : report.focusLabel
}

/** "48s", for the provenance line. */
export function seconds(ms) {
  if (ms === null || ms === undefined) return null

  return `${(ms / 1000).toFixed(ms < 10_000 ? 1 : 0)}s`
}
