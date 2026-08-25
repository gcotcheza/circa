<script setup>
import { computed } from 'vue'

/**
 * "Health data: last sync 3h ago."
 *
 * THE FAILURE THIS EXISTS FOR IS SILENT: Health Auto Export runs unattended, and
 * Background App Refresh off, Low Power Mode, a changed API key or a phone left at
 * home each stop the exports with no error anywhere the user sees — the first
 * symptom is a gap in a chart a week later.
 *
 * The age comes from the server (DailyView), not the browser polling the public
 * /api/health endpoint: it is one column on a page already being rendered, and the
 * answer is the SERVER's clock against the last arrival — a client using its own
 * clock would be wrong by the phone's drift, in the direction that hides the
 * problem.
 *
 * THE THRESHOLD IS 36 HOURS, not a rounding of 24: a locked iPhone has no
 * HealthKit access, so the overnight hours land in the MORNING — eight hours is a
 * normal night from here, twelve a normal night plus a slow first sync. Warning
 * earlier would mean an orange line most mornings, and an indicator that is
 * usually orange has stopped being read.
 */
const props = defineProps({
  ingest: { type: Object, default: null },
})

const age = computed(() => props.ingest?.ageHours ?? null)

const stale = computed(() => props.ingest?.stale === true)

/**
 * Hours below a day, days above it. "51h ago" is a number the reader has to
 * divide; "2 days ago" is the fact. Under a day the hour matters, because "1h" and
 * "20h" are different situations and both are fine.
 */
const label = computed(() => {
  if (age.value === null) return 'no health data has arrived yet'

  if (age.value < 1) return 'last sync just now'

  if (age.value < 36) return `last sync ${Math.round(age.value)}h ago`

  const days = Math.floor(age.value / 24)

  return `last sync ${days} day${days === 1 ? '' : 's'} ago`
})
</script>

<template>
  <p
    v-if="ingest"
    class="flex items-center gap-1.5 px-1 text-xs"
    :class="stale ? 'text-amber-700 dark:text-amber-400' : 'text-stone-500 dark:text-stone-400'"
  >
    <svg
      v-if="stale"
      class="size-3.5 shrink-0"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path d="M12 4.5 2.5 20h19L12 4.5Z" stroke-linejoin="round" />
      <path d="M12 10v4M12 17h.01" stroke-linecap="round" />
    </svg>

    <span>
      Health data: {{ label }}<template v-if="stale">
        — check Health Auto Export on your phone</template>.
    </span>
  </p>
</template>
