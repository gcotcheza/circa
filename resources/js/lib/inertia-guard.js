import axios from 'axios'
// With the extension, unlike the rest of the app's imports: this module is
// loaded directly by Node in tests/js, whose ESM resolver does not guess
// extensions the way Vite does.
import { reportClientError, setPageComponent } from './report.js'

/**
 * Surviving a response that says it is an Inertia page and is not one.
 *
 * THE BUG THIS IS FOR, IN THE ORDER IT WAS FOUND
 *
 * Three rows in `client_errors`, three builds, one morning, all identical:
 * `rejection`, `undefined is not an object (evaluating 'e.toString')`, stacked
 * in `rb` / `pageUrl` / `setPage` — i.e. `hrefToUrl`, `Response.pageUrl` and
 * `Response.setPage` in @inertiajs/core (the class methods survived
 * minification, the module-level function did not), which are four lines:
 *
 *   async setPage() {
 *     const pageResponse = this.getPageResponse()
 *     ...
 *     pageResponse.url = history.preserveUrl ? ... : this.pageUrl(pageResponse)
 *   }
 *   pageUrl(pageResponse) { const responseUrl = hrefToUrl(pageResponse.url); ... }
 *   function hrefToUrl(href) { return new URL(href.toString(), ...) }
 *
 * So `pageResponse.url` was `undefined` and `undefined.toString()` threw — not a
 * bug in this app's components, and not the error reporter's doing either. One
 * step up is why: Inertia asks for `responseType: 'text'` and then:
 *
 *   isInertiaResponse() { return this.hasHeader('x-inertia') }
 *   getDataFromResponse(r) { try { return JSON.parse(r) } catch { return r } }
 *
 * The HEADER decides that a response is a page; the BODY falls back to the raw
 * string. So any response carrying `x-inertia` whose body is empty, truncated or
 * not JSON becomes a string, and `"".url` is `undefined`.
 *
 * WHAT THE SERVER LOG SAID, AND WHY IT MATTERS
 *
 * All three faults were a GET the origin answered `200` with a complete body
 * (2716, 2806 and 34342 bytes), each retried by the user seconds later to a
 * byte-identical response that worked, and each the FIRST request after an idle
 * gap (154s, 39 minutes, 92s) from an iPhone through Cloudflare: a body that did
 * not survive the trip, not a page this app rendered wrongly. It is the leading
 * explanation rather than a fact, because the row said `undefined is not an
 * object` and nothing else — no way to tell an empty body from an HTML one from
 * JSON with no url. Hence the second fix: every rejection from this module
 * carries the status, the content type and the byte count, so the NEXT
 * occurrence names its own cause.
 *
 * WHAT THIS ACTUALLY DOES
 *
 * 1. AN AXIOS RESPONSE INTERCEPTOR — the only place in the stack where the raw
 *    body is still visible — requiring a `url` and a `component`, which is the
 *    precondition Inertia needs and never checks.
 * 2. ONE RETRY, FOR GET ONLY — argued where it is implemented, below.
 * 3. A `inertia:exception` LISTENER, the guarantee, because Inertia re-rejects
 *    unless a listener calls `preventDefault()`:
 *
 *      if (fireExceptionEvent(error)) { ...; return Promise.reject(error) }
 *
 *    which is how the TypeError became an unhandled rejection. It catches every
 *    Inertia transport failure, including the ones the interceptor never sees,
 *    and files each with a message instead of a shrug.
 *
 * WHY IT IS SAFE TO INTERCEPT AXIOS, AND HOW IT FAILS IF IT IS NOT
 *
 * `@inertiajs/core` does `import { default as axios } from 'axios'` and axios is
 * deduplicated to one copy at the root of node_modules, so this file and Inertia
 * hold the same singleton. `axios` is listed in package.json for that reason and
 * no other: it makes a fact the fix depends on into something npm has to keep
 * true. If it stops holding, the interceptor is inert, registered on an axios
 * nobody uses — but step 3 is independent of it and still prevents the unhandled
 * rejection, so the failure mode is a report with less detail, never a worse app.
 */

/** Set on a request config once it has had its one retry. */
const RETRIED = '__healthInertiaRetried'

/** A non-JSON body is quoted this far into the report and no further. */
const MAX_HEAD = 64

let installed = false

/**
 * Is this response usable as an Inertia page? Returns the reason it is not, or
 * null. `json` says whether the body parsed at all, which decides whether
 * quoting the start of it can leak anything: a body that is NOT JSON is by
 * construction not this app's page props. Exported for tests.
 */
export function pageResponseFault(data) {
  let page = data

  if (typeof page === 'string') {
    if (page.trim() === '') return { fault: 'empty body', json: false }

    try {
      page = JSON.parse(page)
    } catch {
      return { fault: 'body is not JSON', json: false }
    }
  }

  if (page === null) return { fault: 'body is null', json: true }

  if (Array.isArray(page)) return { fault: 'body is an array', json: true }

  if (typeof page !== 'object') return { fault: `body is a ${typeof page}`, json: true }

  // The two fields Inertia dereferences without checking: `url` in
  // `Response.pageUrl`, `component` in `CurrentPage.set` -> `this.resolve()`.
  if (typeof page.url !== 'string' || page.url === '') return { fault: 'page has no url', json: true }

  if (typeof page.component !== 'string' || page.component === '') {
    return { fault: 'page has no component', json: true }
  }

  return { fault: null, json: true }
}

/**
 * A header, from either shape axios hands back: an `AxiosHeaders` in current
 * versions, a plain lowercase-keyed object in older ones and in every test
 * double anybody writes.
 */
function header(response, name) {
  const headers = response?.headers

  if (!headers) return null

  try {
    if (typeof headers.get === 'function') return headers.get(name) ?? null
  } catch {
    // Fall through to the plain-object read.
  }

  return headers[name] ?? null
}

/**
 * Exactly Inertia's own `hasHeader('x-inertia')`, and it has to stay that: a
 * guard judging a different set of responses than the code it guards would
 * either miss the fault or reject something that works.
 */
function isInertiaResponse(response) {
  return header(response, 'x-inertia') != null
}

/**
 * The facts about a response that could not be used, in the order they answer
 * "why not". `head` is filled in only when the body did not parse as JSON,
 * because this app's page props ARE JSON and must never appear in a report; what
 * lands there is `<!DOCTYPE html>` or a proxy's error text.
 */
function diagnose(response, fault, json, retried) {
  const body = typeof response?.data === 'string' ? response.data : null

  const context = {
    at: 'inertia-response',
    fault,
    status: response?.status ?? null,
    type: typeof header(response, 'content-type') === 'string'
      ? header(response, 'content-type').split(';', 1)[0]
      : null,
    bytes: body === null ? null : body.length,
    method: String(response?.config?.method ?? '').toLowerCase() || null,
    retried,
  }

  if (!json && body) context.head = body.slice(0, MAX_HEAD)

  return context
}

/**
 * Rejected with a plain Error, and specifically WITHOUT a `response` property.
 * Inertia's first catch is `if (error?.response) { ...process it as a
 * response... }`, so handing it something axios-error-shaped would send the same
 * unusable body straight back into `setPage` and reproduce the TypeError.
 */
function malformed(context) {
  const error = new Error(`Inertia response was not a page: ${context.fault}`)

  error.name = 'MalformedInertiaResponse'
  error.inertiaContext = context

  return error
}

/**
 * Install the interceptor and the two document listeners. Idempotent, and called
 * from app.js BEFORE `createInertiaApp` so the interceptor is in place before
 * the router can make its first request.
 */
export function installInertiaGuard() {
  if (installed || typeof document === 'undefined') return

  installed = true

  try {
    axios.interceptors.response.use((response) => {
      if (!isInertiaResponse(response)) return response

      const { fault, json } = pageResponseFault(response.data)

      if (fault === null) return response

      const config = response.config ?? {}

      const retried = config[RETRIED] === true

      /*
       * One more go, and only for a GET: a tap on "Trends" that quietly does
       * nothing is the user-visible bug, and re-issuing is what fixes it, but a
       * replayed POST /meals is a second dinner.
       *
       * The same config object, so the visit's abort signal still cancels it and
       * navigating away mid-retry is still a cancellation rather than an error.
       * `RETRIED` on that config is what makes this exactly one retry: the second
       * response comes back through here and finds the flag already set.
       */
      if (!retried && String(config.method ?? 'get').toLowerCase() === 'get') {
        config[RETRIED] = true

        return axios(config)
      }

      throw malformed(diagnose(response, fault, json, retried))
    }, (error) => {
      /*
       * THE OTHER DOOR INTO `setPage`, AND IT IS EASY TO MISS.
       *
       * A 422 or a 500 is a rejected axios promise, so it never reaches the
       * handler above — but Inertia catches it and puts the body through the
       * very same code:
       *
       *   .catch((error) => {
       *     if (error?.response) {
       *       this.response = Response.create(..., error.response, ...)
       *       return this.response.handle()          // -> setPage -> pageUrl
       *     }
       *
       * A validation failure is a full page object with an `errors` prop and can
       * arrive unusable for the same reasons a 200 can, so guarding only the
       * success path would have left the identical crash live on every form. No
       * retry: the server has already answered, and re-issuing a request it
       * refused is not a recovery. Swapping in an error with no `response`
       * property is what stops the body reaching `setPage`.
       */
      try {
        const response = error?.response

        if (response && isInertiaResponse(response)) {
          const { fault, json } = pageResponseFault(response.data)

          if (fault !== null) {
            return Promise.reject(malformed(diagnose(response, fault, json, false)))
          }
        }
      } catch {
        // The original error beats anything this block could invent.
      }

      return Promise.reject(error)
    })
  } catch {
    // This module must not be the reason the app fails to boot; the listener
    // below is the part that actually holds the line.
  }

  /*
   * The rejection stops here. `cancelable: true` on Inertia's side, so
   * `preventDefault()` is a supported instruction and not a trick: it turns
   * `fireExceptionEvent(error)` false, skipping the `Promise.reject(error)` so
   * the visit's promise settles, and `Request.finish()` is in a `.finally` so
   * the visit still ends and the progress bar still clears. Nothing downstream
   * was reading that rejection — its only reader was
   * `window.onunhandledrejection`, i.e. this app's error reporter, called here
   * directly and with more to say.
   */
  document.addEventListener('inertia:exception', (event) => {
    try {
      const error = event?.detail?.exception ?? null

      const context = (error && typeof error === 'object' && error.inertiaContext) || {
        at: 'inertia-request',
      }

      event.preventDefault?.()

      reportClientError('inertia', error, { source: context.at, context })
    } catch {
      // A listener that throws inside dispatchEvent surfaces as a window error
      // and would be reported as the app's fault.
    }
  })

  /*
   * Which screen was on show, written into every report from here on: `Day` is
   * more use than any single frame of a minified stack.
   *
   * THE BACKSTOP, NOT THE PRIMARY. app.js sets the name in `resolve`, before the
   * component swap; this event fires one microtask AFTER it, so on its own it
   * would file a crash during a page's first render under the previous page —
   * see the comment in app.js, and `client_errors` row 7. What it still earns is
   * the correction after a visit that was resolved and then abandoned.
   */
  document.addEventListener('inertia:navigate', (event) => {
    try {
      setPageComponent(event?.detail?.page?.component ?? null)
    } catch {
      // Nothing to do. A report without a component is the old behaviour.
    }
  })
}
