<script setup>
import { computed } from 'vue'
import { num } from '../lib/format'
import { discard, retry } from '../lib/queue'

/**
 * A meal that is on this phone and not yet on the server.
 *
 * IT LOOKS DIFFERENT ON PURPOSE AND DOES NOT OPEN. It is NOT in the day's totals
 * (the balance above is built from confirmed meals in the database), so a card
 * looking like the others would make the arithmetic above it appear to be wrong.
 * Nor can it be edited: a PUT to /meals/{uuid} for a meal the server has never
 * heard of is a 404, and queueing an edit behind a create is a two-step replay
 * with an ordering bug waiting in it.
 *
 * The kcal figure is computed here from the queued payload rather than stored with
 * it — the same arithmetic MealRequest does server-side — because a second stored
 * copy of the total can disagree with the one the server works out on replay.
 */
const props = defineProps({
  action: { type: Object, required: true },
})

const blocked = computed(() => props.action.state === 'blocked')

const time = computed(() => props.action.payload?.time ?? props.action.meta?.time ?? '—')

const label = computed(() => {
  if (props.action.meta?.label) return props.action.meta.label

  const names = (props.action.payload?.items ?? [])
    .map((item) => item.name)
    .filter((name) => (name ?? '').trim() !== '')

  return names.length > 0 ? names.join(', ') : 'Meal'
})

const kcal = computed(() => {
  if (typeof props.action.meta?.kcal === 'number') return props.action.meta.kcal

  const items = props.action.payload?.items

  if (!Array.isArray(items) || items.length === 0) return null

  const total = items.reduce((sum, item) => {
    // The meal sheet's two entry modes: `per_100g` is density × weight, `absolute`
    // is already the portion's figure.
    const value =
      item.basis === 'per_100g'
        ? (Number(item.grams) || 0) * (Number(item.kcal_per_100g) || 0) / 100
        : Number(item.kcal) || 0

    return sum + value
  }, 0)

  return Math.round(total)
})

const kindLabels = {
  'meal.create': 'Queued — will sync',
  'meal.update': 'Edit queued — will sync',
  'meal.repeat': 'Queued — will sync',
  'memory.log': 'Queued — will sync',
  'meal.confirm': 'Confirmation queued',
  'photo.upload': 'Photo queued — will send',
  // The estimate cannot start until the meal reaches the server, so the day shows
  // a queued card, not an `analyzing` meal; the job runs server-side on arrival.
  'meal.estimate': 'Queued — will estimate on sync',
}
</script>

<template>
  <div
    class="w-full rounded-2xl border border-dashed p-3.5 text-left"
    :class="blocked
      ? 'border-rose-400/70 bg-rose-50/60 dark:border-rose-800 dark:bg-rose-950/30'
      : 'border-stone-300 bg-stone-100/60 dark:border-stone-700 dark:bg-stone-900/40'"
  >
    <div class="flex items-baseline justify-between gap-3">
      <div class="flex items-baseline gap-2">
        <span class="tnum text-sm font-semibold text-stone-600 dark:text-stone-300">{{ time }}</span>
        <span
          class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
          :class="blocked
            ? 'bg-rose-200 text-rose-900 dark:bg-rose-900 dark:text-rose-100'
            : 'bg-stone-200 text-stone-600 dark:bg-stone-800 dark:text-stone-300'"
        >
          {{ blocked ? 'Needs attention' : (kindLabels[action.kind] ?? 'Queued') }}
        </span>
      </div>

      <span v-if="kcal !== null" class="tnum shrink-0 text-sm font-semibold text-stone-500 dark:text-stone-400">
        {{ num(kcal) }}<span class="ml-1 text-xs font-normal">kcal</span>
      </span>
    </div>

    <p class="mt-2 truncate text-sm text-stone-600 dark:text-stone-300">{{ label }}</p>

    <!-- Not counted yet, said out loud: the balance card above is built from what the server has. -->
    <p v-if="!blocked" class="mt-1 text-xs text-stone-500 dark:text-stone-400">
      Not counted in today's totals until it sends.
    </p>

    <template v-else>
      <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ action.lastError }}</p>

      <div class="mt-2 flex gap-2">
        <button
          type="button"
          class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white active:scale-95"
          @click="retry(action.id)"
        >
          Try again
        </button>
        <button
          type="button"
          class="rounded-lg px-3 py-1.5 text-xs font-medium text-stone-500 active:scale-95 dark:text-stone-400"
          @click="discard(action.id)"
        >
          Discard
        </button>
      </div>
    </template>
  </div>
</template>
