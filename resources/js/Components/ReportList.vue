<script setup>
import { Link } from '@inertiajs/vue3'
import { focusLine } from '../lib/health-reports'

/**
 * Past reports, newest first.
 *
 * The HEADLINE is why the model is asked for one at all: a list of date ranges is
 * unbrowsable — every row looks the same — and truncating the summary instead would
 * put the decision about what a report is ABOUT into a CSS ellipsis. THE FOCUS IS A
 * THIRD LINE, only when there is one: "Stress + Sleep · 6m" answers why this report
 * says nothing about food, and has to be visible without opening the row.
 *
 * A FAILED ROW STAYS, carrying its reason: it is the only place the user finds out
 * that Monday's automatic report did not happen, and hiding it would make a silent
 * failure genuinely silent.
 */
defineProps({
  reports: { type: Array, required: true },
  openId: { type: Number, default: null },
})
</script>

<template>
  <section v-if="reports.length" class="rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
    <h2 class="px-4 pt-4 text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-stone-500">
      Reports
    </h2>

    <ul class="divide-y divide-stone-100 dark:divide-stone-800">
      <li v-for="report in reports" :key="report.id">
        <Link
          :href="report.url"
          preserve-scroll
          class="flex items-start gap-3 px-4 py-3 transition active:scale-[0.99]"
          :class="report.id === openId ? 'bg-teal-50/60 dark:bg-teal-950/20' : 'hover:bg-stone-50 dark:hover:bg-stone-800/40'"
        >
          <div class="min-w-0 flex-1">
            <p class="tnum text-sm font-medium">
              {{ report.rangeLabel }}
              <span class="ml-1 text-[11px] font-normal text-stone-400 dark:text-stone-500">
                {{ report.kindLabel }}
              </span>
            </p>

            <p
              v-if="focusLine(report)"
              class="mt-0.5 text-[11px] font-medium text-teal-700 dark:text-teal-400"
            >{{ focusLine(report) }}</p>

            <p v-if="report.headline" class="mt-0.5 text-[12px] leading-snug text-stone-600 dark:text-stone-400">
              {{ report.headline }}
            </p>

            <p v-else-if="report.status === 'failed'" class="mt-0.5 text-[12px] leading-snug text-rose-700 dark:text-rose-400">
              {{ report.error ?? 'Did not finish.' }}
            </p>

            <p v-else-if="report.pending" class="mt-0.5 text-[12px] text-stone-500 dark:text-stone-400">
              Being written…
            </p>
          </div>

          <!-- A dot, not a word: the state is redundant with the line above except
               for "still going", and a column of "ready ready ready" is noise. -->
          <span
            v-if="report.pending"
            class="mt-1.5 size-2 shrink-0 animate-pulse rounded-full bg-amber-500"
            aria-label="being written"
          />
          <span
            v-else-if="report.status === 'failed'"
            class="mt-1.5 size-2 shrink-0 rounded-full bg-rose-500"
            aria-label="failed"
          />
        </Link>
      </li>
    </ul>
  </section>
</template>
