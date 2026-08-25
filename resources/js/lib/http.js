/**
 * The hand-rolled fetch calls: the CSRF cookie, the headers that make a refusal
 * answer honestly, and the HTTP-status -> result ladder that lib/vision.js,
 * lib/supplements.js and lib/health-reports.js were each writing out in full.
 *
 * A DISCRIMINATED RESULT RATHER THAN A THROW, because every outcome is a
 * different thing on screen with a different next step, which a rejected promise
 * would flatten into "something went wrong".
 *
 * NOTHING HERE INVENTS A SENTENCE THE USER READS. Every endpoint passes its own
 * copy and its own extra codes. The two strings that DO live here are the
 * wording their callers settled on — not the app's one way of saying it — kept
 * in one place so those callers cannot drift apart from each other. They are
 * not the same set: STALE_PAGE is read by the three POST callers below,
 * SIGNED_OUT by those three and by lib/products.js, which imports it alone.
 *
 * THE DELIBERATE NON-ADOPTERS, each argued where it lives: lib/products.js
 * keeps its whole ladder and takes only SIGNED_OUT; lib/memory.js keeps both
 * its ladder and its own shorter 401 sentence; lib/queue.js answers in a
 * different vocabulary entirely and takes only the headers; lib/report.js
 * deliberately duplicates the cookie read rather than import anything at all.
 * Rewording either string below is a decision about its own callers, and never
 * a licence to go and reword theirs to match.
 */

/** 401. The session is gone; signing in again is the way out. */
export const SIGNED_OUT = 'Session expired. Reload the page and sign in.'

/** 419. The token is stale but the session is not; a reload gets a fresh one. */
const STALE_PAGE = 'This page has been open a while. Reload and try again.'

/**
 * Laravel's CSRF cookie, as the header the framework looks for. Inertia's own
 * requests carry it because axios does; a hand-rolled fetch() does not, and a
 * POST without it is a 419 that looks exactly like a session timeout. Read at
 * SEND time and never stored with the request: an action queued on Tuesday and
 * flushed on Wednesday must carry Wednesday's token, and keeping the token
 * beside the action is the classic way to build a queue that flushes into a wall
 * of 419s. The cookie value is URL-encoded.
 */
export function csrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

  return match ? decodeURIComponent(match[1]) : ''
}

/**
 * `Accept` and `X-Requested-With` together are what make an unauthenticated
 * attempt come back as a 401 with a body instead of a 302 to the login page —
 * which fetch() follows and hands back as a 200 full of HTML, i.e. as a success.
 * That is the whole reason these calls do not simply trust `response.ok`.
 * A fresh object per call, so a caller may add to what it gets — and editing it
 * edits the multipart posts too, since `formHeaders()` is this function unchanged.
 */
export function authHeaders() {
  return {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': csrfToken(),
  }
}

/** The same, for a JSON body. */
export function jsonHeaders() {
  return { ...authHeaders(), 'Content-Type': 'application/json' }
}

/**
 * The same, for a FormData body — deliberately WITHOUT `Content-Type`: the
 * multipart boundary is the browser's to generate, and naming the type by hand
 * sends a header with no boundary in it, which the server cannot parse.
 */
export function formHeaders() {
  return authHeaders()
}

/**
 * The one thing this module refuses to guess. A missing `busy` or `failed` is a
 * programmer error, and it has to LOOK like one: left unchecked it reaches the
 * user as `message: undefined` — an empty red box — or, thrown from inside the
 * fetch try, comes back as a confident `{status: 'offline'}` that is a lie.
 * Hence a throw, before anything can catch it.
 */
function requireCopy({ busy, failed }) {
  if (typeof busy !== 'string' || typeof failed !== 'string') {
    throw new TypeError('lib/http.js: `busy` and `failed` are required; this module does not choose copy the user reads.')
  }
}

/**
 * A failed response as the result the screen switches on.
 *
 * `busy` (429) and `failed` (the last resort) are required rather than
 * defaulted: an upload, a label reading and a report each say a different thing
 * there, and a default would be this module choosing words for a screen it
 * cannot see.
 *
 * @param {Response} response
 * @param {object|null} payload  the parsed body, or null when there was none
 * @param {{busy: string, failed: string, fieldErrorFirst?: boolean}} copy
 *        `fieldErrorFirst` puts a 422's field sentence ahead of its envelope
 *        `message`; see lib/health-reports.js for the one endpoint that wants it.
 */
export function interpretResponse(response, payload, { busy, failed, fieldErrorFirst = false }) {
  requireCopy({ busy, failed })

  if (response.status === 401) return { status: 'unauthenticated', message: SIGNED_OUT }

  if (response.status === 419) return { status: 'expired', message: STALE_PAGE }

  if (response.status === 429) return { status: 'rate_limited', message: busy }

  // A 422 from a form request arrives as {message, errors: {field: [...]}}; one
  // raised by a controller or a decoder carries {message} alone. Which of the
  // two reads better is the endpoint's call, not this function's.
  const envelope = payload?.message
  const field = Object.values(payload?.errors ?? {})[0]?.[0]

  return {
    status: payload?.status ?? 'failed',
    message: (fieldErrorFirst ? (field ?? envelope) : (envelope ?? field)) ?? failed,
  }
}

/**
 * One POST, as a result: `{ok: true, ...payload}` when the server said yes, and
 * `{ok: false, ...}` off the ladder above when it did not, so a caller asks one
 * question of it. `offline` is the copy for no network at all — a different
 * fact from any answer the server could give, and worth its own sentence.
 *
 * `extra` maps an HTTP status the ladder has no opinion about to a result
 * builder, and is consulted first: health-reports' 409 is the case it exists
 * for, where a refusal is not an error.
 */
async function post(url, body, { json, offline, extra = {}, ...copy }) {
  // Before the try, deliberately: inside it, a programmer error would be caught
  // and served to the user as "no connection".
  requireCopy(copy)

  let response

  try {
    response = await fetch(url, {
      method: 'POST',
      // Headers and serialisation both INSIDE the try, where each of these
      // modules had them: with no `document` to read the cookie off, the
      // failure has to come back as a result the screen can read out rather
      // than as a throw out of the middle of a caller.
      headers: json ? jsonHeaders() : formHeaders(),
      credentials: 'same-origin',
      body: json ? JSON.stringify(body) : body,
    })
  } catch {
    return { ok: false, status: 'offline', message: offline }
  }

  const payload = await response.json().catch(() => null)

  if (response.ok) return { ok: true, ...payload }

  const special = extra[response.status]

  return { ok: false, ...(special ? special(payload) : interpretResponse(response, payload, copy)) }
}

/** A POST of JSON. */
export function postJson(url, body, options) {
  return post(url, body, { ...options, json: true })
}

/** A POST of a prepared form — a photograph, in every case here. */
export function postForm(url, form, options) {
  return post(url, form, { ...options, json: false })
}
