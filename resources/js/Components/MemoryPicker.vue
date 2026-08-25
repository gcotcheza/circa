<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { band } from '../lib/format'
import { searchMemory } from '../lib/memory'
import { KIND, submitOrQueue } from '../lib/queue'

/**
 * "Log it again" — the everyday path, and the one that gets better with use.
 *
 * TWO LISTS, ONE A FALLBACK. `frequentMeals` is `meal_memory` ranked by frecency
 * (times_logged weighted by how recently the shape was eaten) and wins as soon as
 * anything has been logged. `recentMeals`, derived on the fly from the last few
 * confirmed meals, stays because memory starts EMPTY on a fresh deploy: seeding it
 * would be inventing somebody's history, and an empty row for the first week would
 * regress a feature that already worked. They post to different endpoints on
 * purpose — a remembered meal is logged from `meal_memory_items` (the averaged
 * habit), a recent one is copied from the meal row it came from.
 *
 * SEARCH IS OPT-IN: the box appears only once the row is hiding something, since
 * searching four meals is more work than looking at them. It debounces and aborts
 * the previous request — "chick" is worthless once "chicken" has been typed.
 */
const props = defineProps({
  date: { type: String, required: true },
  frequentMeals: { type: Array, default: () => [] },
  recentMeals: { type: Array, default: () => [] },
})

const query = ref('')
const results = ref(null)
const searching = ref(false)
const error = ref(null)
const logError = ref(null)

let debounce = null
let inFlight = null

// The search box earns its space only when the row cannot show everything.
const canSearch = computed(() => props.frequentMeals.length >= 5)

const usingMemory = computed(() => props.frequentMeals.length > 0)

const shown = computed(() => {
  if (results.value !== null) return results.value

  return usingMemory.value ? props.frequentMeals : props.recentMeals
})

watch(query, (term) => {
  error.value = null

  if (debounce !== null) clearTimeout(debounce)
  if (inFlight !== null) inFlight.abort()

  if (term.trim() === '') {
    results.value = null
    searching.value = false

    return
  }

  searching.value = true

  // 220 ms: one request per word typed at speed, still following the keyboard.
  debounce = setTimeout(async () => {
    inFlight = new AbortController()

    const result = await searchMemory(term, { signal: inFlight.signal })

    if (result.aborted) return

    searching.value = false

    if (!result.ok) {
      error.value = result.message

      return
    }

    results.value = result.memories
  }, 220)
})

function nowTime() {
  const d = new Date()

  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/**
 * One tap logs it onto the day being viewed. The uuid is generated before the POST,
 * as in the meal sheet: this request can be retried, and a retry must land on the
 * same new meal rather than a second copy. It goes through the offline queue
 * because this is the tap most likely to happen with no signal — the "same lunch as
 * always" button, pressed in a canteen — and the uuid is what makes replaying it
 * safe. The label and kcal band ride along as `meta` so the queued card can say
 * WHAT is waiting rather than "1 item"; they are display data, deliberately not
 * part of the request body the server reads.
 */
async function log(entry) {
  const url = usingMemory.value || results.value !== null
    ? `/meal-memory/${entry.id}/log`
    : `/meals/${entry.uuid}/repeat`

  const uuid = crypto.randomUUID()

  logError.value = null

  const result = await submitOrQueue({
    id: uuid,
    kind: usingMemory.value || results.value !== null ? KIND.memoryLog : KIND.mealRepeat,
    url,
    method: 'POST',
    payload: { uuid, date: props.date, time: nowTime() },
    date: props.date,
    meta: { label: entry.label, time: nowTime(), kcal: entry.kcal?.mid ?? null },
  })

  if (result.ok) {
    if (!result.queued) router.reload({ preserveScroll: true })

    return
  }

  logError.value = result.message ?? 'That could not be logged.'
}

function keyFor(entry) {
  return entry.id ?? entry.uuid
}
</script>

<template>
  <section v-if="shown.length || canSearch" class="space-y-2">
    <div class="flex items-baseline justify-between">
      <h2 class="text-sm font-semibold">{{ usingMemory ? 'Log again' : 'Recent' }}</h2>
      <span v-if="searching" class="text-xs text-stone-500">searching…</span>
    </div>

    <input
      v-if="canSearch"
      v-model="query"
      type="search"
      inputmode="search"
      placeholder="Search your meals"
      class="w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-base outline-none
             focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950"
    >

    <p v-if="logError" class="rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
      {{ logError }}
    </p>

    <p v-if="error" class="rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
      {{ error }}
    </p>

    <p
      v-else-if="results !== null && results.length === 0 && !searching"
      class="rounded-xl border border-dashed border-stone-300 px-3 py-4 text-center text-sm
             text-stone-500 dark:border-stone-700 dark:text-stone-400"
    >
      Nothing in your history matches “{{ query }}”.
    </p>

    <!-- Horizontal scroll, not a wrapped grid: it keeps the page's vertical rhythm
         and matches the use — scan three, tap one. Search results are read, so wrap. -->
    <div
      v-else
      class="-mx-4 px-4 pb-1"
      :class="results === null ? 'flex gap-2 overflow-x-auto' : 'grid grid-cols-2 gap-2'"
    >
      <button
        v-for="entry in shown"
        :key="keyFor(entry)"
        type="button"
        class="shrink-0 rounded-xl border border-stone-200 bg-white p-2.5 text-left
               active:scale-[0.97] dark:border-stone-800 dark:bg-stone-900"
        :class="results === null ? 'w-40' : 'w-full'"
        @click="log(entry)"
      >
        <p class="truncate text-xs font-medium">{{ entry.label }}</p>
        <p class="tnum mt-1 text-xs text-stone-500 dark:text-stone-400">
          {{ band(entry.kcal) }} kcal · {{ entry.itemCount }} item{{ entry.itemCount === 1 ? '' : 's' }}
        </p>
        <!-- The count is what makes the order the row is drawn in legible. -->
        <p v-if="entry.timesLogged" class="tnum mt-0.5 text-[11px] text-teal-700 dark:text-teal-400">
          logged {{ entry.timesLogged }}×
        </p>
      </button>
    </div>
  </section>
</template>
