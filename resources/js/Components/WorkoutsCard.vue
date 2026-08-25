<script setup>
import { computed } from 'vue'
import { duration, num } from '../lib/format'
import HonestyChip from './HonestyChip.vue'

/**
 * What was trained on this day, one row per session.
 *
 * THE KCAL HERE ARE NOT ADDED TO ANYTHING: a session's active energy is ALREADY in
 * the day's active kcal in the strip above, because the Watch records it whether or
 * not a workout is running — pressing start puts a NAME on a stretch of time, it
 * does not create calories. So this card lists and never totals; adding these to
 * the day's burn would double-count every run in the database.
 *
 * Built on the confirmed-meal card's shape rather than a new one: one idiom for
 * "a thing that happened at a time today".
 */
const props = defineProps({
  workouts: { type: Array, required: true },
})

const sessions = computed(() =>
  props.workouts.map((workout) => ({
    ...workout,
    // Each of these is absent on a large fraction of real sessions (climbing has no
    // distance), so the line is assembled from what exists, not six em dashes.
    meta: [
      workout.distanceKm === null ? null : `${num(workout.distanceKm, 2)} km`,
      workout.activeKcal === null ? null : `${num(workout.activeKcal)} kcal`,
      workout.avgHr === null ? null : `${workout.avgHr} bpm avg`,
      workout.maxHr === null ? null : `${workout.maxHr} max`,
    ].filter((part) => part !== null),
  }))
)
</script>

<template>
  <section class="space-y-2">
    <h2 class="text-sm font-semibold">Training</h2>

    <article
      v-for="workout in sessions"
      :key="workout.id"
      class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
    >
      <div class="flex items-baseline justify-between gap-3">
        <div class="min-w-0">
          <p class="truncate text-sm font-medium">{{ workout.type }}</p>
          <p class="text-xs text-stone-500 dark:text-stone-400">
            {{ workout.start }}–{{ workout.end }}
          </p>
        </div>

        <span class="tnum shrink-0 text-lg font-semibold">
          {{ duration(workout.durationMinutes) }}
        </span>
      </div>

      <p
        v-if="workout.meta.length > 0"
        class="tnum mt-2 flex flex-wrap gap-x-2 gap-y-1 text-[11px] text-stone-500 dark:text-stone-400"
      >
        <span v-for="(part, index) in workout.meta" :key="index">
          <span v-if="index > 0" class="mr-2">·</span>{{ part }}
        </span>
      </p>

      <!-- The forgotten timer, said out loud: the row stays because the export is
           the record and a vanished session would be unexplainable, and the chip is
           the same flag that keeps it out of every training total. -->
      <p v-if="workout.isImplausible" class="mt-2">
        <HonestyChip tone="warn" label="Timer looks like it was left running — not counted in training totals" />
      </p>
    </article>
  </section>
</template>
