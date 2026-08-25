/**
 * Telling the server that the browser broke.
 *
 * WHY THIS FILE EXISTS
 *
 * The blade ships an empty `#app` and everything after that is drawn by this
 * bundle, so every "the screen was blank" fault — a chunk that 404'd after a
 * deploy, a component that threw during render, a rejected dynamic import —
 * produces a white rectangle on a phone and NOTHING on the server: the HTML was
 * a 200, the assets were 200s, no log line anywhere says otherwise, and the
 * person holding the phone is the only witness. This turns that into a row in
 * `client_errors` with a message, a stack, a page and a build hash.
 *
 * THE RULE: IT CAN NEVER BE THE PROBLEM
 *
 * Every path through this module is wrapped so that it cannot throw. A reporter
 * that throws inside `window.onerror` re-enters the handler it is running in,
 * and one that awaits anything can turn a render error into an unhandled
 * rejection that fires the reporter again. Both are how a diagnostic tool
 * becomes the outage. Concretely:
 *   - `fetch` is fire-and-forget with a `.catch(() => {})`, never read and never
 *     awaited, and `keepalive: true` so a report fired during a crash still
 *     leaves a device that is being torn down a moment later.
 *   - a re-entrancy latch, because serialising a hostile value is the one place
 *     in here that can plausibly throw.
 *   - identical faults once per page load, at most `MAX_REPORTS_PER_PAGE` in
 *     all, and nothing at all while offline.
 *
 * WHY `describe` IS AS LONG AS IT IS
 *
 * `throw` and `Promise.reject` take a VALUE, not an Error: `unhandledrejection`
 * fires with `event.reason === undefined` for a bare `reject()`, with a string
 * for half the minified libraries in the world, and with an Error-shaped object
 * that fails `instanceof Error` whenever it crossed a realm boundary (an iframe,
 * a worker, some WebKit internals). A serialiser that assumes an Error THROWS on
 * the undefined case, and what then lands in `client_errors` is its own
 * TypeError instead of the fault that caused it — the real error gone, the row
 * pointing at this file. That is the specific way a crash reporter becomes a
 * liar, so every branch below is a value this has to survive — including the
 * unobvious ones: cross-realm Error, DOMException, symbol, circular object,
 * null-prototype object.
 *
 * WHAT IT SENDS, AND WHAT IT REFUSES TO
 *
 * The body below, and nothing else. NOT sent: page props, form contents, meal
 * data, the user's name, anything from storage. The user agent is not sent
 * either — the server reads it from the request header, which is the honest
 * source. `location.href` is the one field with any identifying content, and it
 * is a path in a single-user app.
 *
 * The `component` field is the cheap half of making a minified stack readable:
 * `rb@app-BozACjIO.js:15:16699` says nothing, `Day` says which screen. The
 * expensive half is `build.sourcemap` in vite.config.js.
 */

const ENDPOINT = '/api/client-errors'

/**
 * Five per page load: enough to capture a cascade (a failed import, the render
 * that needed it, the retry) whole, low enough that a component throwing on
 * every frame stops costing anything almost immediately. The server's 10/minute
 * limiter backstops the case where this counter is in the broken code.
 */
const MAX_REPORTS_PER_PAGE = 5

/** Stacks are the useful field and the enormous one. 4 KB matches the column. */
const MAX_STACK = 4000

const MAX_MESSAGE = 1000

/** Matches the `context` column. See `serializeContext` for what goes in it. */
const MAX_CONTEXT = 1000

/** Matches the `component` column. Inertia component names are one word. */
const MAX_COMPONENT = 64

let sent = 0

/**
 * True while a report is being assembled. The outer try/catch stops a throw from
 * escaping; this stops one from RE-ENTERING — `describe` running over a hostile
 * value is called from inside `window.onerror`, and a reporter that reported its
 * own serialisation failure would file the wrong error under the right
 * timestamp.
 */
let reporting = false

/** Fingerprints already reported, so a repeat costs nothing. */
const seen = new Set()

/**
 * The Inertia page component on screen, or null before the first render. Set
 * from app.js's `resolve`, the earliest point at which the name of the page
 * about to be drawn is known — a crash in that page's first render would
 * otherwise be filed under the page before it (see the comment there).
 * lib/inertia-guard.js corrects it afterwards from `inertia:navigate`, a plain
 * DOM event, so this module still imports nothing and still cannot be broken by
 * whatever it is reporting on.
 */
let pageComponent = null

export function setPageComponent(component) {
  pageComponent = typeof component === 'string' && component !== '' ? component : null
}

/**
 * The build this page was served by — the md5 prefix of the Vite manifest, the
 * same string the service worker uses as its cache name. It is what makes a
 * report answerable: an error from a build since deployed over is a tab somebody
 * left open, the same error against the current build is a live bug.
 */
export function buildVersion() {
  try {
    return document.querySelector('meta[name="app-build"]')?.content || null
  } catch {
    return null
  }
}

/**
 * ITS OWN COPY OF lib/http.js's, deliberately, and the one duplicate in this
 * bundle that should stay. This file's rule is that it can never be the problem;
 * an import makes that rule rest on a property of another module nothing pins —
 * http.js growing an import of its own some day would silently hand the reporter
 * that whole init graph to survive before it can report anything.
 */
function csrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

  return match ? decodeURIComponent(match[1]) : ''
}

function truncate(value, max) {
  if (typeof value !== 'string') return null

  return value.length > max ? value.slice(0, max) : value
}

/**
 * `[object DOMException]`, and it cannot throw. `String(value)` and template
 * literals both call the value's own `toString`, which a null-prototype object
 * does not have and a hostile one can define as a thrower;
 * `Object.prototype.toString.call` asks the language, not the value.
 */
function label(value) {
  try {
    return Object.prototype.toString.call(value)
  } catch {
    return '<unprintable>'
  }
}

/**
 * Error-shaped, whether or not it passes `instanceof`. An Error thrown across a
 * realm boundary — an iframe, a worker, some of WebKit's own internals — has a
 * genuine stack but a prototype from the OTHER realm, so `instanceof Error` is
 * false. The previous version fell through to `JSON.stringify`, which serialises
 * an Error's non-enumerable `message` and `stack` as `{}`: the most useful value
 * in the system, recorded as two braces.
 */
function isErrorLike(value) {
  if (value instanceof Error) return true

  return (
    typeof value === 'object' &&
    value !== null &&
    typeof value.message === 'string' &&
    (typeof value.stack === 'string' || typeof value.name === 'string')
  )
}

function stackOf(value) {
  return typeof value?.stack === 'string' && value.stack !== '' ? value.stack : null
}

/**
 * Whatever was thrown, as a message, a stack and a one-word note about what sort
 * of value it actually was. That last field matters: `<undefined>` in the
 * message column is the difference between "a promise rejected with nothing, go
 * and find the bare reject()" and a row that reads as if the app produced the
 * string "undefined" on purpose.
 */
function describe(value) {
  try {
    if (value === undefined) return { message: '<undefined>', stack: null, type: 'undefined' }

    if (value === null) return { message: '<null>', stack: null, type: 'null' }

    if (typeof value === 'string') {
      return { message: value === '' ? '<empty string>' : value, stack: null, type: 'string' }
    }

    if (isErrorLike(value)) {
      const name = typeof value.name === 'string' && value.name !== '' ? value.name : 'Error'
      const text = typeof value.message === 'string' && value.message !== '' ? value.message : name

      // WebKit's `stack` starts at the throwing frame and never repeats the
      // message, so without the name a TypeError and a NetworkError read alike.
      const message = text === name || text.startsWith(`${name}:`) ? text : `${name}: ${text}`

      return {
        message: value.cause !== undefined && value.cause !== null
          ? `${message} (cause: ${causeOf(value.cause)})`
          : message,
        stack: stackOf(value),
        type: name,
      }
    }

    if (typeof value === 'object') {
      let json = null

      try {
        json = JSON.stringify(value)
      } catch {
        // Circular, or a getter that throws. Neither is worth a second attempt.
      }

      const useful = typeof json === 'string' && json !== '{}' && json !== 'null'

      return { message: useful ? json : label(value), stack: stackOf(value), type: 'object' }
    }

    // number, boolean, bigint, symbol, function.
    return { message: label(value), stack: null, type: typeof value }
  } catch {
    // Unreachable by design — every branch above is total — and here anyway,
    // because this function's failure mode is the one this file exists to
    // prevent. `<undescribable>` is still a row with a timestamp and a build.
    return { message: '<undescribable>', stack: null, type: 'unknown' }
  }
}

/** One level of `cause`, as a phrase. Deliberately not recursive. */
function causeOf(cause) {
  try {
    if (isErrorLike(cause)) return typeof cause.message === 'string' ? cause.message : label(cause)
    if (typeof cause === 'string') return cause

    return label(cause)
  } catch {
    return '<undescribable>'
  }
}

/**
 * The `context` column: a short JSON object of facts ABOUT the failure, as
 * opposed to the failure's own message.
 *
 * Text rather than a `json` column on purpose: this value is truncated to fit,
 * truncated JSON is invalid JSON, and a Postgres `json` column would answer that
 * with an exception thrown by the one endpoint whose entire job is not to throw.
 */
function serializeContext(context, described) {
  try {
    const base = context && typeof context === 'object' && !Array.isArray(context) ? context : {}

    const json = JSON.stringify({ ...base, value: described.type })

    return typeof json === 'string' ? truncate(json, MAX_CONTEXT) : null
  } catch {
    return null
  }
}

/**
 * What makes two reports the same report. The first stack frame is in here
 * because it is the only thing separating two different bugs with the same
 * sentence in them, and on a minified build that happens: `undefined is not an
 * object` is the message for a dozen unrelated faults. Without the frame the
 * second is swallowed as a duplicate of the first, which is exactly how a new
 * bug hides behind an old one.
 */
function fingerprint(kind, message, extra, stack) {
  const frame = typeof stack === 'string' ? stack.split('\n', 1)[0] : ''

  return [kind, message, extra.source ?? '', extra.line ?? '', frame].join('|')
}

/**
 * Send one report. Returns nothing, awaits nothing, throws nothing.
 * `extra.context` is an optional plain object of facts about the failure — see
 * lib/inertia-guard.js, the one caller that has any.
 */
export function reportClientError(kind, error, extra = {}) {
  if (reporting) return

  reporting = true

  try {
    if (sent >= MAX_REPORTS_PER_PAGE) return

    // Offline: the request would fail and there is nothing worth queueing. The
    // next fault after the radio comes back will be reported.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return

    const described = describe(error)

    const message = truncate(extra.message ?? described.message, MAX_MESSAGE)

    if (!message) return

    const stack = truncate(extra.stack ?? described.stack, MAX_STACK)

    const key = fingerprint(kind, message, extra, stack)

    if (seen.has(key)) return

    seen.add(key)
    sent += 1

    const body = JSON.stringify({
      kind,
      message,
      source: truncate(extra.source ?? null, 1000),
      line: Number.isFinite(extra.line) ? extra.line : null,
      col: Number.isFinite(extra.col) ? extra.col : null,
      stack,
      url: truncate(window.location.href, 2000),
      build: buildVersion(),
      component: truncate(pageComponent, MAX_COMPONENT),
      context: serializeContext(extra.context, described),
    })

    fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      // Lets a report survive the navigation or teardown that often follows the
      // error that caused it.
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        // Both, for the same reason lib/queue.js sends both: they make an
        // expired session answer 401 with a body instead of a 302 to the login
        // page that fetch would follow and call a success.
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken(),
      },
      body,
    }).catch(() => {
      // A reporter that reports its own failure to report is a loop.
    })
  } catch {
    // The more important of the two: called from inside `window.onerror` and
    // from Vue's error handler, where a throw re-enters the caller.
  } finally {
    reporting = false
  }
}

/** Exported for the unit tests in tests/js. Not part of the public surface. */
export const __private = { describe, serializeContext, fingerprint }

/**
 * Wire the two global handlers.
 *
 * `error` on the window catches synchronous throws AND — because it is
 * registered in the capture phase — resource load failures, which matter most
 * here: a `<script>` or a dynamic `import()` for a chunk deleted by a deploy
 * fires exactly this, with no message a user would ever see.
 *
 * `unhandledrejection` is the other half; every await in this app is a fetch or
 * a dynamic import, so a rejection reaching the top is the network or a missing
 * chunk. NOTE that one reaching HERE is now a failure of something else: the one
 * recurring unhandled rejection this app has had came out of Inertia's response
 * handling, and lib/inertia-guard.js catches it at the source, where the
 * response is still in scope. This handler remains the floor, not the plan.
 */
export function installErrorReporting() {
  if (typeof window === 'undefined') return

  window.addEventListener(
    'error',
    (event) => {
      /*
       * A failed resource load: `event.target` is the element and there is no
       * `error` object at all — what a 404 on a build asset looks like from
       * JavaScript. Reporting it by URL is the whole point.
       */
      const target = event.target

      if (target && target !== window && (target.src || target.href)) {
        reportClientError('error', null, {
          message: `Failed to load ${target.tagName?.toLowerCase?.() ?? 'resource'}`,
          source: target.src || target.href,
        })

        return
      }

      reportClientError('error', event.error, {
        message: event.message,
        source: event.filename,
        line: event.lineno,
        col: event.colno,
      })
    },
    true,
  )

  window.addEventListener('unhandledrejection', (event) => {
    reportClientError('rejection', event.reason)
  })
}
