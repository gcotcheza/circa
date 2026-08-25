<script setup>
import { computed } from 'vue'
import { num } from '../lib/format'

/**
 * Back-calculated expenditure — the number this app exists to produce.
 *
 * The gated state is not a placeholder: for the first fortnight it is the whole
 * card, saying what is missing and why it matters, with a bar that moves every time
 * a day is logged — "Collecting data" with a progress bar is a reason to log
 * dinner, "no data" is a reason to delete the app. The active state is a RANGE,
 * always: the width IS the finding, the regression's own error combined with how
 * uncertain the food log was, and a midpoint alone would be a formula's confidence
 * with none of a formula's excuses.
 */
const props = defineProps({
  tdee: { type: Object, required: true },
})

const isEstimate = computed(() => props.tdee.status === 'estimate')

const progress = computed(() => props.tdee.progress)

const daysPct = computed(() =>
  Math.min(100, (100 * progress.value.days) / Math.max(1, progress.value.daysNeeded)),
)

const weighInsPct = computed(() =>
  Math.min(100, (100 * progress.value.weighIns) / Math.max(1, progress.value.weighInsNeeded)),
)

const daysLeft = computed(() => Math.max(0, progress.value.daysNeeded - progress.value.days))
const weighInsLeft = computed(() =>
  Math.max(0, progress.value.weighInsNeeded - progress.value.weighIns),
)

function shortDate(iso) {
  if (!iso) return '—'

  return new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
  })
}

const windowLabel = computed(() => `${shortDate(props.tdee.window.start)} – ${shortDate(props.tdee.window.end)}`)

const computedAtLabel = computed(() => {
  if (!props.tdee.computedAt) return null

  const at = new Date(props.tdee.computedAt)
  const sameDay = at.toDateString() === new Date().toDateString()

  return sameDay
    ? at.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
    : at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
})

// Direction in the plainest words. `intakeVersusTdee` is signed intake − TDEE, so
// negative is eating under expenditure. "About" is not hedging: the gap between
// two estimates is itself an estimate, and this is the one sentence a reader might
// otherwise take for a measurement.
const balance = computed(() => {
  if (!isEstimate.value) return null

  const gap = props.tdee.intakeVersusTdee

  if (Math.abs(gap) < 50) {
    return { word: 'maintenance', tone: 'text-stone-500', text: 'about level with what you burn' }
  }

  return gap < 0
    ? { word: 'deficit', tone: 'text-teal-600 dark:text-teal-400', text: `about ${num(Math.abs(gap))} kcal under it` }
    : { word: 'surplus', tone: 'text-orange-600 dark:text-orange-400', text: `about ${num(gap)} kcal over it` }
})

/** "14 complete days and 8 weigh-ins" — whichever halves are still short. */
const needLabel = computed(() => {
  const parts = []

  if (daysLeft.value > 0) {
    parts.push(`${daysLeft.value} complete ${daysLeft.value === 1 ? 'day' : 'days'}`)
  }

  if (weighInsLeft.value > 0) {
    parts.push(`${weighInsLeft.value} ${weighInsLeft.value === 1 ? 'weigh-in' : 'weigh-ins'}`)
  }

  return parts.join(' and ')
})

const trend = computed(() => {
  const perWeek = props.tdee.slopeKgPerWeek

  if (perWeek === null || perWeek === undefined) return null
  if (Math.abs(perWeek) < 0.05) return 'holding steady'

  return `${perWeek < 0 ? 'falling' : 'rising'} ${num(Math.abs(perWeek), 2)} kg/week`
})
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="flex items-baseline justify-between">
      <h2 class="text-sm font-medium text-stone-500 dark:text-stone-400">
        Your energy expenditure
      </h2>
      <span class="text-[11px] text-stone-400">measured, not predicted</span>
    </div>

    <!-- ACTIVE: a range, the window it came from, and which way it points. -->
    <template v-if="isEstimate">
      <p class="tnum mt-1 text-2xl font-semibold">
        {{ num(tdee.estimate.min) }}–{{ num(tdee.estimate.max) }}
        <span class="text-sm font-normal text-stone-500">kcal/day</span>
      </p>

      <p class="mt-2 text-xs leading-relaxed text-stone-600 dark:text-stone-300">
        Fitted from
        <span class="tnum font-medium">{{ progress.days }}</span> complete-log days and
        <span class="tnum font-medium">{{ progress.weighIns }}</span> weigh-ins between
        {{ windowLabel }}, with the weight trend {{ trend }}.
      </p>

      <p class="mt-2 border-t border-stone-100 pt-2 text-xs leading-relaxed dark:border-stone-800">
        You averaged <span class="tnum font-medium">{{ num(tdee.intakeMean) }}</span> kcal —
        <span :class="balance.tone">{{ balance.text }}</span>, a {{ balance.word }}.
      </p>

      <p class="mt-2 text-[11px] text-stone-400">
        Range = the trend line's own error (±{{ num(tdee.slopeUncertaintyKcal) }} kcal) combined with
        how uncertain your food log is. Method {{ tdee.methodVersion }}<template v-if="computedAtLabel">, computed {{ computedAtLabel }}</template>.
      </p>
    </template>

    <!-- GATED: not a placeholder. This is the card for the first fortnight. -->
    <template v-else>
      <p class="mt-1 text-lg font-semibold">Collecting data</p>

      <p class="mt-1 text-xs leading-relaxed text-stone-600 dark:text-stone-300">
        Most apps guess your burn from your height and age. This one solves for it: what you ate,
        against what the scale actually did. {{ needLabel }} to go, and it becomes a number fitted
        to you.
      </p>

      <div class="mt-3 space-y-3">
        <div>
          <div class="flex items-baseline justify-between text-xs">
            <span class="font-medium">Complete food logs</span>
            <span class="tnum text-stone-500 dark:text-stone-400">
              {{ progress.days }} / {{ progress.daysNeeded }}
            </span>
          </div>
          <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-stone-100 dark:bg-stone-800">
            <div class="h-full rounded-full bg-teal-500 transition-all" :style="{ width: `${daysPct}%` }" />
          </div>
          <p class="mt-1 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
            A day counts when it has at least {{ tdee.completeLogRule.minItems }} items, the last one
            at or after {{ tdee.completeLogRule.lastMealAfter }}, and at least
            {{ num(tdee.completeLogRule.kcalFloor) }} kcal — a breakfast-only day is missing lunch,
            not eating 400 kcal, and averaging it in would push this estimate hundreds of kcal low.
          </p>
        </div>

        <div>
          <div class="flex items-baseline justify-between text-xs">
            <span class="font-medium">Weigh-ins in this window</span>
            <span class="tnum text-stone-500 dark:text-stone-400">
              {{ progress.weighIns }} / {{ progress.weighInsNeeded }}
            </span>
          </div>
          <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-stone-100 dark:bg-stone-800">
            <div class="h-full rounded-full bg-sky-500 transition-all" :style="{ width: `${weighInsPct}%` }" />
          </div>
          <p class="mt-1 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
            Weight change is the other half of the equation: every kg is about
            {{ num(tdee.kcalPerKg) }} kcal. Daily weight swings ±1 kg on water alone, so the slope is
            fitted through the smoothed line — which needs points spread across the window, not two
            at the ends.
          </p>
        </div>
      </div>

      <p class="mt-3 text-[11px] text-stone-400">Window: {{ windowLabel }} · nothing is estimated until both bars are full.</p>
    </template>
  </section>
</template>
