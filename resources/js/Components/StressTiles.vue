<script setup>
import { computed } from 'vue'
import { formatShortDate, signed } from '../lib/stress'

/**
 * Two numbers in their own units, beside the score.
 *
 * The score is a z-score in disguise, because "how unusual is this for me" is the
 * daily question — but a unitless scale cannot be checked from outside; there is
 * no way to look at 62 and test it against anything. HRV in milliseconds and
 * resting heart rate in bpm are what the Watch itself shows, so they tie the score
 * to something the reader can see elsewhere.
 *
 * NO VERDICTS: the app being replaced labels these same tiles "Normal" or "Great"
 * against a range for your age and sex, and no such range exists anywhere in this
 * feature. Each tile carries its own denominator instead — days with data, the
 * reading's date, days behind the median — because a number whose coverage is on
 * screen can be argued with, and one with a one-word verdict cannot.
 */
const props = defineProps({
  hrv7: { type: Object, required: true },
  restingHr: { type: Object, required: true },
  todayDate: { type: String, required: true },
})

/** "+8.6% vs the 7 days before", or the reason there is no comparison. */
const hrvChange = computed(() => {
  const { percent, currentDays, previousDays, minDays } = props.hrv7

  if (percent !== null) {
    return { text: `${signed(percent, 1)}% vs the 7 days before`, muted: false }
  }

  if (currentDays === 0) return { text: 'no readings in the last 7 days', muted: true }

  return {
    text: `not enough days to compare (${previousDays} of the 7 before, ${minDays} needed)`,
    muted: true,
  }
})

const restingDelta = computed(() => {
  const { delta, previousDate } = props.restingHr

  if (delta === null || !previousDate) return null

  return `${signed(delta)} vs ${formatShortDate(previousDate)}`
})

/** Only said when it is not today — a stale tile must look stale. */
const restingAsOf = computed(() =>
  props.restingHr.latestDate && props.restingHr.latestDate !== props.todayDate
    ? `as of ${formatShortDate(props.restingHr.latestDate)}`
    : null
)
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <!-- HRV, seven days ------------------------------------------------- -->
    <section class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <h3 class="text-[11px] font-medium text-stone-500 dark:text-stone-400">HRV · last 7 days</h3>

      <p v-if="hrv7.currentMs !== null" class="tnum mt-1 text-2xl font-semibold leading-none">
        {{ hrv7.currentMs }}<span class="ml-1 text-xs font-normal text-stone-400">ms</span>
      </p>
      <p v-else class="mt-1 text-lg font-semibold leading-none text-stone-400 dark:text-stone-500">—</p>

      <p
        class="tnum mt-1.5 text-[11px] leading-tight"
        :class="hrvChange.muted
          ? 'text-stone-400 dark:text-stone-500'
          : 'text-stone-600 dark:text-stone-300'"
      >
        {{ hrvChange.text }}
      </p>

      <!-- The denominator, always. -->
      <p class="tnum mt-1 text-[10px] text-stone-400 dark:text-stone-500">
        {{ hrv7.currentDays }} of 7 days with data
      </p>
    </section>

    <!-- Resting heart rate ------------------------------------------------ -->
    <section class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <h3 class="text-[11px] font-medium text-stone-500 dark:text-stone-400">Resting heart rate</h3>

      <template v-if="restingHr.latestBpm !== null">
        <p class="tnum mt-1 text-2xl font-semibold leading-none">
          {{ restingHr.latestBpm }}<span class="ml-1 text-xs font-normal text-stone-400">bpm</span>
        </p>

        <p v-if="restingDelta" class="tnum mt-1.5 text-[11px] leading-tight text-stone-600 dark:text-stone-300">
          {{ restingDelta }}
        </p>
        <p v-else class="mt-1.5 text-[11px] leading-tight text-stone-400 dark:text-stone-500">
          no earlier reading to compare with
        </p>

        <p class="tnum mt-1 text-[10px] text-stone-400 dark:text-stone-500">
          <template v-if="restingAsOf">{{ restingAsOf }} · </template>
          <!-- Your own middle, over a stated window. Never a population range. -->
          your {{ restingHr.windowDays }}-day median {{ restingHr.medianBpm }}
        </p>
      </template>

      <template v-else>
        <p class="mt-1 text-lg font-semibold leading-none text-stone-400 dark:text-stone-500">—</p>
        <p class="mt-1.5 text-[11px] leading-tight text-stone-400 dark:text-stone-500">
          no reading in the last {{ restingHr.windowDays }} days
        </p>
      </template>
    </section>
  </div>
</template>
