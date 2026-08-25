import assert from 'node:assert/strict'
import test from 'node:test'

import { authHeaders, csrfToken, formHeaders, interpretResponse, jsonHeaders, postForm, postJson } from '../../resources/js/lib/http.js'

/**
 * resources/js/lib/http.js — the plumbing under every hand-rolled fetch.
 *
 * Five modules used to carry their own copy of this: the same cookie regex, the
 * same three headers, the same 401/419/429 ladder and the same two sentences.
 * The risk of one shared copy is the opposite of the risk of five — a knob
 * quietly changing a string, or a rung firing for an endpoint that never had it
 * — so what is pinned here is which sentence each status produces, and that the
 * per-endpoint copy is the CALLER'S, never this module's.
 *
 * Run by `npm test`, and by scripts/ci.sh alongside the PHP suite.
 */

/** The browser this module needs: a cookie jar and a fetch. Always put back. */
async function withBrowser({ cookie = 'XSRF-TOKEN=a%20token', fetch = null }, run) {
  const originalFetch = globalThis.fetch
  const originalDocument = globalThis.document

  globalThis.document = { cookie }
  if (fetch) globalThis.fetch = fetch

  try {
    await run()
  } finally {
    globalThis.fetch = originalFetch
    globalThis.document = originalDocument
  }
}

// --- the cookie -------------------------------------------------------------

test('the token is URL-decoded, because the cookie holds it encoded', async () => {
  await withBrowser({ cookie: 'XSRF-TOKEN=a%20token' }, () => {
    assert.equal(csrfToken(), 'a token')
  })
})

test('the token is found beside other cookies, at either end', async () => {
  await withBrowser({ cookie: 'other=1; XSRF-TOKEN=mid; last=2' }, () => assert.equal(csrfToken(), 'mid'))
  await withBrowser({ cookie: 'XSRF-TOKEN=first; other=1' }, () => assert.equal(csrfToken(), 'first'))
})

test('no cookie is an empty token rather than a throw', async () => {
  // The 419 that follows is a result the screen can read out; a ReferenceError
  // inside the caller's try/catch would come back as `offline` and lie.
  await withBrowser({ cookie: 'session=only' }, () => assert.equal(csrfToken(), ''))
})

test('a cookie whose name merely ends in the token name is not it', async () => {
  await withBrowser({ cookie: 'NOT-XSRF-TOKEN=wrong' }, () => assert.equal(csrfToken(), ''))
})

// --- the headers ------------------------------------------------------------

test('every request asks for JSON and says it is an XHR', async () => {
  await withBrowser({ cookie: 'XSRF-TOKEN=t' }, () => {
    // Both, or an expired session answers 302 to the login page and fetch
    // follows it into a 200 of HTML that `response.ok` calls a success.
    assert.deepEqual(authHeaders(), {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': 't',
    })
  })
})

test('a form body carries no content type, because the boundary is the browser to write', async () => {
  await withBrowser({ cookie: 'XSRF-TOKEN=t' }, () => {
    assert.equal(jsonHeaders()['Content-Type'], 'application/json')
    assert.equal('Content-Type' in formHeaders(), false)
  })
})

test('each call builds its own object, so a caller adding to it cannot poison the next', async () => {
  await withBrowser({ cookie: 'XSRF-TOKEN=t' }, () => {
    const first = authHeaders()

    first['Content-Type'] = 'application/json'

    assert.equal('Content-Type' in authHeaders(), false)
  })
})

// --- the ladder -------------------------------------------------------------

const COPY = { busy: 'Too many photos in a row. Wait a minute.', failed: 'The upload failed.' }

test('401 and 419 are told apart, and say different things', () => {
  const out = interpretResponse({ status: 401 }, null, COPY)
  const stale = interpretResponse({ status: 419 }, null, COPY)

  // Signing in fixes one; reloading fixes the other. Reversed, the app tells a
  // signed-in user to sign in and a signed-out one to wait.
  assert.deepEqual(out, { status: 'unauthenticated', message: 'Session expired. Reload the page and sign in.' })
  assert.deepEqual(stale, { status: 'expired', message: 'This page has been open a while. Reload and try again.' })
})

test('429 says what the caller told it to say', () => {
  assert.deepEqual(interpretResponse({ status: 429 }, null, COPY), {
    status: 'rate_limited',
    message: 'Too many photos in a row. Wait a minute.',
  })
})

test('the envelope wins by default, and the field sentence when asked', () => {
  const payload = { message: 'The given data was invalid.', errors: { from: ['A report can cover at most 92 days.'] } }

  assert.equal(interpretResponse({ status: 422 }, payload, COPY).message, 'The given data was invalid.')
  assert.equal(
    interpretResponse({ status: 422 }, payload, { ...COPY, fieldErrorFirst: true }).message,
    'A report can cover at most 92 days.',
  )
})

test('either order falls through to whichever candidate exists', () => {
  const fieldOnly = { errors: { photo: ['That file is not an image.'] } }
  const envelopeOnly = { message: 'Pick a range, or one of the presets.' }

  assert.equal(interpretResponse({ status: 422 }, fieldOnly, COPY).message, 'That file is not an image.')
  assert.equal(
    interpretResponse({ status: 422 }, envelopeOnly, { ...COPY, fieldErrorFirst: true }).message,
    'Pick a range, or one of the presets.',
  )
})

test('an unreadable body still produces the caller sentence, never undefined', () => {
  // `response.json()` on a 502 of HTML gives null, and a result whose message is
  // undefined draws an empty red box that says nothing at all.
  assert.deepEqual(interpretResponse({ status: 500 }, null, COPY), {
    status: 'failed',
    message: 'The upload failed.',
  })
})

test('a status the server named is kept, because the screen switches on it', () => {
  const result = interpretResponse({ status: 422 }, { status: 'invalid_range', message: 'Pick a range.' }, COPY)

  assert.equal(result.status, 'invalid_range')
})

// --- the copy contract, enforced --------------------------------------------

test('interpretResponse refuses to run without the caller sentences', () => {
  // Unenforced, a forgotten `failed` reaches the user as an empty red box.
  assert.throws(() => interpretResponse({ status: 429 }, null, {}), TypeError)
  assert.throws(() => interpretResponse({ status: 500 }, null, { busy: 'Wait.' }), TypeError)
  assert.throws(() => interpretResponse({ status: 500 }, null, { failed: 'It failed.' }), TypeError)
})

// --- the whole call ---------------------------------------------------------

function response(body, status = 200) {
  return { ok: status >= 200 && status < 300, status, json: async () => body }
}

test('a success hands back the body, flagged', async () => {
  await withBrowser({ fetch: async () => response({ id: 3, status: 'queued' }) }, async () => {
    assert.deepEqual(await postJson('/api/reports', { days: 7 }, { offline: 'x', ...COPY }), {
      ok: true,
      id: 3,
      status: 'queued',
    })
  })
})

test('no network at all is its own status, not a refusal', async () => {
  const fetch = async () => {
    throw new TypeError('Failed to fetch')
  }

  await withBrowser({ fetch }, async () => {
    const result = await postJson('/api/reports', {}, { offline: 'No connection. Writing a report needs the network.', ...COPY })

    // "The server said no" and "there was no server" are different screens.
    assert.deepEqual(result, { ok: false, status: 'offline', message: 'No connection. Writing a report needs the network.' })
  })
})

test('a body that cannot be serialised is a result, not a throw out of the caller', async () => {
  // The serialisation sits inside the ladder's own try, where each module had
  // it. A throw out of the middle of a call is the one shape no screen reads.
  const circular = {}
  circular.self = circular

  await withBrowser({ fetch: async () => response({}) }, async () => {
    const result = await postJson('/api/reports', circular, { offline: 'No connection.', ...COPY })

    assert.deepEqual(result, { ok: false, status: 'offline', message: 'No connection.' })
  })
})

test('no cookie jar at all is offline rather than a ReferenceError', async () => {
  const originalFetch = globalThis.fetch
  const had = 'document' in globalThis
  const originalDocument = globalThis.document

  delete globalThis.document
  globalThis.fetch = async () => response({})

  try {
    // Reading `document.cookie` where there is no document must land in the
    // same catch: a green-looking `offline` is at least a screen, and the
    // alternative is an unhandled rejection inside a click handler.
    const result = await postJson('/api/reports', {}, { offline: 'No connection.', ...COPY })

    assert.equal(result.status, 'offline')
  } finally {
    globalThis.fetch = originalFetch
    if (had) globalThis.document = originalDocument
  }
})

test('an extra code is consulted before the ladder and can refuse to be an error', async () => {
  await withBrowser({ fetch: async () => response({ message: 'Already running.', report: { id: 9 } }, 409) }, async () => {
    const result = await postJson('/api/reports', {}, {
      offline: 'x',
      ...COPY,
      extra: { 409: (payload) => ({ status: 'already_generating', report: payload?.report }) },
    })

    assert.deepEqual(result, { ok: false, status: 'already_generating', report: { id: 9 } })
  })
})

test('postJson throws before it fetches, so the mistake is not served as offline', async () => {
  let called = false
  const fetch = async () => {
    called = true

    return response({})
  }

  await withBrowser({ fetch }, async () => {
    await assert.rejects(() => postJson('/api/reports', {}, { offline: 'No connection.' }), TypeError)
  })

  // The check sits OUTSIDE the try on purpose: inside it, a programmer error
  // would come back as a confident "no connection" that is simply untrue.
  assert.equal(called, false)
})

test('postForm throws before it fetches too', async () => {
  let called = false
  const fetch = async () => {
    called = true

    return response({})
  }

  await withBrowser({ fetch }, async () => {
    await assert.rejects(
      () => postForm('/api/supplements/label', { append: () => {} }, { offline: 'x', busy: 'Wait.' }),
      TypeError,
    )
  })

  assert.equal(called, false)
})

test('a form post sends the form itself and lets the browser type it', async () => {
  const seen = {}
  const form = { append: () => {} }

  await withBrowser({
    cookie: 'XSRF-TOKEN=t',
    fetch: async (url, init) => {
      Object.assign(seen, { url, init })

      return response({ ok: true })
    },
  }, async () => {
    await postForm('/api/supplements/label', form, { offline: 'x', ...COPY })
  })

  assert.equal(seen.url, '/api/supplements/label')
  assert.equal(seen.init.method, 'POST')
  assert.equal(seen.init.body, form)
  assert.equal(seen.init.credentials, 'same-origin')
  assert.equal('Content-Type' in seen.init.headers, false)
})

test('a JSON post serialises the body and names the type', async () => {
  const seen = {}

  await withBrowser({
    cookie: 'XSRF-TOKEN=t',
    fetch: async (url, init) => {
      Object.assign(seen, { init })

      return response({})
    },
  }, async () => {
    await postJson('/api/reports', { days: 7 }, { offline: 'x', ...COPY })
  })

  assert.equal(seen.init.body, '{"days":7}')
  assert.equal(seen.init.headers['Content-Type'], 'application/json')
  assert.equal(seen.init.headers['X-XSRF-TOKEN'], 't')
})
