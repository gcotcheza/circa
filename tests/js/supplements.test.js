import assert from 'node:assert/strict'
import test from 'node:test'

/**
 * "Take all" — resources/js/lib/supplements.js.
 *
 * The PHP suite pins what the SERVER does with the four requests this button
 * sends (Tests\Feature\Supplements\TakeAllTest); it cannot see the decision that
 * produces them, which is where the interesting failures are. Two are pinned
 * rather than described:
 *
 *   IT LOOPS THE PER-BOTTLE WRITE. Every request carries the same URL, body and
 *   offline-queue action id a single tap on that row would have, so "take all,
 *   then un-tick one with no radio" replaces a queued statement instead of
 *   stacking one behind it. A bulk endpoint would re-tick the bottle the user
 *   just said no to. The test is the shape of the wire traffic.
 *
 *   A REFUSAL IS NAMED, because a result that did not say WHICH bottle failed
 *   leaves the card either un-ticking three that were recorded or leaving one
 *   ticked that was not.
 */

/*
 * THE IMPORT COMES FIRST, AND THE BROWSER GLOBALS COME AFTER IT.
 *
 * `lib/supplements.js` pulls in `lib/queue.js`, hence vue and @inertiajs/core,
 * which decide at IMPORT TIME whether they are in a browser (`typeof window !==
 * 'undefined'`) and then reach for `window.history.scrollRestoration`,
 * `window.navigator.userAgent` and `document.createElement`. Declaring the
 * globals first makes the import FAIL: a half-built window is worse than none.
 *
 * With them absent both libraries take their server branch. The queue's own uses
 * of `document.cookie` and `window.location.origin` are at SEND time, so the
 * stubs below are in time for every assertion — and without them the
 * ReferenceError lands inside the queue's try/catch and comes back as `offline`,
 * a green-looking test that proves nothing.
 */
const { takeAllSupplements } = await import('../../resources/js/lib/supplements.js')

globalThis.window = { location: { origin: 'https://health.test' } }
globalThis.document = { cookie: 'XSRF-TOKEN=test-token' }

/** Swap global fetch for the duration of one test, and always put it back. */
async function withFetch(impl, run) {
  const original = globalThis.fetch
  const calls = []

  globalThis.fetch = (url, init) => {
    calls.push({ url, method: init.method, body: JSON.parse(init.body) })

    return impl(url, init, calls.length - 1)
  }

  try {
    await run(calls)
  } finally {
    globalThis.fetch = original
  }
}

function ok(url) {
  return Promise.resolve({ ok: true, status: 200, url, json: async () => ({}) })
}

function shelf() {
  return [
    { id: 1, name: 'Magnesium', taken: false },
    { id: 2, name: 'Vitamin D', taken: false },
    { id: 3, name: 'Omega 3', taken: false },
    { id: 4, name: 'Zinc', taken: false },
  ]
}

test('one press writes every bottle on the shelf, one request each', async () => {
  await withFetch(
    (url) => ok(url),
    async (calls) => {
      const results = await takeAllSupplements(shelf(), '2026-08-10')

      assert.equal(calls.length, 4)
      assert.deepEqual(
        calls.map((call) => call.url),
        [
          '/api/supplements/1/intake',
          '/api/supplements/2/intake',
          '/api/supplements/3/intake',
          '/api/supplements/4/intake',
        ]
      )

      // The same verb and complete-desired-state body a single tap sends, which
      // is what makes the whole press replay-safe.
      for (const call of calls) {
        assert.equal(call.method, 'PUT')
        assert.deepEqual(call.body, { date: '2026-08-10', taken: true })
      }

      assert.equal(results.length, 4)
      assert.ok(results.every((result) => result.ok))
    }
  )
})

/** The date on screen, not today — the card is per-date and so is this. */
test('a past day is written as the day being looked at', async () => {
  await withFetch(
    (url) => ok(url),
    async (calls) => {
      await takeAllSupplements([{ id: 7, taken: false }], '2026-07-04')

      assert.deepEqual(calls[0].body, { date: '2026-07-04', taken: true })
    }
  )
})

/** The three already ticked are not "written again to be safe": a request that
 * changes nothing is still a request, four times an evening. */
test('bottles that are already ticked are left alone', async () => {
  await withFetch(
    (url) => ok(url),
    async (calls) => {
      const items = shelf()

      items[0].taken = true
      items[1].taken = true
      items[3].taken = true

      const results = await takeAllSupplements(items, '2026-08-10')

      assert.deepEqual(
        calls.map((call) => call.url),
        ['/api/supplements/3/intake']
      )
      assert.deepEqual(
        results.map((result) => result.id),
        [3]
      )
    }
  )
})

/** The re-fire. The endpoint would absorb the replay anyway; saying nothing is
 * the cheaper half of the same guarantee. */
test('pressing it again on a finished shelf sends nothing', async () => {
  await withFetch(
    (url) => ok(url),
    async (calls) => {
      const results = await takeAllSupplements(
        shelf().map((item) => ({ ...item, taken: true })),
        '2026-08-10'
      )

      assert.equal(calls.length, 0)
      assert.deepEqual(results, [])
    }
  )
})

test('an empty shelf writes nothing and does not throw', async () => {
  await withFetch(
    (url) => ok(url),
    async (calls) => {
      assert.deepEqual(await takeAllSupplements([], '2026-08-10'), [])
      assert.deepEqual(await takeAllSupplements(undefined, '2026-08-10'), [])
      assert.equal(calls.length, 0)
    }
  )
})

/**
 * One refusal must not cost the other three. The card reads this list to decide
 * which ticks to put back, so the failure has to arrive attached to its bottle.
 */
test('a refused bottle comes back named, and the rest still land', async () => {
  await withFetch(
    (url, init, index) => {
      if (index !== 2) return ok(url)

      return Promise.resolve({
        ok: false,
        status: 422,
        url,
        json: async () => ({ errors: { date: ['The date field is required.'] } }),
      })
    },
    async (calls) => {
      const results = await takeAllSupplements(shelf(), '2026-08-10')

      assert.equal(calls.length, 4)

      assert.deepEqual(
        results.map((result) => [result.id, result.ok]),
        [
          [1, true],
          [2, true],
          [3, false],
          [4, true],
        ]
      )

      const refused = results.find((result) => !result.ok)

      assert.equal(refused.outcome, 'invalid')
      assert.equal(refused.message, 'The date field is required.')
    }
  )
})

/**
 * Sequential, deliberately.
 *
 * The queue's own flush is sequential, and its offline path checks the
 * MAX_ACTIONS ceiling with a read-then-write that is not atomic — four parallel
 * writes could read the same count and step over it together. Nobody is waiting
 * on the network here: the card drew all four ticks before the first request
 * left.
 */
test('the writes go one at a time', async () => {
  let inFlight = 0
  let peak = 0

  await withFetch(
    (url) => {
      inFlight++
      peak = Math.max(peak, inFlight)

      return new Promise((resolve) => {
        setTimeout(() => {
          inFlight--
          resolve({ ok: true, status: 200, url, json: async () => ({}) })
        }, 1)
      })
    },
    async () => {
      await takeAllSupplements(shelf(), '2026-08-10')

      assert.equal(peak, 1)
    }
  )
})
