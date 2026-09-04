import assert from 'node:assert/strict'
import { test } from 'node:test'

/* iOS closes an idle IndexedDB connection while the PWA sleeps overnight —
 * usually without firing `close` — and the app woke to a banner instead of a
 * working offline queue (2026-09-04). These tests fake that death both ways. */

function fakeIndexedDb() {
  const state = { opens: 0, dbs: [], deadOnArrival: false }

  state.handle = {
    open() {
      state.opens += 1

      const db = {
        dead: state.deadOnArrival,
        throws: null,
        transactions: 0,
        onclose: null,
        objectStoreNames: { contains: () => true },
        close() {},
        transaction() {
          db.transactions += 1

          const failure = db.dead ? 'InvalidStateError' : db.throws

          if (failure) {
            const error = new Error(`transaction() refused: ${failure}`)
            error.name = failure
            throw error
          }

          const transaction = { onabort: null }

          transaction.objectStore = () => ({
            count() {
              const request = { onsuccess: null, onerror: null, result: 7 }

              queueMicrotask(() => request.onsuccess?.())

              return request
            },
          })

          return transaction
        },
      }

      state.dbs.push(db)

      const request = { onupgradeneeded: null, onsuccess: null, onerror: null, onblocked: null, result: db }

      queueMicrotask(() => request.onsuccess?.())

      return request
    },
  }

  return state
}

let instance = 0

/** A fresh copy of the module per test: its memoised connection is the thing under test. */
function freshIdb() {
  instance += 1

  return import(`../../resources/js/lib/idb.js?instance=${instance}`)
}

test('a handle the browser killed without a close event is retried once, reopened', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)
    assert.equal(fake.opens, 1)

    fake.dbs[0].dead = true

    assert.equal(await idb.count(), 7)
    assert.equal(fake.opens, 2)
    assert.equal(fake.dbs[0].transactions, 2)
  } finally {
    delete globalThis.indexedDB
  }
})

test('a close event forgets the handle before anything trips over it', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)

    fake.dbs[0].dead = true
    fake.dbs[0].onclose?.()

    assert.equal(await idb.count(), 7)
    assert.equal(fake.opens, 2)
    assert.equal(fake.dbs[0].transactions, 1)
  } finally {
    delete globalThis.indexedDB
  }
})

test('a late close event from the old handle leaves the fresh connection alone', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)

    fake.dbs[0].dead = true

    assert.equal(await idb.count(), 7)
    assert.equal(fake.opens, 2)

    fake.dbs[0].onclose?.()

    assert.equal(await idb.count(), 7)
    assert.equal(fake.opens, 2)
    assert.equal(fake.dbs[1].transactions, 2)
  } finally {
    delete globalThis.indexedDB
  }
})

test('concurrent callers over one dead handle reopen once between them', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)

    fake.dbs[0].dead = true

    assert.deepEqual(await Promise.all([idb.count(), idb.count(), idb.count()]), [7, 7, 7])
    assert.equal(fake.opens, 2)
  } finally {
    delete globalThis.indexedDB
  }
})

test('an error that is not a closing connection is not retried', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)

    fake.dbs[0].throws = 'QuotaExceededError'

    await assert.rejects(idb.count(), (error) => error.name === 'QuotaExceededError')
    assert.equal(fake.opens, 1)
  } finally {
    delete globalThis.indexedDB
  }
})

test('a store that is dead on every connection stays loud and is retried exactly once', async () => {
  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    const idb = await freshIdb()

    assert.equal(await idb.count(), 7)

    fake.dbs[0].dead = true
    fake.deadOnArrival = true

    await assert.rejects(idb.count(), (error) => error.name === 'InvalidStateError')
    assert.equal(fake.opens, 2)
  } finally {
    delete globalThis.indexedDB
  }
})

test('a failed open is not remembered: the store works once IndexedDB exists', async () => {
  delete globalThis.indexedDB

  const idb = await freshIdb()

  await assert.rejects(idb.count(), /nothing can be queued offline/)

  const fake = fakeIndexedDb()
  globalThis.indexedDB = fake.handle

  try {
    assert.equal(await idb.count(), 7)
  } finally {
    delete globalThis.indexedDB
  }
})
