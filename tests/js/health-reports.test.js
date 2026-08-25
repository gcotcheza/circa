import assert from 'node:assert/strict'
import test from 'node:test'

import { focusLine, pollReport, shortRange, spanLabel, startReport, watchReport } from '../../resources/js/lib/health-reports.js'

/**
 * The polling loop, and the one line in it that matters most. Tested in
 * JavaScript because this half runs entirely in the browser and leaves no
 * server-side symptom: a report that generated, was paid for, and never appeared
 * because a dropped poll was read as a failure is a 200 in every log. The
 * behaviour under test is that a NETWORK FAILURE IS NOT A REPORT FAILURE — the
 * row on the server is the truth and the next poll will find it; backwards, it
 * takes a healthy generation off screen at random.
 */

/** Swap global fetch for one test, and always put it back. `document` is stubbed
 * too because startReport reads the XSRF cookie off it: without it the
 * ReferenceError is caught by the module's own try/catch and comes back as
 * `offline` — a green-looking test that proves nothing. */
async function withFetch(impl, run) {
  const originalFetch = globalThis.fetch
  const originalDocument = globalThis.document

  globalThis.fetch = impl
  globalThis.document = { cookie: 'XSRF-TOKEN=test-token' }

  try {
    await run()
  } finally {
    globalThis.fetch = originalFetch
    globalThis.document = originalDocument
  }
}

function json(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  }
}

test('a dropped poll is pending rather than failed', async () => {
  await withFetch(
    async () => {
      throw new TypeError('Failed to fetch')
    },
    async () => {
      const state = await pollReport(7)

      assert.equal(state.pending, true)
      assert.equal(state.unreachable, true)
    },
  )
})

test('a non-2xx poll is also pending rather than failed', async () => {
  // A 502 from a proxy mid-deploy says nothing about the report.
  await withFetch(async () => json({}, 502), async () => {
    const state = await pollReport(7)

    assert.equal(state.pending, true)
  })
})

test('watching stops as soon as the report is no longer pending', async () => {
  let calls = 0

  await withFetch(
    async () => {
      calls += 1

      return json({ pending: calls < 3, report: { id: 7, status: calls < 3 ? 'generating' : 'ready' } })
    },
    async () => {
      const seen = []

      const state = await watchReport(7, { onState: (s) => seen.push(s.report.status), intervalMs: 1 })

      assert.equal(calls, 3)
      assert.equal(state.report.status, 'ready')
      assert.deepEqual(seen, ['generating', 'generating', 'ready'])
    },
  )
})

test('a report that finished while the page was closed is found on the first poll', async () => {
  let calls = 0

  await withFetch(
    async () => {
      calls += 1

      return json({ pending: false, report: { id: 7, status: 'ready' } })
    },
    async () => {
      // No initial wait: a finished row must not cost an interval of spinner.
      const state = await watchReport(7, { intervalMs: 60_000 })

      assert.equal(calls, 1)
      assert.equal(state.report.status, 'ready')
    },
  )
})

test('watching gives up at the ceiling rather than polling forever', async () => {
  await withFetch(async () => json({ pending: true, report: { id: 7, status: 'generating' } }), async () => {
    const state = await watchReport(7, { intervalMs: 1, maxMs: 5 })

    assert.equal(state.timedOut, true)
  })
})

test('a 409 is reported as already_generating and carries the running report', async () => {
  await withFetch(
    async () => json({ status: 'already_generating', message: 'A report is already being written.', report: { id: 3 } }, 409),
    async () => {
      const result = await startReport({ days: 7 })

      // NOT ok, and not a generic failure: the page follows the report the
      // server named rather than showing a red box about an invisible race.
      assert.equal(result.ok, false)
      assert.equal(result.status, 'already_generating')
      assert.equal(result.report.id, 3)
    },
  )
})

test('a validation error surfaces the field message rather than a generic one', async () => {
  await withFetch(
    async () => json({ message: 'The given data was invalid.', errors: { from: ['A report can cover at most 92 days.'] } }, 422),
    async () => {
      const result = await startReport({ from: '2020-01-01', to: '2026-08-10' })

      assert.equal(result.ok, false)
      // The range rule's own sentence, not Laravel's envelope — why legality is
      // decided by constructing a ReportRange rather than by a second rule set.
      assert.equal(result.message, 'A report can cover at most 92 days.')
    },
  )
})

test('a controller error with no field errors still reads out', async () => {
  await withFetch(async () => json({ status: 'invalid_range', message: 'Pick a range, or one of the presets.' }, 422), async () => {
    const result = await startReport({})

    assert.equal(result.message, 'Pick a range, or one of the presets.')
  })
})

test('an offline start says so rather than looking like a server refusal', async () => {
  await withFetch(
    async () => {
      throw new TypeError('Failed to fetch')
    },
    async () => {
      const result = await startReport({ days: 7 })

      assert.equal(result.ok, false)
      assert.equal(result.status, 'offline')
    },
  )
})

test('a preset range reads as a human date range', () => {
  assert.equal(shortRange('2026-08-03', '2026-08-09'), '3–9 Aug')
  assert.equal(shortRange('2026-07-28', '2026-08-10'), '28 Jul – 10 Aug')
  assert.equal(shortRange('2026-08-10', '2026-08-10'), '10 Aug')
})

/* THE ARCHIVE LINE. A focused report's headline may say nothing about most of
 * what the app measures, so "why does this one only talk about stress" has to be
 * answerable without opening it. The span is shorthand because the row already
 * carries an exact range label, and two exact figures per glance is unscannable. */
test('a span reads as somebody would say it', () => {
  assert.equal(spanLabel(7), '7d')
  assert.equal(spanLabel(13), '13d')
  assert.equal(spanLabel(14), '2w')
  assert.equal(spanLabel(30), '1m')
  assert.equal(spanLabel(182), '6m')
  assert.equal(spanLabel(365), '1y')
  assert.equal(spanLabel(null), null)
})

test('a focused report says what it was about and how far back', () => {
  assert.equal(focusLine({ focusLabel: 'Stress + Sleep', days: 182 }), 'Stress + Sleep · 6m')
  assert.equal(focusLine({ focusLabel: 'Food', days: 7 }), 'Food · 7d')
})

/* Null rather than "Everything": most rows have no focus, and a column reading
 * "Everything · 1w" on twenty consecutive rows is exactly the noise the status
 * dot was designed around. */
test('an unfocused report gets no badge at all', () => {
  assert.equal(focusLine({ focusLabel: null, days: 7 }), null)
  assert.equal(focusLine({ days: 7 }), null)
  assert.equal(focusLine(null), null)
})
