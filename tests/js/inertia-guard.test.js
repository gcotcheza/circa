import assert from 'node:assert/strict'
import test from 'node:test'

/**
 * resources/js/lib/inertia-guard.js — the fix for the recurring day-view
 * rejection, tested against the response that caused it. @inertiajs/core decides
 * a response is a page from its HEADER (`hasHeader('x-inertia')`) but falls back
 * to the raw string when the BODY will not parse, so `Response.pageUrl` reaches
 * `hrefToUrl(page.url)` → `href.toString()` on `"".url` — `undefined is not an
 * object (evaluating 'e.toString')`, unhandled, three times in one morning
 * behind a tap that did nothing. Driven through the real axios with a stub
 * adapter, so the interceptor is exercised as installed.
 */

const posted = []

globalThis.window = { location: { href: 'https://health.test/' }, addEventListener() {} }

const listeners = {}

globalThis.document = {
  cookie: '',
  querySelector: () => ({ content: 'af0d37859d98' }),
  addEventListener(type, callback) {
    listeners[type] = callback
  },
}

globalThis.fetch = (url, init) => {
  posted.push(JSON.parse(init.body))

  return Promise.resolve({ ok: true })
}

const axios = (await import('axios')).default
const { installInertiaGuard, pageResponseFault } = await import('../../resources/js/lib/inertia-guard.js')
const { setPageComponent } = await import('../../resources/js/lib/report.js')

installInertiaGuard()

/** An axios adapter that answers with whatever you hand it, and counts. */
function adapter(...answers) {
  const calls = []

  return {
    calls,
    fn: (config) => {
      calls.push(config)

      const answer = answers[Math.min(calls.length - 1, answers.length - 1)]

      const response = {
        status: answer.status ?? 200,
        statusText: 'OK',
        data: answer.data,
        headers: answer.headers ?? {},
        config,
      }

      /* A real adapter calls `settle`, which rejects anything outside 2xx with an
       * error carrying the response. A 422 reaches Inertia down a different path
       * from a 200, so the stub honours that rather than testing one branch
       * twice. */
      if (response.status >= 400) {
        const error = new Error(`Request failed with status code ${response.status}`)

        error.isAxiosError = true
        error.config = config
        error.response = response

        return Promise.reject(error)
      }

      return Promise.resolve(response)
    },
  }
}

/** A request shaped like the ones @inertiajs/core makes. `responseType: 'text'`
 * is what the core sends and what suppresses axios's own JSON parsing — which is
 * why an unusable body survives as a string all the way to `pageResponse.url`.
 * Omit it and the test exercises a path production never takes. */
function visit(config) {
  return axios({ responseType: 'text', ...config })
}

const INERTIA = { 'x-inertia': 'true', 'content-type': 'application/json' }

const PAGE = JSON.stringify({
  component: 'Day',
  props: {},
  url: '/?date=2026-08-09',
  version: 'abc',
})

// --- what counts as a usable page ----------------------------------------

test('a body is a page only when it has the two fields Inertia dereferences', () => {
  assert.equal(pageResponseFault(PAGE).fault, null)
  assert.equal(pageResponseFault(JSON.parse(PAGE)).fault, null)

  // The leading theory for the live fault: headers arrived, body did not.
  assert.equal(pageResponseFault('').fault, 'empty body')
  assert.equal(pageResponseFault('   \n ').fault, 'empty body')

  assert.equal(pageResponseFault('<!DOCTYPE html><html>').fault, 'body is not JSON')
  assert.equal(pageResponseFault('{"component":"Day","props":{},"ur').fault, 'body is not JSON')

  assert.equal(pageResponseFault('null').fault, 'body is null')
  assert.equal(pageResponseFault('[]').fault, 'body is an array')
  assert.equal(pageResponseFault('"a string"').fault, 'body is a string')

  // `url` is the field that throws; `component` is the next one that would.
  assert.equal(pageResponseFault('{"component":"Day"}').fault, 'page has no url')
  assert.equal(pageResponseFault('{"url":"/"}').fault, 'page has no component')
  assert.equal(pageResponseFault('{"url":"","component":"Day"}').fault, 'page has no url')
})

test('only a non-JSON body may be quoted in a report', () => {
  // Wrong-shape JSON is still this app's props, which must never be sent.
  assert.equal(pageResponseFault('{"url":"/"}').json, true)
  assert.equal(pageResponseFault('<!DOCTYPE html>').json, false)
  assert.equal(pageResponseFault('').json, false)
})

// --- the interceptor -----------------------------------------------------

test('a good page passes through untouched, and is asked for once', async () => {
  const stub = adapter({ data: PAGE, headers: INERTIA })

  const response = await visit({ url: 'https://health.test/', method: 'get', adapter: stub.fn })

  assert.equal(stub.calls.length, 1)
  assert.equal(response.data, PAGE)
})

test('a response without the x-inertia header is none of our business', async () => {
  const stub = adapter({ data: 'not json at all', headers: { 'content-type': 'text/html' } })

  const response = await visit({ url: 'https://health.test/x', method: 'get', adapter: stub.fn })

  assert.equal(stub.calls.length, 1)
  assert.equal(response.data, 'not json at all')
})

test('an empty body on a GET is retried once, and the retry is believed', async () => {
  // The bug end to end: response one is what the phone got, two the retap.
  const stub = adapter({ data: '', headers: INERTIA }, { data: PAGE, headers: INERTIA })

  const response = await visit({ url: 'https://health.test/trends', method: 'get', adapter: stub.fn })

  assert.equal(stub.calls.length, 2)
  assert.equal(response.data, PAGE)
})

test('a GET that is unusable twice rejects with the facts, and never with a response', async () => {
  const stub = adapter({ data: '', headers: INERTIA })

  const error = await visit({ url: 'https://health.test/trends', method: 'get', adapter: stub.fn })
    .then(() => null, (thrown) => thrown)

  assert.equal(stub.calls.length, 2, 'one retry, and only one')
  assert.equal(error.name, 'MalformedInertiaResponse')

  /* NO `response` PROPERTY, load bearing: Inertia's first catch is
   * `if (error?.response) { ... }`, so an axios-shaped error would hand the same
   * unusable body back to `setPage` and reproduce the TypeError. */
  assert.equal(error.response, undefined)

  assert.deepEqual(error.inertiaContext, {
    at: 'inertia-response',
    fault: 'empty body',
    status: 200,
    type: 'application/json',
    bytes: 0,
    method: 'get',
    retried: true,
  })
})

test('a write is never replayed — one meal, one POST', async () => {
  const stub = adapter({ data: '', headers: INERTIA })

  const error = await visit({ url: 'https://health.test/meals', method: 'post', data: '{}', adapter: stub.fn })
    .then(() => null, (thrown) => thrown)

  assert.equal(stub.calls.length, 1, 'a replayed POST /meals is a second dinner')
  assert.equal(error.name, 'MalformedInertiaResponse')
  assert.equal(error.inertiaContext.retried, false)
  assert.equal(error.inertiaContext.method, 'post')
})

test('an HTML body is quoted far enough to recognise it', async () => {
  const login = '<!DOCTYPE html><html lang="en"><head><title>Sign in</title>'

  const stub = adapter({ data: login, headers: { ...INERTIA, 'content-type': 'text/html' } })

  const error = await visit({ url: 'https://health.test/', method: 'get', adapter: stub.fn })
    .then(() => null, (thrown) => thrown)

  assert.equal(error.inertiaContext.fault, 'body is not JSON')
  assert.equal(error.inertiaContext.type, 'text/html')
  assert.equal(error.inertiaContext.head, login.slice(0, 64))
})

test('a 422 with an unusable body is stopped too, and is not retried', async () => {
  // Error responses go through the same `setPage`, so every form is exposed.
  const stub = adapter({ status: 422, data: '', headers: INERTIA })

  const error = await visit({ url: 'https://health.test/meals', method: 'get', adapter: stub.fn })
    .then(() => null, (thrown) => thrown)

  assert.equal(stub.calls.length, 1, 'a request the server refused is not re-issued')
  assert.equal(error.name, 'MalformedInertiaResponse')
  assert.equal(error.response, undefined)
  assert.equal(error.inertiaContext.status, 422)
})

test('an ordinary 422 is left exactly as axios made it', async () => {
  const errors = JSON.stringify({
    component: 'Day',
    props: { errors: { kcal: 'required' } },
    url: '/',
    version: 'abc',
  })

  const stub = adapter({ status: 422, data: errors, headers: INERTIA })

  const error = await visit({ url: 'https://health.test/meals', method: 'get', adapter: stub.fn })
    .then(() => null, (thrown) => thrown)

  // `error.response` is what surfaces validation errors; eating it breaks forms.
  assert.equal(error.inertiaContext, undefined)
  assert.equal(error.response.status, 422)
  assert.equal(error.response.data, errors)
})

// --- the listeners -------------------------------------------------------

test('inertia:navigate names the screen for every report after it', () => {
  setPageComponent(null)

  listeners['inertia:navigate']({ detail: { page: { component: 'Trends' } } })

  posted.length = 0

  listeners['inertia:exception']({
    detail: { exception: new Error('anything') },
    preventDefault() {},
  })

  assert.equal(posted[0].component, 'Trends')
})

test('inertia:exception is prevented, so the rejection never reaches the window', () => {
  posted.length = 0

  let prevented = false

  const error = new Error('Inertia response was not a page: empty body')
  error.name = 'MalformedInertiaResponse'
  error.inertiaContext = { at: 'inertia-response', fault: 'empty body', status: 200, bytes: 0 }

  listeners['inertia:exception']({
    detail: { exception: error },
    preventDefault() {
      prevented = true
    },
  })

  /* Inertia's `if (fireExceptionEvent(error)) { return Promise.reject(error) }`
   * is what made this an unhandled rejection. `preventDefault` makes the `if`
   * false; `Request.finish()` is in a `.finally`, so the bar still clears. */
  assert.equal(prevented, true)

  assert.equal(posted.length, 1)
  assert.equal(posted[0].kind, 'inertia')
  assert.equal(posted[0].message, 'MalformedInertiaResponse: Inertia response was not a page: empty body')
  assert.equal(JSON.parse(posted[0].context).fault, 'empty body')
  assert.equal(JSON.parse(posted[0].context).status, 200)
})

test('a listener that is handed nothing at all still does not throw', () => {
  assert.doesNotThrow(() => listeners['inertia:exception']({}))
  assert.doesNotThrow(() => listeners['inertia:navigate']({}))
})
