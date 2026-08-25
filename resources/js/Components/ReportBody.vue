<script setup>
import { computed } from 'vue'
import { seconds } from '../lib/health-reports'

/**
 * One report, rendered.
 *
 * THE ORDER IS THE ORDER SOMEBODY READS IN, NOT THE SCHEMA'S: stop after the
 * summary and you have the answer; go to the bottom and you end on the two things
 * that qualify everything above — the gaps, and that a language model wrote it from
 * this app's own numbers.
 *
 * MICRONUTRIENTS ARE A TABLE, NOT A PARAGRAPH: the three columns — exact supplement
 * figure, food-derived figure with its coverage, and a comment not allowed to add
 * them together — are the whole point, since prose would let the two kinds of
 * number share a sentence, the confusion this design exists to prevent.
 *
 * NOTHING IS COLOUR-CODED BY VERDICT: a traffic light over prose about a person's
 * own week would be this app making a judgement it has spent nine steps refusing to
 * make. The failure state is red because it is a broken thing, not a finding.
 */
const props = defineProps({
  report: { type: Object, required: true },
})

const body = computed(() => props.report.body)

const sections = computed(() => {
  const b = body.value

  if (!b) return []

  return [
    { key: 'energyBalance', title: 'Energy balance', text: b.energyBalance },
    { key: 'foodQuality', title: 'Food', text: b.foodQuality },
    { key: 'sleep', title: 'Sleep', text: b.sleep },
    { key: 'stressPatterns', title: 'Stress', text: b.stressPatterns },
    { key: 'cardiovascular', title: 'Heart rate & HRV', text: b.cardiovascular },
  ].filter((s) => s.text)
})

const provenance = computed(() => {
  const p = props.report.provenance ?? {}
  const parts = []

  if (p.model) parts.push(p.model)
  if (p.promptVersion) parts.push(`prompt ${p.promptVersion}`)
  if (p.inputTokens !== null && p.inputTokens !== undefined) {
    parts.push(`${p.inputTokens.toLocaleString('en-GB')} in / ${(p.outputTokens ?? 0).toLocaleString('en-GB')} out`)
  }
  if (p.costUsd !== null && p.costUsd !== undefined) parts.push(`$${p.costUsd.toFixed(3)}`)
  if (p.latencyMs) parts.push(seconds(p.latencyMs))

  return parts.join(' · ')
})

function coverageLine(label, block) {
  if (!block || block.n === null || block.n === undefined) return null

  return `${label} ${block.n}`
}

const coverage = computed(() => {
  const c = props.report.coverage

  if (!c) return null

  return [
    coverageLine('food logged', c.foodLoggedDays),
    coverageLine('nights of sleep', c.sleepNights),
    coverageLine('stress scored', c.stressScoredDays),
    coverageLine('weigh-ins', c.weighIns),
  ].filter(Boolean)
})
</script>

<template>
  <article class="space-y-4">
    <!-- FAILED ------------------------------------------------------------ -->
    <section
      v-if="report.status === 'failed'"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/40"
    >
      <h2 class="text-sm font-semibold text-rose-900 dark:text-rose-200">This report did not finish</h2>
      <p class="mt-1 text-xs leading-relaxed text-rose-800 dark:text-rose-300">
        {{ report.error ?? 'It stopped before it was written.' }}
      </p>
      <p class="mt-2 text-xs text-rose-800/80 dark:text-rose-300/80">
        Nothing was saved for {{ report.rangeLabel }}. Generating it again is safe.
      </p>
    </section>

    <template v-else-if="body">
      <!-- SUMMARY ---------------------------------------------------------- -->
      <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="tnum text-sm font-semibold tracking-tight">{{ report.rangeLabel }}</h2>
          <span class="shrink-0 text-[11px] text-stone-400 dark:text-stone-500">
            {{ report.days }} day{{ report.days === 1 ? '' : 's' }} · {{ report.kindLabel }}
          </span>
        </div>

        <p class="mt-2 text-[15px] leading-relaxed">{{ body.summary }}</p>

        <!-- What the report was written from. Under the summary because it qualifies
             everything below: knowing only four days had food logged changes how
             the food section reads. -->
        <p v-if="coverage?.length" class="tnum mt-3 border-t border-stone-100 pt-2 text-[11px] text-stone-500 dark:border-stone-800 dark:text-stone-400">
          From {{ coverage.join(' · ') }}
        </p>
      </section>

      <!-- BODY SECTIONS ---------------------------------------------------- -->
      <section
        v-for="section in sections"
        :key="section.key"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
          {{ section.title }}
        </h3>
        <p class="mt-1.5 text-sm leading-relaxed">{{ section.text }}</p>
      </section>

      <!-- MICRONUTRIENTS --------------------------------------------------- -->
      <section
        v-if="body.micronutrients?.length"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
          Micronutrients
        </h3>

        <p class="mt-1.5 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
          Supplement amounts are exact, read off the label. Food amounts are not tracked for these,
          so a total intake is not something this app can give you.
        </p>

        <ul class="mt-3 space-y-3">
          <li
            v-for="row in body.micronutrients"
            :key="row.nutrient"
            class="border-t border-stone-100 pt-3 first:border-0 first:pt-0 dark:border-stone-800"
          >
            <p class="text-sm font-medium">{{ row.nutrient }}</p>

            <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[12px]">
              <dt class="text-stone-400 dark:text-stone-500">Supplement</dt>
              <dd class="tnum">{{ row.supplementExact }}</dd>

              <template v-if="row.foodEstimateRange">
                <dt class="text-stone-400 dark:text-stone-500">Food</dt>
                <dd class="tnum">
                  {{ row.foodEstimateRange }}
                  <span v-if="row.foodCoveragePct !== null" class="text-stone-400 dark:text-stone-500">
                    ({{ row.foodCoveragePct }}% of intake covered)
                  </span>
                </dd>
              </template>

              <template v-else>
                <dt class="text-stone-400 dark:text-stone-500">Food</dt>
                <dd class="text-stone-400 dark:text-stone-500">not tracked</dd>
              </template>
            </dl>

            <p v-if="row.combinedComment" class="mt-1 text-[12px] leading-relaxed text-stone-600 dark:text-stone-400">
              {{ row.combinedComment }}
            </p>
          </li>
        </ul>
      </section>

      <!-- OBSERVATIONS ----------------------------------------------------- -->
      <section
        v-if="body.observations?.length"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
          What lines up
        </h3>

        <ul class="mt-2 space-y-2.5">
          <li v-for="(item, i) in body.observations" :key="i">
            <p class="text-sm leading-relaxed">{{ item.observation }}</p>
            <!-- Evidence shown, not behind a disclosure: it makes the observation
                 checkable, and one nobody can check should not be acted on. -->
            <p v-if="item.evidence" class="tnum mt-0.5 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
              {{ item.evidence }}
            </p>
          </li>
        </ul>
      </section>

      <!-- SUGGESTIONS ------------------------------------------------------ -->
      <section
        v-if="body.suggestions?.length"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
          Worth trying
        </h3>

        <ul class="mt-2 space-y-2.5">
          <li v-for="(item, i) in body.suggestions" :key="i">
            <p class="text-sm leading-relaxed">{{ item.suggestion }}</p>
            <p v-if="item.rationale" class="mt-0.5 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
              {{ item.rationale }}
            </p>
          </li>
        </ul>
      </section>

      <!-- DATA GAPS -------------------------------------------------------- -->
      <section
        v-if="body.dataGaps?.length"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
          What was missing
        </h3>

        <ul class="mt-2 space-y-1.5">
          <li v-for="(gap, i) in body.dataGaps" :key="i" class="text-[12px] leading-relaxed text-stone-600 dark:text-stone-400">
            {{ gap }}
          </li>
        </ul>
      </section>

      <!-- PROVENANCE ------------------------------------------------------- -->
      <p class="px-1 text-[11px] leading-relaxed text-stone-400 dark:text-stone-600">
        <span class="tnum">{{ provenance }}</span>
        <template v-if="report.provenance?.hasSnapshot">
          ·
          <a :href="report.provenance.snapshotUrl" class="underline underline-offset-2">the numbers it was given</a>
        </template>
      </p>
    </template>
  </article>
</template>
