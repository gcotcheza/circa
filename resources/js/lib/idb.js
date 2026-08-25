/**
 * A very small promise wrapper over IndexedDB.
 *
 * NOT `idb`/Dexie/localforage: the surface needed is one database, one store,
 * getAll/put/delete/count — the seventy lines below. The smallest of them is a
 * few kilobytes gzipped in the ENTRY bundle (the queue initialises on launch,
 * so it cannot be lazy), and when a meal logged on a train sits in a store that
 * will not open, the debugging happens on a phone with no dev tools, where a
 * dependency is a layer between the symptom and the lines that caused it.
 *
 * NOT localStorage: synchronous and string-only, so a downscaled JPEG would be
 * base64'd (+33%) and would block the main thread, and its ~5 MB per-origin
 * ceiling in WebKit would be filled by three queued photos. What is written
 * here survives WebKit's 7-day eviction of unused script-writable storage only
 * because of `navigator.storage.persist()` (lib/storage.js).
 */

const DB_NAME = 'health-tracker'
const DB_VERSION = 1
const STORE = 'actions'

let connection = null

/**
 * One connection, opened lazily and reused — an app that has never been offline
 * should not pay for a database it will never write to, and at module load the
 * open would race the page's first paint.
 */
function open() {
  if (connection) return connection

  connection = new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('This browser has no IndexedDB, so nothing can be queued offline.'))

      return
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result

      if (!db.objectStoreNames.contains(STORE)) {
        const store = db.createObjectStore(STORE, { keyPath: 'id' })

        // Replay order is the order things were done in: flushing a meal edit
        // before the meal it edits loses data.
        store.createIndex('createdAt', 'createdAt')
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('The offline store could not be opened.'))

    // Another tab holding an older version open — rare, and silent unless said.
    request.onblocked = () => reject(new Error('Another copy of the app is open. Close it and try again.'))
  }).catch((error) => {
    // Do not memoise a failure: a private-mode window that later becomes a
    // normal one should get a working store, not the old error.
    connection = null

    throw error
  })

  return connection
}

function run(mode, work) {
  return open().then(
    (db) =>
      new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE, mode)
        const request = work(transaction.objectStore(STORE))

        transaction.onabort = () => reject(transaction.error ?? new Error('The offline store rejected the write.'))

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
      })
  )
}

/** Every queued action, oldest first. */
export async function all() {
  const records = await run('readonly', (store) => store.getAll())

  return (records ?? []).sort((a, b) => a.createdAt - b.createdAt)
}

export function put(record) {
  return run('readwrite', (store) => store.put(record))
}

export function remove(id) {
  return run('readwrite', (store) => store.delete(id))
}

export function count() {
  return run('readonly', (store) => store.count())
}

export function clear() {
  return run('readwrite', (store) => store.clear())
}
