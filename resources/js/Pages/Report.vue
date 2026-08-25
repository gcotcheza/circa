<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import ReportForm from '../Components/ReportForm.vue'
import ReportBody from '../Components/ReportBody.vue'
import ReportList from '../Components/ReportList.vue'
import { startReport, watchReport } from '../lib/health-reports'

/**
 * The report tab. A report takes a minute, so this is the app's usual async
 * shape: a JSON POST answering with an id, a JSON poll on that id, then an
 * INERTIA RELOAD once the status settles.
 *
 * The reload is the part worth defending. Having the poll return the whole
 * report would mean two code paths for one screen — a report you watched arrive
 * and a report you opened from a link — drifting apart where only the second
 * reading of an old report would show it. The poll answers a status and nothing
 * else; the rendered report always comes from one controller, view and parser.
 *
 * The watcher is also STARTED ON MOUNT when the server says something is in
 * flight, so a reload mid-generation or a second device lands on a page that is
 * following the work rather than one that forgot it.
 */
const props = defineProps({
  report: { type: Object, default: null },
  reports: { type: Array, default: () => [] },
  inFlight: { type: Object, default: null },
  presets: { type: Array, required: true },
  maxRangeDays: { type: Number, required: true },
  today: { type: String, required: true },
  model: { type: String, default: null },
  focus: { type: Object, required: true },
})

const busy = ref(false)
const error = ref(null)
const watching = ref(props.inFlight)

/* A cancellation flag rather than a cleared timer: `watchReport` owns its sleep
 * and cannot be interrupted mid-await. What this prevents is a stale generation
 * firing an Inertia visit from a dead component — a page that jumps under
 * somebody's thumb after they navigated away. */
let live = true

onBeforeUnmount(() => {
  live = false
})

onMounted(() => {
  if (props.inFlight) follow(props.inFlight.id)
})

async function follow(id) {
  const state = await watchReport(id, {
    onState: (s) => {
      if (live && s.report) watching.value = s.report
    },
  })

  if (!live) return

  watching.value = null
  busy.value = false

  if (state.timedOut) {
    error.value = 'The report is taking longer than expected. Reload the page to see where it got to.'

    return
  }

  /* `router.visit` rather than `router.reload`: a report generated from the
   * tab's index page has a URL of its own now, and leaving the user on /report
   * would mean the back button could not get them out of it. */
  router.visit(state.report?.url ?? '/report', { preserveScroll: true })
}

async function generate(body) {
  if (busy.value) return

  busy.value = true
  error.value = null

  const result = await startReport(body)

  if (result.ok) {
    watching.value = result.report
    follow(result.report.id)

    return
  }

  /* 409 is not a failure: another tab, or the Monday cron, already started one —
   * so follow THAT rather than show a red box about an invisible race. */
  if (result.status === 'already_generating' && result.report) {
    watching.value = result.report
    follow(result.report.id)

    return
  }

  busy.value = false
  error.value = result.message
}

const openId = computed(() => props.report?.id ?? null)
</script>

<template>
  <Head title="Report" />

  <AppLayout title="Report">
    <div class="space-y-4">
      <!-- IN FLIGHT --------------------------------------------------------
           Above the form: it answers the question the user is about to ask
           again, and names the range so another device's report is identifiable. -->
      <section
        v-if="watching"
        class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30"
      >
        <div class="flex items-center gap-2.5">
          <span class="size-2 animate-pulse rounded-full bg-amber-500" />
          <p class="text-sm font-medium text-amber-900 dark:text-amber-200">
            Writing the report for {{ watching.rangeLabel }}
          </p>
        </div>
        <p class="mt-1 pl-[1.125rem] text-xs leading-relaxed text-amber-800/90 dark:text-amber-300/90">
          It reads the whole range and takes a minute or so. You can leave this page — it will be
          here when it is done.
        </p>
      </section>

      <ReportForm
        v-if="!watching"
        :presets="presets"
        :max-range-days="maxRangeDays"
        :today="today"
        :busy="busy"
        :error="error"
        :focus="focus"
        @generate="generate"
      />

      <ReportBody v-if="report" :report="report" />

      <!-- No illustration or call to action: the form above says what it does. -->
      <p
        v-else-if="!watching"
        class="rounded-2xl border border-stone-200 bg-white p-4 text-xs leading-relaxed text-stone-500
               dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400"
      >
        No reports yet. One is written automatically every Monday morning for the week that just
        finished, and you can ask for any range above.
      </p>

      <ReportList :reports="reports" :open-id="openId" />

      <!-- THE STANDING DISCLAIMER -------------------------------------------
           On every view of this tab, not only under a generated report: the
           thing disclaimed is the feature, not one document. It names the model
           because "a language model read your own numbers" is the most useful
           fact a reader can have about a paragraph that sounds authoritative. -->
      <p class="px-1 pt-2 text-[11px] leading-relaxed text-stone-400 dark:text-stone-600">
        These reports are written by a language model<span v-if="model"> ({{ model }})</span> from the
        figures this app has recorded. They are a personal tool for noticing things, not medical
        advice, not a diagnosis and not a substitute for a doctor. The numbers are only ever as good
        as what was logged — every report says what it was missing.
      </p>
    </div>
  </AppLayout>
</template>
