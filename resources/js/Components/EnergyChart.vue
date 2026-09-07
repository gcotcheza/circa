<script setup>
import { computed } from 'vue'
import { bestEstimate, num } from '../lib/format'

/**
 * Intake against expenditure, per day: two bars, side by side, one baseline.
 *
 * ---------------------------------------------------------------------------
 * WHY IT WAS REDRAWN
 *
 * Intake used to be a narrow teal column drawn INSIDE the orange burn bar. The
 * owner, unprompted, said they did not understand what the graph was trying to
 * show — correctly: the card answers "did I eat more or less than I burned that
 * day", and that encoding asked the eye to compare a bar's top edge with a small
 * mark floating over it. Two bars from one baseline at one scale need no legend:
 * the taller one is the bigger number.
 *
 * NOT A BULLET BAR (intake slimmer, inside the burn track): it reads as progress
 * towards a target, and the moment intake exceeds burn the inner bar overflows
 * its own track — a rendering fault, not a surplus. Crossing that line in either
 * direction is the whole point here. It also stacks two translucent fills, which
 * is what made the old version illegible in dark mode.
 *
 * ---------------------------------------------------------------------------
 * WHY INTAKE STILL CARRIES A WHISKER
 *
 * A bar implies a measurement; intake is an estimate assembled from estimates,
 * and SPEC.md's "ranges everywhere" exists to stop a ±200 kcal guess being drawn
 * with the authority of a weighed figure. So the BAR is the midpoint, directly
 * comparable with the burn bar beside it, and min..max is a whisker over it. A
 * day whose items were all typed has no range and renders as a bare cap.
 *
 * A whisker rather than a second, paler fill is a dark-mode decision: "paler" is
 * lighter on white and darker on stone-900, so a tinted range needs two opposite
 * treatments and a theme to choose between them. A line in the series' own
 * colour needs neither.
 *
 * ---------------------------------------------------------------------------
 * THE THREE THINGS THAT ARE NOT A NUMBER
 *
 *   FADED       still accruing — partial Watch coverage on the burn side, an
 *               incomplete food log on the intake side. Both mean "a floor, not
 *               a value", the same claim, so the same treatment.
 *
 *   A DASH      under the axis, in the intake bar's own slot: nothing logged.
 *               The one state a bar chart cannot draw, because "no food logged"
 *               and "ate nothing" are the same picture — no bar — and opposite
 *               facts. The stress week chart says it the same way.
 *
 *   THE CAPTION which way the window came out, from BalanceTally over the days
 *               it is honest to count, server-side and deterministic. At 2 000
 *               kcal against 2 000 kcal a 150 kcal difference is five pixels
 *               tall: the bars answer "how much", the sentence "which way".
 *
 * Hand-rolled SVG rather than a chart library: it inherits `currentColor`, so
 * light and dark need no configuration, and there is no runtime dependency to
 * keep current.
 * ---------------------------------------------------------------------------
 */
const props = defineProps({
  days: { type: Array, required: true },
  /**
   * `{ days, surplus, deficit, balanced, sentence }` from BalanceTally, or null.
   * `sentence` alone is null when too few days are fully logged: the caption is
   * then absent rather than hedged.
   */
  tally: { type: Object, default: null },
})

const W = 320
const H = 122
const PAD_L = 26

/**
 * Deeper than the plot needs: two rows live down here, the no-food-logged dash
 * and the weekday labels. H grew with it, so the BASELINE is still 106 and the
 * plot keeps the height it always had — the card is two units taller and the
 * chart inside it did not shrink to pay for the row.
 */
const PAD_B = 16
const PAD_T = 6

const maxValue = computed(() => {
  const values = props.days.flatMap((d) => [d.kcalOut ?? 0, d.kcalIn.max ?? 0])

  // Round up to the next 500: readable gridline labels.
  return Math.max(500, Math.ceil(Math.max(...values, 0) / 500) * 500)
})

const step = computed(() => (W - PAD_L) / props.days.length)

const y = (value) => H - PAD_B - ((value ?? 0) / maxValue.value) * (H - PAD_B - PAD_T)

/**
 * Two bars in the space one used to have: the pair takes 78% of the column, as
 * the single bar did, so it still sits beside the steps chart unchanged. The gap
 * is a fraction of the column, not a constant — 2.5 units separates the pair at
 * seven days, but at twenty-eight, where a column is a quarter of the width, a
 * constant 2.5 would be most of the eaten bar.
 */
const geometry = computed(() => {
  const group = step.value * 0.78
  const gap = Math.min(2.5, step.value * 0.07)

  return { group, gap, bar: Math.max(1.2, (group - gap) / 2) }
})

const columns = computed(() =>
  props.days.map((day, index) => {
    const { group, gap, bar } = geometry.value

    const groupX = PAD_L + index * step.value + (step.value - group) / 2
    const eatX = groupX + bar + gap

    const hasIntake = day.kcalIn.mid !== null

    return {
      key: day.date,
      label: day.label,
      day,

      burnX: groupX,
      burnY: y(day.kcalOut),
      burnHeight: Math.max(0, H - PAD_B - y(day.kcalOut)),
      // A partial day's burn is a floor, not a value — now the intake bar's rule too.
      burnPartial: day.activeKcalIsPartial || !day.hasFullMetricCoverage,

      eatX,
      eatY: y(day.kcalIn.mid),
      eatHeight: Math.max(0, H - PAD_B - y(day.kcalIn.mid)),
      // Some meals logged is not all: an estimate versus a minimum.
      eatPartial: !day.isCompleteLog,

      width: bar,

      hasIntake,
      // Centred on the intake bar; absent when every item was typed, where
      // drawing a range would invent uncertainty the data does not have.
      whiskerX: eatX + bar / 2,
      whiskerTop: y(day.kcalIn.max),
      whiskerBottom: y(day.kcalIn.min),
      capWidth: Math.max(1, bar * 0.5),

      // Centred under the PAIR, not under either bar.
      centreX: groupX + group / 2,
      // The dash sits in the slot the intake bar would have held, so it reads
      // as that bar's absence.
      dashX: eatX + bar / 2,
    }
  })
)

const gridlines = computed(() => {
  const lines = []

  for (let value = 0; value <= maxValue.value; value += maxValue.value / 2) {
    lines.push({ value, y: y(value) })
  }

  return lines
})

/**
 * The whole day in one line, for the mark's `<title>`. The verdict is READ, not
 * recomputed: `day.balance` is the same EnergyBalance the day view's card
 * renders, so tapping through cannot find a different answer to the question
 * this chart just answered. BalanceLineTest holds the day card to the same rule.
 */
function summary(day) {
  const parts = [day.label]

  parts.push(day.kcalOut === null ? 'burn not measured' : `burned ${num(day.kcalOut)}`)

  const eaten = bestEstimate(day.kcalIn)

  parts.push(
    day.kcalIn.mid === null
      ? 'no food logged'
      : `eaten ${eaten.figure}${eaten.width ? ` (${eaten.width})` : ''}${day.isCompleteLog ? '' : ' so far'}`
  )

  if (day.balance) {
    const { direction, lo, hi } = day.balance
    const amount = lo === hi ? num(lo) : `${num(lo)}–${num(hi)}`

    parts.push(
      direction === 'balanced'
        ? 'too close to call'
        : `${direction === 'deficit' ? 'under' : 'over'} by ${amount} kcal`
    )
  }

  return parts.join(' · ')
}

/**
 * The key, and only the parts this window needs — the rule the stress chart's
 * training legend follows: explaining a mark that is not on screen teaches the
 * reader to skip legends.
 */
const hasWhisker = computed(() =>
  props.days.some((d) => d.kcalIn.mid !== null && Number(d.kcalIn.max) - Number(d.kcalIn.min) >= 1)
)

const hasFaded = computed(() =>
  props.days.some(
    (d) =>
      d.activeKcalIsPartial ||
      !d.hasFullMetricCoverage ||
      (d.kcalIn.mid !== null && !d.isCompleteLog)
  )
)

const hasDash = computed(() => props.days.some((d) => d.kcalIn.mid === null))

const key = computed(() => {
  const parts = ['Two bars a day: what you burned, then what you ate.']

  if (hasWhisker.value) parts.push('The whisker is how wide that intake estimate is.')
  if (hasFaded.value) parts.push('A faded bar is still adding up.')
  if (hasDash.value) parts.push('A dash under the axis is a day with no food logged.')

  return parts.join(' ')
})
</script>

<template>
  <figure class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
    <figcaption class="mb-2">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold">Energy</h3>
        <div class="flex items-center gap-3 text-[11px] text-stone-500 dark:text-stone-400">
          <span><span class="mr-1 inline-block size-2 rounded-sm bg-orange-500" />burned</span>
          <span><span class="mr-1 inline-block size-2 rounded-sm bg-teal-500" />eaten</span>
        </div>
      </div>

      <!-- The chart explaining itself, where somebody looks first. -->
      <p class="mt-1 text-[10px] leading-relaxed text-stone-400 dark:text-stone-500">
        {{ key }}
      </p>
    </figcaption>

    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Daily energy burned and eaten">
      <g class="text-stone-300 dark:text-stone-700">
        <line
          v-for="line in gridlines"
          :key="line.value"
          :x1="PAD_L" :x2="W" :y1="line.y" :y2="line.y"
          stroke="currentColor" stroke-width="0.5"
        />
      </g>

      <g class="fill-stone-400 text-[7px] dark:fill-stone-500">
        <text v-for="line in gridlines" :key="line.value" :x="0" :y="line.y + 2.5">
          {{ num(line.value) }}
        </text>
      </g>

      <g v-for="column in columns" :key="column.key">
        <!-- ONE TITLE FOR THE WHOLE DAY, ON THE GROUP.
             A tooltip resolves to the nearest ANCESTOR <title>, so here it
             answers for every mark in the column; on a sibling it would answer
             for that sibling alone, and on two 15-unit bars most of the column
             would say nothing. The transparent rect is the hit area — without
             it the gap between the pair, and everything above the shorter bar,
             is not the column as far as a thumb is concerned. -->
        <title>{{ summary(column.day) }}</title>

        <rect
          :x="column.burnX - 1" :y="PAD_T"
          :width="column.width * 2 + geometry.gap + 2" :height="H - PAD_B - PAD_T"
          fill="transparent"
        />

        <rect
          :x="column.burnX" :y="column.burnY"
          :width="column.width" :height="column.burnHeight"
          rx="1"
          class="fill-orange-500"
          :class="{ 'opacity-45': column.burnPartial }"
        />

        <template v-if="column.hasIntake">
          <rect
            :x="column.eatX" :y="column.eatY"
            :width="column.width" :height="column.eatHeight"
            rx="1"
            class="fill-teal-500"
            :class="{ 'opacity-45': column.eatPartial }"
          />

          <!-- min..max, centred on its bar. Deeper teal so it reads against
               both the bar below the midpoint and the card above it. -->
          <g
            class="stroke-teal-700 dark:stroke-teal-300"
            stroke-width="0.9"
            :class="{ 'opacity-45': column.eatPartial }"
          >
            <line
              :x1="column.whiskerX" :x2="column.whiskerX"
              :y1="column.whiskerTop" :y2="column.whiskerBottom"
            />
            <line
              :x1="column.whiskerX - column.capWidth" :x2="column.whiskerX + column.capWidth"
              :y1="column.whiskerTop" :y2="column.whiskerTop"
            />
            <line
              :x1="column.whiskerX - column.capWidth" :x2="column.whiskerX + column.capWidth"
              :y1="column.whiskerBottom" :y2="column.whiskerBottom"
            />
          </g>
        </template>

        <!-- Nothing logged. Stated under the axis: an empty half-column and a
             day of fasting are the same picture and opposite facts. -->
        <text
          v-else
          :x="column.dashX" :y="H - PAD_B + 5"
          text-anchor="middle"
          class="fill-stone-300 text-[6px] dark:fill-stone-600"
        >
          —
        </text>
      </g>

      <!-- Only every fourth label at 28 days, or they overlap into a smear. -->
      <g class="fill-stone-400 text-[6px] dark:fill-stone-500">
        <text
          v-for="(column, index) in columns"
          v-show="columns.length <= 14 || index % 4 === 0"
          :key="column.key"
          :x="column.centreX"
          :y="H - 3"
          text-anchor="middle"
        >
          {{ column.label }}
        </text>
      </g>
    </svg>

    <!-- WHICH WAY THE WINDOW CAME OUT ------------------------------------
         BalanceTally, over the same per-day verdicts the day view prints, on
         fully logged days only — see that class for why a partial log cannot
         be counted. Absent when there are too few, rather than hedged. -->
    <p
      v-if="tally?.sentence"
      class="mt-2 border-t border-stone-100 pt-2 text-[11px] leading-relaxed text-stone-600 dark:border-stone-800 dark:text-stone-300"
    >
      {{ tally.sentence }}
    </p>
  </figure>
</template>
