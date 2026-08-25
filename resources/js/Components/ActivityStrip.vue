<script setup>
import { num } from '../lib/format'

/**
 * The four movement numbers in one row. Each comes from BucketSelector, never a
 * SUM: Watch, iPhone and HAE's "Watch|iPhone" composite all report the same day
 * at once, so adding them roughly doubles the number.
 */
defineProps({
  summary: { type: Object, required: true },
})
</script>

<template>
  <section class="grid grid-cols-4 gap-2">
    <div class="rounded-xl border border-stone-200 bg-white px-2 py-2.5 text-center dark:border-stone-800 dark:bg-stone-900">
      <p class="tnum text-base font-semibold">{{ num(summary.steps) }}</p>
      <p class="text-[11px] text-stone-500 dark:text-stone-400">steps</p>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white px-2 py-2.5 text-center dark:border-stone-800 dark:bg-stone-900">
      <p class="tnum text-base font-semibold">{{ num(summary.activeKcal) }}</p>
      <p class="text-[11px] text-stone-500 dark:text-stone-400">active kcal</p>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white px-2 py-2.5 text-center dark:border-stone-800 dark:bg-stone-900">
      <p class="tnum text-base font-semibold">{{ num(summary.exerciseMinutes) }}</p>
      <p class="text-[11px] text-stone-500 dark:text-stone-400">exercise min</p>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white px-2 py-2.5 text-center dark:border-stone-800 dark:bg-stone-900">
      <p class="tnum text-base font-semibold">{{ num(summary.distanceKm, 1) }}</p>
      <p class="text-[11px] text-stone-500 dark:text-stone-400">km</p>
    </div>
  </section>
</template>
