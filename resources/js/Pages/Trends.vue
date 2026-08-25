<script setup>
import { computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import EnergyChart from '../Components/EnergyChart.vue'
import WeightChart from '../Components/WeightChart.vue'
import StepsChart from '../Components/StepsChart.vue'
import TdeeCard from '../Components/TdeeCard.vue'
import { band, num } from '../lib/format'

/**
 * The weekly (and four-weekly) picture: three charts, no dashboard — anything
 * else would be a number nobody acts on. The TDEE card moves, top once it has a
 * number and bottom while still collecting; the argument is in the template.
 */
const props = defineProps({
  range: Number,
  ranges: Array,
  emaAlpha: Number,
  start: String,
  end: String,
  days: Array,
  // Every weigh-in ever, with body fat and muscle mass, and its own range chips.
  // Does NOT follow the day buttons above — see TrendController for why.
  body: Object,
  // The estimator's own window (up to 28 days), NOT the range buttons — a slope
  // fitted over seven days of a ±1 kg scale is noise.
  tdee: Object,
  // Adherence over the SELECTED range: {days, complete, counted}, or null. Unlike
  // `tdee` it follows the range buttons — the question is about the window chosen.
  supplements: Object,
  // The energy chart's caption, from BalanceTally; follows the range buttons like
  // the chart. `sentence` is null when too few days are fully logged.
  balanceTally: Object,
})

function setRange(range) {
  router.get('/trends', { range }, { preserveScroll: true, preserveState: false })
}

/** Averages over the days that HAVE the number, not the window: dividing a week's
 * intake by seven when three days were never logged would report a starvation
 * diet. The count is shown beside each average, so the denominator is visible. */
const intakeAverage = computed(() => {
  const logged = props.days.filter((d) => d.kcalIn.mid !== null)

  if (logged.length === 0) return null

  const average = (key) => logged.reduce((sum, d) => sum + Number(d.kcalIn[key]), 0) / logged.length

  return { days: logged.length, min: average('min'), mid: average('mid'), max: average('max') }
})

const burnAverage = computed(() => {
  const known = props.days.filter((d) => d.kcalOut !== null)

  if (known.length === 0) return null

  return {
    days: known.length,
    value: known.reduce((sum, d) => sum + Number(d.kcalOut), 0) / known.length,
  }
})

const completeLogDays = computed(() => props.days.filter((d) => d.isCompleteLog).length)

/** Whether the TDEE card has a number, and therefore WHERE it sits. The same
 * `status` the card branches on (TdeeCard.vue), read one level up because here
 * it is a layout question too — no extra prop, no second query. */
const hasTdeeEstimate = computed(() => props.tdee?.status === 'estimate')
</script>

<template>
  <Head title="Trends" />

  <AppLayout title="Trends">
    <div class="mb-4 inline-flex rounded-xl bg-stone-200/70 p-0.5 text-sm font-medium dark:bg-stone-800">
      <button
        v-for="option in ranges"
        :key="option"
        type="button"
        class="rounded-lg px-3.5 py-1.5"
        :class="option === range ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
        @click="setRange(option)"
      >
        {{ option }} days
      </button>
    </div>

    <div class="space-y-4">
      <section class="grid grid-cols-2 gap-2">
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] text-stone-500 dark:text-stone-400">Average eaten</p>
          <p class="tnum text-base font-semibold">
            {{ intakeAverage ? band(intakeAverage) : '—' }}
          </p>
          <p class="text-[11px] text-stone-400">
            over {{ intakeAverage?.days ?? 0 }} logged day{{ intakeAverage?.days === 1 ? '' : 's' }}
          </p>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] text-stone-500 dark:text-stone-400">Average burned</p>
          <p class="tnum text-base font-semibold">
            {{ burnAverage ? num(burnAverage.value) : '—' }}
          </p>
          <p class="text-[11px] text-stone-400">
            over {{ burnAverage?.days ?? 0 }} measured day{{ burnAverage?.days === 1 ? '' : 's' }}
          </p>
        </div>
      </section>

      <!-- ===================================================================
           THE TDEE CARD'S POSITION IS ITS STATE — one of two places, never both.
           TOP once there is a number: an estimate is the point of the app and on
           the day it appears it is the news, so it goes above the charts feeding
           it. BOTTOM while still collecting, because in that state it is a
           progress meter that was opening the page with a paragraph about there
           being nothing to see, and whoever taps Trends came for the lines. It
           keeps its full height either way.
           =================================================================== -->
      <TdeeCard v-if="hasTdeeEstimate" :tdee="tdee" />

      <EnergyChart :days="days" :tally="balanceTally" />
      <WeightChart :body="body" />
      <StepsChart :days="days" />

      <!-- The selected range's completeness — a different question from the
           estimator's window. -->
      <p class="px-1 text-xs text-stone-500 dark:text-stone-400">
        {{ completeLogDays }} of {{ days.length }} days in this {{ range }}-day view have a complete
        food log.
      </p>

      <!-- No chart: a 7-point bar chart of a boolean would be decoration.
           "Evenings" rather than "days" because that is when they are taken. -->
      <p v-if="supplements" class="px-1 text-xs text-stone-500 dark:text-stone-400">
        Supplements:
        <span class="tnum font-medium text-stone-700 dark:text-stone-200">
          {{ supplements.complete }}/{{ supplements.counted }}
        </span>
        evening{{ supplements.counted === 1 ? '' : 's' }} complete
        <span v-if="supplements.counted < supplements.days" class="opacity-70">
          (of the {{ supplements.counted }} day{{ supplements.counted === 1 ? '' : 's' }} you have had them)
        </span>.
      </p>

      <!-- The other half of the block above: still collecting, so it reads last. -->
      <TdeeCard v-if="!hasTdeeEstimate" :tdee="tdee" />
    </div>
  </AppLayout>
</template>
