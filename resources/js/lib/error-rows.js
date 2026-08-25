/**
 * Which row a refusal is about, said in front of the refusal.
 *
 * Both meal sheets render every sentence — the browser's on blur and the
 * server's after a submit — in ONE list at the foot of the sheet rather than
 * under the box it is about. A sentence that names only the box therefore
 * leaves the reader to find the row themselves, and two rows failing the same
 * rule read identically: "The calories field must not be greater than 20000."
 * twice over, with nothing to say which plate line to look at.
 *
 * The row's own NAME is what a person recognises it by, so it goes first and a
 * number is only the fallback — for the blank row every sheet keeps open at the
 * end, and for a server refusal about a row that has since been removed. The
 * number counts from 1 because the rows on screen do, while the key counts
 * from 0.
 *
 * Display only. The sentence is the server's, unchanged and never rewritten,
 * and nothing here decides whether there is one.
 */

/** `items.2.kcal` — the shape every per-row key has, and the only one prefixed. */
const ROW = /^items\.(\d+)\./

export function errorLine(key, message, items) {
  const row = ROW.exec(String(key))

  if (row === null) return message

  const index = Number(row[1])
  const named = items?.[index]?.name

  const label = typeof named === 'string' && named.trim() !== ''
    ? named.trim()
    : `Item ${index + 1}`

  return `${label} — ${message}`
}
