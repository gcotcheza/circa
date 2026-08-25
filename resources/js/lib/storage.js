import { ref } from 'vue'

/**
 * `navigator.storage.persist()` — asking the browser not to throw the queue
 * away.
 *
 * WebKit evicts all script-writable storage — IndexedDB, so the offline meal
 * queue — for an origin not interacted with for SEVEN DAYS; a fortnight's
 * holiday with the app unopened loses whatever was waiting in it.
 *
 * A HOME-SCREEN WEB APP IS THE EXCEPTION: installing moves the app into its own
 * storage container, out of the eviction path, and is also what makes a
 * `persist()` request likely to be granted at all. So it is asked at the first
 * AUTHENTICATED launch: before sign-in nothing is worth protecting, and the
 * grant heuristics are worth spending on a real session.
 *
 * The outcome is shown and not merely logged, so the user can act on it. The
 * localStorage key is app-prefixed, the habit that stops the
 * Scribly/Reflection shared-origin collision from happening again.
 */

const KEY = 'health-tracker:storage-persistence'

/** 'persisted' | 'denied' | 'unsupported' | 'unknown' */
export const persistence = ref(read())

export function persistenceLabel(state = persistence.value) {
  switch (state) {
    case 'persisted':
      return 'Offline log is protected on this device.'
    case 'denied':
      return 'Offline log is not protected — add this to your Home Screen so a week away cannot clear it.'
    case 'unsupported':
      return 'This browser cannot protect the offline log from being cleared.'
    default:
      return null
  }
}

/**
 * Ask once, remember the answer, re-ask only while it is "no" — cheap, because
 * there is no dialog on iOS, and the heuristic behind a refusal changes the
 * moment the user adds the app to their Home Screen.
 */
export async function ensurePersistentStorage() {
  if (typeof navigator === 'undefined' || !navigator.storage?.persist) {
    return write('unsupported')
  }

  try {
    if (await navigator.storage.persisted()) {
      return write('persisted')
    }

    const granted = await navigator.storage.persist()

    // Also a console line: worth reading off a phone attached to a laptop when
    // a queued meal has vanished.
    console.info('[health] navigator.storage.persist() =>', granted)

    return write(granted ? 'persisted' : 'denied')
  } catch {
    // Private browsing, or an engine that has the method and refuses it.
    return write('unsupported')
  }
}

/** How much has been stored, for the about line. Best-effort; null if unknown. */
export async function storageEstimate() {
  if (!navigator.storage?.estimate) return null

  try {
    const { usage, quota } = await navigator.storage.estimate()

    return { usage: usage ?? null, quota: quota ?? null }
  } catch {
    return null
  }
}

function read() {
  try {
    return localStorage.getItem(KEY) ?? 'unknown'
  } catch {
    return 'unknown'
  }
}

function write(state) {
  persistence.value = state

  try {
    localStorage.setItem(KEY, state)
  } catch {
    // Private mode. The in-memory value still drives this session's UI.
  }

  return state
}
