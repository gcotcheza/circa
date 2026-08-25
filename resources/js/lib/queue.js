import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import * as idb from './idb.js'
import { authHeaders } from './http.js'

/**
 * The offline action queue.
 *
 * WHY THERE IS NO BACKGROUND SYNC IN HERE
 *
 * The Background Sync API does not exist in WebKit, and every browser on an
 * iPhone is WebKit — Chrome included, which is what this app is used from. No
 * `sync` event to register, no way to hand the OS a promise for when the radio
 * comes back, no way to run this app's code while it is not on screen. So the
 * queue is IN THE PAGE, flushing on `online`, on `visibilitychange` -> visible
 * and at launch — enough, because the user opens this app to log a meal, the
 * same moment anything queued gets sent.
 *
 * WHY REPLAY IS SAFE. Nothing in here needs a transaction log, because every
 * endpoint it replays was made idempotent in an earlier step for this reason:
 *
 *   meal create / re-log   client `uuid`: a uuid the server has seen returns the
 *                          existing meal and creates nothing.
 *   photo upload           client `idempotency_key`: a key the server has seen
 *                          returns the claimed analysis and does not buy a
 *                          second one from Anthropic.
 *   meal estimate          BOTH: the uuid identifies the meal, the key the
 *                          estimate, so a replay lands on the same meal and buys
 *                          the same one call.
 *   meal update / confirm  PUT of the complete desired state.
 *   supplement intake      PUT of the complete desired state, again: {date,
 *                          taken}. The unique index on (supplement, local_date)
 *                          makes a replayed tick a no-op and a replayed undo a
 *                          delete of something already gone — which is why it
 *                          answers 200 either way rather than 404, a status this
 *                          queue treats as permanent.
 *
 * A double flush therefore costs a duplicate HTTP request and changes nothing;
 * the `flushing` guard below saves the requests, not the correctness.
 *
 * WHY THE FIRST ATTEMPT GOES THROUGH HERE TOO. `submitOrQueue()` is used by the
 * meal sheet, the picker, the camera and the review screen for the ONLINE case
 * as well: one code path means the request the server sees on a replay is
 * byte-identical to the one it would have seen at the time, rather than an
 * Inertia visit the first time and a hand-rolled fetch the second — two
 * definitions of one write, one of them untested.
 */

/*
 * The ceiling. 50 actions is somewhere between a fortnight of meals and a number
 * no honest offline session reaches, so hitting it means something is wrong — a
 * flush that never succeeds, a 4xx nobody looked at — and the right response is
 * to SAY SO, not to make room by deleting the oldest. Silently discarding a meal
 * the user watched go into the app is the failure this module exists to prevent.
 */
export const MAX_ACTIONS = 50

/** Kinds, so the UI can label a queued action without parsing its URL. */
export const KIND = {
  mealCreate: 'meal.create',
  mealUpdate: 'meal.update',
  mealRepeat: 'meal.repeat',
  memoryLog: 'memory.log',
  mealConfirm: 'meal.confirm',
  photoUpload: 'photo.upload',
  mealEstimate: 'meal.estimate',
  supplementIntake: 'supplement.intake',
}

/** The in-memory mirror of the store, for rendering. IndexedDB is not reactive. */
const records = ref([])

export const actions = computed(() => records.value)

export const pendingCount = computed(() => records.value.length)

export const blockedCount = computed(() => records.value.filter((a) => a.state === 'blocked').length)

export const flushing = ref(false)

/**
 * `navigator.onLine === false` is a reliable NO; `true` means only "there is an
 * interface up", which on a phone includes a wifi network that needs a login. So
 * it is trusted to say offline and never to say online — the fetch decides that.
 */
export const online = ref(typeof navigator === 'undefined' || navigator.onLine !== false)

/** Last thing that went wrong at the queue level (full, or the store is dead). */
export const queueError = ref(null)

let started = false

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Send it now if we can, queue it if we cannot.
 *
 * @returns {Promise<{ok: boolean, queued?: boolean, outcome?: string, message?: string, errors?: object, data?: object}>}
 */
export async function submitOrQueue(action) {
  const record = build(action)

  if (navigator.onLine === false) {
    return queueIt(record)
  }

  const result = await send(record)

  if (result.outcome === 'done') {
    return { ok: true, data: result.data }
  }

  // Network gone mid-request: indistinguishable from having been offline all
  // along, and treated the same way.
  if (result.outcome === 'offline') {
    return queueIt(record)
  }

  /*
   * Everything else — 422, 401, 429, 500 — goes back to the caller rather than
   * the queue: the user is standing there with the sheet open, a message they
   * can act on now beats a silent retry in an hour, and a queued copy of a
   * request the server has already rejected would retry forever.
   */
  return { ok: false, outcome: result.outcome, message: result.message, errors: result.errors }
}

/** Put it straight in the queue without trying the network. */
export async function enqueue(action) {
  return queueIt(build(action))
}

/**
 * Change part of the body of something that has not been sent yet.
 *
 * The one caller is the capture sheet: a note typed after the shutter, while the
 * photograph it is about is still queued. Rewriting `payload.hint` on that
 * upload beats any request, because the replay carries the note into the FIRST
 * analysis — one call, and the model is told before it is asked.
 *
 * Deliberately a MERGE into `payload` and nothing else: the three ids, the blob
 * and the created-at stamp are what make a replay indistinguishable from the
 * upload that would have happened at the time, so this cannot become a general
 * "edit the queue" hook. A record that is no longer there is not an error worth
 * shouting about — a flush got to it first, i.e. what the user was waiting for
 * has happened.
 */
export async function amendPayload(id, patch) {
  try {
    const record = (await idb.all()).find((action) => action.id === id)

    if (!record) return { ok: false, outcome: 'gone', message: 'That is no longer queued.' }

    await idb.put({ ...record, payload: { ...record.payload, ...patch } })
    await refresh()

    return { ok: true }
  } catch (error) {
    queueError.value = error?.message ?? 'The offline queue could not be written.'

    return { ok: false, outcome: 'queue_failed', message: queueError.value }
  }
}

/**
 * Try to send everything queued, oldest first.
 *
 * Stops at the first action that fails for a reason time might fix (no network,
 * rate limit, server error): the queue is ordered, and hammering the rest of it
 * against a network that just refused is how a phone's battery disappears. One
 * that fails for a reason time will NOT fix is marked `blocked` and skipped by
 * every later flush until the user retries or discards it, without stopping the
 * ones behind it — one bad meal must not hold up four good ones.
 */
export async function flush({ reload = true } = {}) {
  if (flushing.value) return 0
  if (navigator.onLine === false) return 0

  flushing.value = true

  let synced = 0

  try {
    const queued = await idb.all()

    for (const action of queued) {
      if (action.state === 'blocked') continue

      const result = await send(action)

      if (result.outcome === 'done') {
        await idb.remove(action.id)
        synced++

        continue
      }

      if (isPermanent(result.outcome)) {
        await idb.put({
          ...action,
          state: 'blocked',
          attempts: action.attempts + 1,
          lastError: result.message ?? 'That was refused by the server.',
          lastOutcome: result.outcome,
        })

        continue
      }

      await idb.put({
        ...action,
        attempts: action.attempts + 1,
        lastError: result.message ?? null,
        lastOutcome: result.outcome,
      })

      // Signed out or a dead CSRF token: only the user can fix it, and it is
      // said at the queue level because it is one fact about all the cards.
      if (result.outcome === 'auth' || result.outcome === 'stale') {
        queueError.value = result.message
      }

      break
    }
  } catch (error) {
    queueError.value = error?.message ?? 'The offline queue could not be read.'
  } finally {
    await refresh()

    flushing.value = false
  }

  /*
   * One reload for the whole flush, not one per action, and only if something
   * landed. No `only:`: the queue does not know which page is on screen, and a
   * partial reload naming the day's props would hand the trends page props it
   * does not have.
   */
  if (synced > 0 && reload) {
    router.reload({ preserveScroll: true })
  }

  return synced
}

/** Un-block one action and try again — the "Retry" button. */
export async function retry(id) {
  const record = records.value.find((a) => a.id === id)

  if (!record) return

  await idb.put({ ...record, state: 'queued', lastError: null })
  await refresh()

  return flush()
}

/** Throw one away — the "Discard" button, and the only way anything leaves unsent. */
export async function discard(id) {
  await idb.remove(id)
  await refresh()
}

/**
 * Wire the three flush triggers, and do the launch flush. Idempotent: called
 * from the app entry, and calling it twice must not attach two sets of
 * listeners.
 */
export function startQueue() {
  if (started) return

  started = true

  window.addEventListener('online', () => {
    online.value = true
    flush()
  })

  window.addEventListener('offline', () => {
    online.value = false
  })

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return

    online.value = navigator.onLine !== false

    flush()
  })

  refresh().then(() => flush())
}

/** Re-read the store into the reactive mirror. */
export async function refresh() {
  try {
    records.value = await idb.all()
    queueError.value = null
  } catch (error) {
    records.value = []
    queueError.value = error?.message ?? 'The offline queue is unavailable on this device.'
  }
}

// ---------------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------------

function build(action) {
  return {
    id: action.id ?? crypto.randomUUID(),
    kind: action.kind,
    url: action.url,
    method: action.method ?? 'POST',
    payload: action.payload ?? {},
    blob: action.blob ?? null,
    blobField: action.blobField ?? 'photo',
    // Display-only, and deliberately separate from `payload`: a stray `label`
    // key in the wire body would be a field the server has to ignore forever.
    meta: action.meta ?? {},
    date: action.date ?? null,
    createdAt: Date.now(),
    attempts: 0,
    lastError: null,
    lastOutcome: null,
    state: 'queued',
  }
}

async function queueIt(record) {
  try {
    if ((await idb.count()) >= MAX_ACTIONS) {
      queueError.value = `The offline queue is full (${MAX_ACTIONS} items). Get back online, or clear the ones that failed.`

      return { ok: false, outcome: 'queue_full', message: queueError.value }
    }

    await idb.put(record)
    await refresh()

    return { ok: true, queued: true, id: record.id }
  } catch (error) {
    queueError.value = error?.message ?? 'This device cannot store anything offline.'

    return { ok: false, outcome: 'queue_failed', message: queueError.value }
  }
}

/**
 * Reasons a retry cannot help, i.e. reasons to stop trying this ACTION. A
 * blocked action is skipped by every later flush until a human retries or
 * discards it, so the list is short on purpose:
 *
 *   invalid   422. The server has read the payload and said no; the identical
 *             bytes will get the identical answer forever.
 *   rejected  404/403/409. Same argument.
 *
 * Deliberately NOT here: 429 and 5xx, the definition of things that fix
 * themselves; `auth` (401), because the flush stops and says so and signing in
 * makes the next flush work, where blocking would mean tapping Retry on every
 * queued meal by hand after a session expiry that was not the user's doing; and
 * `stale` (419), where a reload gets a fresh CSRF token and re-runs the launch
 * flush.
 */
function isPermanent(outcome) {
  return ['invalid', 'rejected'].includes(outcome)
}

/**
 * One attempt at one action.
 *
 * `Accept` + `X-Requested-With` are what make an unauthenticated attempt come
 * back as a 401 with a body instead of a 302 to the login page — which `fetch`
 * would follow and hand back as a 200 full of HTML, i.e. as a success (see
 * lib/http.js). That is the whole reason this does not simply check
 * `response.ok`.
 *
 * The Inertia endpoints answer a successful write with a 302 back to the day. We
 * let fetch follow it: the redirect is cheap, and following it CONSUMES the
 * flash message, which is what stops "Meal logged." appearing an hour late.
 */
async function send(action) {
  // Built HERE and never stored on the action: a request written to IndexedDB
  // on Tuesday and flushed on Wednesday must carry Wednesday's CSRF token.
  const headers = authHeaders()

  let body

  if (action.blob) {
    body = new FormData()

    for (const [key, value] of Object.entries(action.payload)) {
      if (value !== null && value !== undefined) body.append(key, value)
    }

    body.append(action.blobField, action.blob, 'meal.jpg')
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(action.payload)
  }

  let response

  try {
    response = await fetch(action.url, {
      method: action.method,
      headers,
      credentials: 'same-origin',
      body,
    })
  } catch {
    return { outcome: 'offline', message: 'No connection.' }
  }

  if (response.ok) {
    /*
     * Belt and braces on the one failure that would be SILENT DATA LOSS.
     *
     * `bootstrap/app.php` renders exceptions as JSON for callers that ask, so a
     * signed-out replay comes back as a 401 and is caught below. If that ever
     * regresses, the guest redirect lands here instead — a followed 302 to the
     * login page, i.e. a 200 of HTML that `response.ok` calls a success — and
     * deleting a queued meal on the strength of that is exactly what this module
     * exists to prevent, so the landing URL is checked too.
     */
    if (new URL(response.url, window.location.origin).pathname === '/login') {
      return { outcome: 'auth', message: 'Signed out. Sign in again and this will send.' }
    }

    return { outcome: 'done', data: await readJson(response) }
  }

  const payload = await readJson(response)

  if (response.status === 401) {
    return { outcome: 'auth', message: 'Signed out. Sign in again and this will send.' }
  }

  if (response.status === 419) {
    return { outcome: 'stale', message: 'This app has been open a while. Reload it and this will send.' }
  }

  if (response.status === 422) {
    return {
      outcome: 'invalid',
      errors: payload?.errors ?? {},
      message: firstMessage(payload) ?? 'The server would not accept this.',
    }
  }

  if (response.status === 429) {
    return { outcome: 'busy', message: 'Too many requests. It will try again shortly.' }
  }

  if (response.status >= 500) {
    return { outcome: 'server', message: 'The server had a problem. It will try again.' }
  }

  return { outcome: 'rejected', message: firstMessage(payload) ?? `The server refused this (${response.status}).` }
}

async function readJson(response) {
  try {
    return await response.json()
  } catch {
    // An Inertia redirect followed to an HTML page is the normal success case
    // and is not JSON: not an error, simply nothing to read.
    return null
  }
}

function firstMessage(payload) {
  if (!payload) return null

  return payload.message ?? Object.values(payload.errors ?? {})[0]?.[0] ?? null
}
