<script setup>
import { computed } from 'vue'
import { bandInk, bandSwatchStyle, coverageLabel, reasonLabel } from '../lib/stress'

/**
 * Today's score, and everything that qualifies it.
 *
 * It never shows a number without its coverage. The commercial app being replaced
 * draws the same confident dial whether it saw two readings or forty, which is the
 * one thing about it that is not merely imprecise but misleading — a morning the
 * Watch was on the charger looks exactly like a calm morning. So there are three
 * states and only one is a number: SCORE (band, score, what it is measured
 * against, how much data), NO SCORE (the reason in a sentence, with whatever
 * readings there were), THIN (a score plus a visible "low confidence" badge —
 * shown and labelled, not hidden or silently downgraded).
 */
const props = defineProps({
  today: { type: Object, required: true },
  yesterday: { type: Object, default: null },
  baseline: { type: Object, required: true },
  bands: { type: Array, required: true },
})

const band = computed(() => props.bands.find((b) => b.band === props.today.band) ?? null)

const delta = computed(() => {
  if (props.today.score === null || !props.yesterday || props.yesterday.score === null) return null

  return props.today.score - props.yesterday.score
})

/** "3% above your usual" — the finding in its own unit, for a reader who checks rather than takes. */
const versusBaseline = computed(() => {
  if (props.today.hrvMs === null || props.today.baselineHrvMs === null) return null

  const ratio = props.today.hrvMs / props.today.baselineHrvMs
  const pct = Math.round((ratio - 1) * 100)

  if (pct === 0) return 'right on your usual level'

  return `${Math.abs(pct)}% ${pct > 0 ? 'above' : 'below'} your usual level`
})
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <h2 class="text-sm font-medium text-stone-500 dark:text-stone-400">Today</h2>

    <!-- SCORED -->
    <template v-if="today.score !== null">
      <div class="mt-2 flex items-center gap-3">
        <span
          class="size-3 shrink-0 rounded-full"
          :style="bandSwatchStyle(today.band)"
          aria-hidden="true"
        />
        <p class="tnum text-4xl font-semibold leading-none">{{ today.score }}</p>
        <div class="min-w-0">
          <p class="text-sm font-semibold" :style="{ color: bandInk(today.band) }">
            {{ today.bandLabel }}
          </p>
          <p v-if="delta !== null" class="tnum text-[11px] text-stone-500 dark:text-stone-400">
            {{ delta > 0 ? '+' : '' }}{{ delta }} vs yesterday
          </p>
        </div>
      </div>

      <p v-if="band" class="mt-2 text-xs leading-relaxed text-stone-600 dark:text-stone-300">
        {{ band.meaning }}
      </p>

      <p class="tnum mt-2 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        HRV {{ today.hrvMs }} ms against a {{ today.baselineDays }}-day baseline of
        {{ today.baselineHrvMs }} ms<span v-if="versusBaseline"> — {{ versusBaseline }}</span>.
      </p>
    </template>

    <!-- NOT SCORED. A reason, never a blank dial. -->
    <template v-else>
      <p class="mt-2 text-2xl font-semibold text-stone-400 dark:text-stone-500">Not enough data</p>
      <p class="mt-1 text-xs leading-relaxed text-stone-600 dark:text-stone-300">
        {{ reasonLabel(today.reason, baseline.minDaySamples) }}
      </p>
      <p
        v-if="today.reason === 'no_baseline'"
        class="mt-1 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400"
      >
        A score needs {{ baseline.minDays }} days of your own history to compare against; the
        baseline then rolls over the last {{ baseline.days }} days.
      </p>
    </template>

    <!-- Coverage, on every state, always. -->
    <div class="mt-3 flex flex-wrap items-center gap-1.5">
      <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium leading-4"
        :class="today.confidence === 'low' || today.confidence === 'none'
          ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
          : 'bg-stone-200/70 text-stone-600 dark:bg-stone-800 dark:text-stone-400'"
      >
        {{ coverageLabel(today) }}
      </span>

      <span
        v-if="today.score !== null && today.confidence === 'low'"
        class="text-[11px] text-stone-500 dark:text-stone-400"
      >
        thin day — treat the number loosely
      </span>
    </div>
  </section>
</template>
