/**
 * The meal-memory search call. Same discriminated-result shape as
 * lib/products.js, for the same reason: "no connection", "session expired" and
 * "nothing matched" are three different things for the user to do next.
 */

/**
 * @returns {Promise<{ok: boolean, memories: Array, message?: string}>}
 */
export async function searchMemory(query, { signal } = {}) {
  let response

  try {
    response = await fetch(`/api/meal-memory?q=${encodeURIComponent(query ?? '')}`, {
      headers: { Accept: 'application/json' },
      // Session-authenticated; without the cookie it is a 401.
      credentials: 'same-origin',
      signal,
    })
  } catch (error) {
    // An aborted request is a keystroke, not a failure: reporting it would
    // flash an error every time somebody types quickly.
    if (error?.name === 'AbortError') return { ok: false, memories: [], aborted: true }

    return { ok: false, memories: [], message: 'No connection.' }
  }

  if (response.status === 401) {
    return { ok: false, memories: [], message: 'Session expired. Reload the page.' }
  }

  if (response.status === 429) {
    return { ok: false, memories: [], message: 'Searching too fast. Wait a moment.' }
  }

  const body = await response.json().catch(() => null)

  if (!response.ok || !body) return { ok: false, memories: [], message: 'The search failed.' }

  return { ok: true, memories: body.memories ?? [] }
}
