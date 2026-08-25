import assert from 'node:assert/strict'
import test from 'node:test'

/**
 * resources/js/lib/report.js — the crash reporter, on the values that broke it.
 *
 * Three rows in `client_errors` said `undefined is not an object (evaluating
 * 'e.toString')` and the first suspect was this module: a serialiser calling
 * `.toString()` on an `unhandledrejection` whose `reason` is `undefined` throws
 * exactly that, recording the reporter's own TypeError with the real fault
 * erased. It was not — the stack points into @inertiajs/core — but "we read the
 * code and it looked fine" is not knowing, and not worth re-deriving at 9am. So
 * every value a browser can plausibly hand this module gets a test: a row lands,
 * it says something true, and nothing throws. Run by `npm test`, and by
 * scripts/ci.sh alongside the PHP suite.
 */

let instance = 0

/** A fresh copy of the module with the browser stubbed around it. Cache-busted
 * per test because the module's state — five reports per page load, the
 * fingerprints already seen — is what several of these tests are about, and it
 * is per page load, i.e. per module instance. */
async function reporter({ href = 'https://health.test/?date=2026-08-09' } = {}) {
  const posted = []

  globalThis.window = { location: { href }, addEventListener() {} }

  globalThis.document = {
    cookie: 'XSRF-TOKEN=token-value',
    querySelector: () => ({ content: 'af0d37859d98' }),
    addEventListener() {},
  }

  globalThis.fetch = (url, init) => {
    posted.push({ url, body: JSON.parse(init.body), init })

    return Promise.resolve({ ok: true })
  }

  const module = await import(`../../resources/js/lib/report.js?instance=${instance++}`)

  return { ...module, posted }
}

test('a rejection with no reason at all is described, not dereferenced', async () => {
  const { reportClientError, posted } = await reporter()

  // `Promise.reject()` — the shape that makes a naive serialiser throw the very
  // message this investigation started from.
  reportClientError('rejection', undefined)

  assert.equal(posted.length, 1)
  assert.equal(posted[0].body.message, '<undefined>')
  assert.equal(posted[0].body.stack, null)
  assert.equal(JSON.parse(posted[0].body.context).value, 'undefined')
})

test('a rejection with null is not the string "null"', async () => {
  const { reportClientError, posted } = await reporter()

  reportClientError('rejection', null)

  assert.equal(posted[0].body.message, '<null>')
  assert.equal(JSON.parse(posted[0].body.context).value, 'null')
})

test('an Error carries its type in the message and its stack in the stack', async () => {
  const { reportClientError, posted } = await reporter()

  const error = new TypeError("undefined is not an object (evaluating 'e.toString')")
  error.stack = 'rb@https://health.test/build/assets/app-BozACjIO.js:15:16699'

  reportClientError('rejection', error)

  /* The prefix is not cosmetic: WebKit's `stack` starts at the throwing frame
   * and never repeats the message, so without it the row cannot tell a
   * TypeError from a NetworkError — a bug in the code from one in the weather. */
  assert.equal(
    posted[0].body.message,
    "TypeError: undefined is not an object (evaluating 'e.toString')",
  )
  assert.match(posted[0].body.stack, /app-BozACjIO/)
  assert.equal(JSON.parse(posted[0].body.context).value, 'TypeError')
})

test('an Error from another realm keeps its message instead of becoming {}', async () => {
  const { reportClientError, posted } = await reporter()

  /* THE REGRESSION THIS PROTECTS. An Error thrown across a realm boundary — an
   * iframe, a worker, WebKit's own internals — fails `instanceof Error` because
   * its prototype belongs to the other realm. The previous version fell through
   * to `JSON.stringify`, where `message` and `stack` are non-enumerable, so the
   * most useful value in the system was recorded as two braces. */
  const alien = Object.create(null)
  alien.name = 'NetworkError'
  alien.message = 'The network connection was lost.'
  alien.stack = 'load@app.js:1:1'

  assert.equal(alien instanceof Error, false)

  reportClientError('rejection', alien)

  assert.equal(posted[0].body.message, 'NetworkError: The network connection was lost.')
  assert.equal(posted[0].body.stack, 'load@app.js:1:1')
})

test('a cause is carried, one level deep', async () => {
  const { reportClientError, posted } = await reporter()

  reportClientError('vue', new Error('render failed', { cause: new Error('no summary') }))

  assert.equal(posted[0].body.message, 'Error: render failed (cause: no summary)')
})

test('a circular object is reported rather than thrown over', async () => {
  const { reportClientError, posted } = await reporter()

  const circular = { a: 1 }
  circular.self = circular

  reportClientError('rejection', circular)

  assert.equal(posted.length, 1)
  assert.equal(posted[0].body.message, '[object Object]')
})

test('a value with no toString of its own does not take the reporter with it', async () => {
  const { reportClientError, posted } = await reporter()

  // `String(value)` throws on this; the module asks the language, not the value.
  reportClientError('rejection', Object.create(null))

  assert.equal(posted.length, 1)
  assert.equal(posted[0].body.message, '[object Object]')
})

test('a thrown string is kept verbatim, and an empty one is named', async () => {
  const { reportClientError, posted } = await reporter()

  reportClientError('error', 'boom')
  reportClientError('error', '')

  assert.equal(posted[0].body.message, 'boom')
  assert.equal(posted[1].body.message, '<empty string>')
})

test('the report says which build, which page and which component', async () => {
  const { reportClientError, setPageComponent, posted } = await reporter()

  setPageComponent('Day')

  reportClientError('inertia', new Error('nope'), {
    context: { at: 'inertia-response', status: 200, bytes: 0 },
  })

  const body = posted[0].body

  assert.equal(body.build, 'af0d37859d98')
  assert.equal(body.url, 'https://health.test/?date=2026-08-09')
  assert.equal(body.component, 'Day')
  assert.deepEqual(JSON.parse(body.context), {
    at: 'inertia-response',
    status: 200,
    bytes: 0,
    value: 'Error',
  })
})

test('two different faults with the same sentence are two reports', async () => {
  const { reportClientError, posted } = await reporter()

  const first = new TypeError('undefined is not an object')
  first.stack = 'rb@app.js:15:16699'

  const second = new TypeError('undefined is not an object')
  second.stack = 'qz@app.js:41:220'

  reportClientError('rejection', first)
  reportClientError('rejection', second)

  // On a minified build this message belongs to a dozen unrelated bugs, so
  // folding by message alone hides a new one behind an old one.
  assert.equal(posted.length, 2)

  reportClientError('rejection', first)

  assert.equal(posted.length, 2, 'an exact repeat is still free')
})

test('a crash loop costs five requests and then nothing', async () => {
  const { reportClientError, posted } = await reporter()

  for (let i = 0; i < 60; i++) {
    reportClientError('vue', new Error(`render ${i}`))
  }

  assert.equal(posted.length, 5)
})

test('the reporter cannot be re-entered by the failure it is reporting', async () => {
  const posted = []

  globalThis.window = { location: { href: 'https://health.test/' }, addEventListener() {} }
  globalThis.document = {
    cookie: '',
    querySelector: () => null,
    addEventListener() {},
  }

  const module = await import(`../../resources/js/lib/report.js?instance=${instance++}`)

  globalThis.fetch = (url, init) => {
    posted.push(JSON.parse(init.body))

    // Something downstream of the send reports while the first report is still
    // being assembled: it must not file its noise under the app's fault.
    module.reportClientError('error', new Error('reporting the reporter'))

    return Promise.resolve({ ok: true })
  }

  module.reportClientError('vue', new Error('the real one'))

  assert.equal(posted.length, 1)
  assert.equal(posted[0].message, 'Error: the real one')
})

test('a report is sent fire-and-forget, with keepalive and the CSRF token', async () => {
  const { reportClientError, posted } = await reporter()

  reportClientError('error', new Error('x'))

  assert.equal(posted[0].url, '/api/client-errors')
  assert.equal(posted[0].init.keepalive, true)
  assert.equal(posted[0].init.headers['X-XSRF-TOKEN'], 'token-value')
})
