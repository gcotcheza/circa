<script setup>
import { computed } from 'vue'
import { bandInk, bandSwatchStyle, formatDay, formatHour, signed, skippedLabel } from '../lib/stress'

/**
 * The week, read back in sentences.
 *
 * Modelled on the analysis screen of the commercial app being replaced, minus two
 * of its four habits. KEPT: the hardest and easiest day, the week-on-week move,
 * the HRV percentage, readings that stood out. REMOVED: every comparison with "the
 * average range for your age and gender" — no population number appears anywhere
 * in this feature, the only yardstick being the person's own previous days.
 * REMOVED: the canned advice, especially the guessed CAUSES — the old app follows
 * a bad night with "this may be due to alcohol, water intake or illness", which it
 * cannot know and did not measure. A number, its window, and what it is a number
 * OF; the reader supplies the week they had.
 *
 * The narrative half is moved, not abandoned: a health report seeing meals, sleep,
 * supplements and training at once is the honest place for a sentence about why.
 */
const props = defineProps({
  digest: { type: Object, required: true },
  weekLabel: { type: String, required: true },
})

const skippedText = computed(() => {
  const entries = Object.entries(props.digest.skipped ?? {})

  if (entries.length === 0) return null

  return entries.map(([reason, count]) => skippedLabel(reason, count)).join(', ')
})

/** The week's average, and how it sits against the week before. */
const average = computed(() => {
  const d = props.digest

  if (d.meanScore === null) return null

  const days = `${d.scoredDays} scored day${d.scoredDays === 1 ? '' : 's'}`

  if (d.scoreDelta === null) {
    return { headline: `Average ${d.meanScore} across ${days}.`, comparison: null }
  }

  const previous = `${d.previousMeanScore} across ${d.previousScoredDays}`

  const comparison =
    d.scoreDelta === 0
      ? `The same as the week before (${previous}).`
      : `${Math.abs(d.scoreDelta)} point${Math.abs(d.scoreDelta) === 1 ? '' : 's'} ` +
        `${d.scoreDelta > 0 ? 'above' : 'below'} the week before (${previous}).`

  return { headline: `Average ${d.meanScore} across ${days}.`, comparison }
})

/** Why there is no week-on-week comparison, when there is not one. */
const noComparison = computed(() => {
  const d = props.digest

  if (d.scoreDelta !== null || d.meanScore === null) return null

  if (d.scoredDays < d.minDays) {
    return `Too few scored days this week to compare with the week before — ${d.minDays} are needed.`
  }

  return (
    `The week before had ${d.previousScoredDays} scored ` +
    `day${d.previousScoredDays === 1 ? '' : 's'}, so there is nothing solid to compare with ` +
    `(${d.minDays} are needed).`
  )
})

const hrv = computed(() => {
  const h = props.digest.hrv

  if (h.currentMs === null) return null

  const across = `across ${h.currentDays} day${h.currentDays === 1 ? '' : 's'}`

  if (h.percent === null) {
    return {
      headline: `HRV averaged ${h.currentMs} ms ${across}.`,
      comparison: null,
    }
  }

  return {
    headline: `HRV averaged ${h.currentMs} ms ${across}.`,
    comparison:
      `${signed(h.percent, 1)}% against the week before ` +
      `(${h.previousMs} ms across ${h.previousDays}).`,
  }
})

const noHrvComparison = computed(() => {
  const h = props.digest.hrv

  if (h.percent !== null || h.currentMs === null) return null

  return (
    `Not enough days to compare HRV with the week before — ${h.minDays} are needed in each, ` +
    `and there are ${h.currentDays} here against ${h.previousDays} there.`
  )
})

/**
 * The one or two days the week is read back by, as rows. ONE SCORED DAY IS NOT
 * A RANGE — "most and least stressful" would dress a single measurement as a
 * comparison — so that week gets a single row under a label that says so.
 */
const ends = computed(() => {
  const d = props.digest

  return d.singleDay
    ? [{ label: 'Only day scored', day: d.mostStressful }]
    : [
      { label: 'Most stressful', day: d.mostStressful },
      { label: 'Least stressful', day: d.leastStressful },
    ]
})

const moments = computed(() => props.digest.moments ?? [])
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="flex items-baseline justify-between gap-2">
      <h3 class="text-sm font-semibold">The week in detail</h3>
      <span class="tnum text-[11px] text-stone-400">{{ weekLabel }}</span>
    </div>

    <!-- NOTHING TO REPORT, stated: a week the Watch was off for is a fact about the week, and an empty section would read as a bug. -->
    <p
      v-if="digest.scoredDays === 0"
      class="mt-2 text-xs leading-relaxed text-stone-600 dark:text-stone-300"
    >
      No day in this week could be scored<span v-if="skippedText"> — {{ skippedText }}</span>.
    </p>

    <template v-else>
      <!-- THE TWO ENDS OF THE WEEK, or the one; `ends` says which and why. -->
      <dl class="mt-3 space-y-2">
        <div v-for="end in ends" :key="end.label" class="flex items-baseline gap-2">
          <dt class="w-24 shrink-0 text-[11px] text-stone-500 dark:text-stone-400">{{ end.label }}</dt>
          <dd class="flex min-w-0 items-baseline gap-1.5">
            <span
              class="size-2.5 shrink-0 translate-y-px rounded-full"
              :style="bandSwatchStyle(end.day.band)"
              aria-hidden="true"
            />
            <span class="tnum text-xs font-medium">{{ formatDay(end.day.date) }}</span>
            <span class="tnum text-xs font-semibold">{{ end.day.score }}</span>
            <span class="text-[11px]" :style="{ color: bandInk(end.day.band) }">
              {{ end.day.bandLabel }}
            </span>
          </dd>
        </div>
      </dl>

      <!-- The denominator for the two lines above. -->
      <p v-if="skippedText" class="tnum mt-2 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        {{ digest.skippedDays }} of {{ digest.elapsedDays }} elapsed days are not in that
        comparison: {{ skippedText }}.
      </p>

      <!-- THE TWO MOVES ------------------------------------------------- -->
      <div class="mt-3 space-y-2 border-t border-stone-100 pt-3 dark:border-stone-800">
        <p v-if="average" class="tnum text-xs leading-relaxed text-stone-700 dark:text-stone-200">
          {{ average.headline }}
          <span v-if="average.comparison" class="text-stone-500 dark:text-stone-400">
            {{ average.comparison }}
          </span>
        </p>
        <p v-if="noComparison" class="text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
          {{ noComparison }}
        </p>

        <p v-if="hrv" class="tnum text-xs leading-relaxed text-stone-700 dark:text-stone-200">
          {{ hrv.headline }}
          <span v-if="hrv.comparison" class="text-stone-500 dark:text-stone-400">
            {{ hrv.comparison }}
          </span>
        </p>
        <p v-if="noHrvComparison" class="text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
          {{ noHrvComparison }}
        </p>
      </div>
    </template>

    <!-- NOTABLE MOMENTS ------------------------------------------------- -->
    <!-- Absent when `moments` is null: without enough history behind the week,
         unusual is unknowable, and a list anyway would shout loudest on the page. -->
    <div
      v-if="digest.moments !== null"
      class="mt-3 border-t border-stone-100 pt-3 dark:border-stone-800"
    >
      <h4 class="text-xs font-semibold">Readings that stood out</h4>

      <ul v-if="moments.length" class="mt-2 space-y-2.5">
        <li v-for="moment in moments" :key="`${moment.date}-${moment.hour}`">
          <p class="tnum text-xs font-medium">
            <!-- Glyph plus the word below; no band colour, a single reading has no band. -->
            <span aria-hidden="true" class="text-stone-400">{{ moment.direction === 'low' ? '▾' : '▴' }}</span>
            {{ formatDay(moment.date) }} {{ formatHour(moment.hour) }} ·
            {{ moment.valueMs }} ms
          </p>
          <p class="tnum mt-0.5 text-[11px] leading-relaxed text-stone-600 dark:text-stone-300">
            Unusually {{ moment.direction }} for you at that hour — half your
            {{ formatHour(moment.hour) }} readings sit between {{ moment.typicalLowMs }} and
            {{ moment.typicalHighMs }} ms.
          </p>
          <p class="tnum mt-0.5 text-[10px] text-stone-400 dark:text-stone-500">
            {{ Math.abs(moment.z).toFixed(1) }} of your own standard deviations
            {{ moment.direction === 'low' ? 'below' : 'above' }}.
          </p>
        </li>
      </ul>

      <!-- Looked, found nothing — different from "could not look": a quiet week is a result. -->
      <p v-else class="mt-2 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        No reading this week was more than {{ digest.momentMinZ }} of your own standard deviations
        from your usual for its hour.
      </p>

      <p class="mt-2 text-[10px] leading-relaxed text-stone-400 dark:text-stone-500">
        Measured against your own readings at the same hour over the
        {{ digest.momentPoolDays }} days before this week, at most one per day in each direction.
        A single reading is not a band — a band is a whole day's median against sixty days of them.
      </p>
    </div>
  </section>
</template>
