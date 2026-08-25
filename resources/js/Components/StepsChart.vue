<script setup>
import { computed } from 'vue'
import { num } from '../lib/format'

/**
 * Steps per day: a priority selection, never a sum — Watch, iPhone and HAE's
 * "Watch|iPhone" composite all report the same hours, so a sum roughly doubles.
 */
const props = defineProps({
  days: { type: Array, required: true },
})

const W = 320
const H = 80
const PAD_L = 26
const PAD_B = 12
const PAD_T = 4

const max = computed(() => {
  const peak = Math.max(...props.days.map((d) => d.steps ?? 0), 0)

  return Math.max(2000, Math.ceil(peak / 2000) * 2000)
})

const step = computed(() => (W - PAD_L) / props.days.length)

const y = (value) => H - PAD_B - ((value ?? 0) / max.value) * (H - PAD_B - PAD_T)

const bars = computed(() =>
  props.days.map((day, index) => {
    const width = Math.max(2, step.value * 0.72)

    return {
      key: day.date,
      x: PAD_L + index * step.value + (step.value - width) / 2,
      width,
      y: y(day.steps),
      height: Math.max(0, H - PAD_B - y(day.steps)),
      steps: day.steps,
    }
  })
)

const average = computed(() => {
  const logged = props.days.filter((d) => d.steps !== null)

  if (logged.length === 0) return null

  return logged.reduce((sum, d) => sum + Number(d.steps), 0) / logged.length
})
</script>

<template>
  <figure class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
    <figcaption class="mb-2 flex items-center justify-between">
      <h3 class="text-sm font-semibold">Steps</h3>
      <span v-if="average !== null" class="tnum text-[11px] text-stone-500 dark:text-stone-400">
        avg {{ num(average) }}/day
      </span>
    </figcaption>

    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Steps per day">
      <line :x1="PAD_L" :x2="W" :y1="y(0)" :y2="y(0)" class="stroke-stone-300 dark:stroke-stone-700" stroke-width="0.5" />
      <line :x1="PAD_L" :x2="W" :y1="y(max)" :y2="y(max)" class="stroke-stone-300 dark:stroke-stone-700" stroke-width="0.5" />

      <text :x="0" :y="y(max) + 2.5" class="fill-stone-400 text-[7px] dark:fill-stone-500">{{ num(max) }}</text>

      <rect
        v-for="bar in bars"
        :key="bar.key"
        :x="bar.x" :y="bar.y" :width="bar.width" :height="bar.height"
        rx="1"
        class="fill-sky-500"
      />
    </svg>
  </figure>
</template>
