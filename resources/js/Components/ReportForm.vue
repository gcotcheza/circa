<script setup>
import { computed, ref } from 'vue'
import AirDate from './AirDate.vue'
import ReportFocusChips from './ReportFocusChips.vue'
import { shortRange } from '../lib/health-reports'
import { blockedReason, canSubmit, ceilingDays, customDays, payload } from '../lib/report-form'

/**
 * The Generate control: a focus, a custom instruction, a range you SELECT, and
 * one button that submits the lot.
 *
 * RANGE IS A SELECTION, NOT THE TRIGGER. Every range chip used to fire the report
 * itself, which was right while a range was the only thing to say. The focus chips
 * and the free-text instruction reversed it: a box to type into reads as a form,
 * and a form is something you complete and submit — a real user filled it in and
 * went looking for the button that did not exist.
 *
 * So exactly one range is selected at a time and NOTHING is selected on load:
 * Generate stays DISABLED until a range is deliberately tapped, because
 * generating fires a model call that costs real money and a minute or two. A
 * quiet "Pick a range to generate" under the dead button says why.
 *
 * EACH CHIP SHOWS THE DATES IT MEANS — "30 days" and "13 Jul – 11 Aug" — computed
 * on the SERVER against the app's reporting timezone, the same clock that decides
 * which local day everything else belongs to. A preset that does not say what it
 * resolves to has to be verified after the fact.
 *
 * THE FOCUS SITS ABOVE THE RANGE BECAUSE IT DECIDES WHAT THE RANGE MAY BE: a
 * focused report assembles fewer blocks, so it covers far more days — six months
 * of stress is a document this app can build, six months of food is not. The long
 * chips are therefore ALWAYS VISIBLE, SOMETIMES DISABLED: a row that reflows under
 * a thumb is disorienting, and an absent 6-month chip teaches nothing while one
 * greyed with "Pick a focus first" teaches the feature in three words. Ceilings
 * come from the SERVER (`focus.maxRangeDays`); see lib/report-form.js for why they
 * are not written here. A range later put out of reach — the focus cleared after a
 * long range was chosen — is refused by Generate with that reason on the button.
 *
 * THE BUSY STATE IS NOT A GUARD: the server refuses a second concurrent report
 * with a 409 whatever this component does; disabling here is a courtesy.
 */
const props = defineProps({
  presets: { type: Array, required: true },
  maxRangeDays: { type: Number, required: true },
  today: { type: String, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: null },
  focus: { type: Object, required: true },
})

const emit = defineEmits(['generate'])

const custom = ref(false)
const from = ref('')
const to = ref(props.today)

const focusAreas = ref([])
const focusText = ref('')

// Nothing selected on load; Generate stays disabled until a range is tapped.
const selectedDays = ref(null)

const ceiling = computed(() => ceilingDays(focusAreas.value, props.focus.maxRangeDays))

const customDayCount = computed(() => customDays(from.value, to.value))
const customTooLong = computed(() => customDayCount.value !== null && customDayCount.value > ceiling.value)

// What the button would submit: the custom pair while open, else the preset.
const currentRange = computed(() => (custom.value ? { from: from.value, to: to.value } : { days: selectedDays.value }))

const canGenerate = computed(() => !props.busy && canSubmit(currentRange.value, focusAreas.value, props.focus.maxRangeDays))

// Why the preset selection cannot run, shown by the button. Only a focus-blocked
// long range reaches this: presets are never too long, custom warns live below.
const blockNote = computed(() => (custom.value ? null : blockedReason(selectedDays.value, focusAreas.value, ceiling.value)))

// The nudge under a disabled button. Suppressed once a range is valid, while a
// report is in flight, and when a louder reason shows (blockNote, long custom).
const rangeHint = computed(() => {
  if (props.busy || canGenerate.value || blockNote.value) return null
  if (custom.value && customTooLong.value) return null

  return 'Pick a range to generate'
})

// With exactly one focus area chosen, the label names the one thing it is about.
const buttonLabel = computed(() => {
  if (focusAreas.value.length !== 1) return 'Generate report'

  const area = props.focus.areas.find((a) => a.value === focusAreas.value[0])

  return area ? `Generate ${area.label.toLowerCase()} report` : 'Generate report'
})

function select(days) {
  selectedDays.value = days
}

function generate() {
  if (!canGenerate.value) return

  emit('generate', payload(currentRange.value, focusAreas.value, focusText.value))
}
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <h2 class="text-sm font-semibold tracking-tight">Write a report</h2>

    <p class="mt-1 text-xs leading-relaxed text-stone-500 dark:text-stone-400">
      Everything logged over the range — food, sleep, energy, movement, supplements and stress —
      read back in sentences. It takes a minute or so.
    </p>

    <!-- FOCUS + INSTRUCTION first: the focus decides what the range may be. -->
    <div class="mt-3 border-b border-stone-100 pb-3 dark:border-stone-800">
      <ReportFocusChips
        v-model:selected="focusAreas"
        v-model:text="focusText"
        :areas="focus.areas"
        :max-text-length="focus.maxTextLength"
        :disabled="busy"
      />
    </div>

    <!-- THE RANGE: a selection rather than the trigger; Generate below submits it. -->
    <div v-if="!custom" class="mt-3 grid grid-cols-2 gap-2">
      <button
        v-for="preset in presets"
        :key="preset.days"
        type="button"
        class="rounded-xl border px-3 py-2.5 text-left transition active:scale-[0.98]
               disabled:opacity-50"
        :class="selectedDays === preset.days
          ? 'border-teal-500 bg-teal-50 dark:border-teal-500 dark:bg-teal-950/40'
          : 'border-stone-200 dark:border-stone-700 enabled:hover:border-teal-500 enabled:hover:bg-teal-50/60 dark:enabled:hover:border-teal-500 dark:enabled:hover:bg-teal-950/30'"
        :disabled="busy"
        :aria-pressed="selectedDays === preset.days"
        @click="select(preset.days)"
      >
        <span class="block text-sm font-medium">{{ preset.label }}</span>
        <span class="tnum block text-[11px] text-stone-500 dark:text-stone-400">
          {{ shortRange(preset.start, preset.end) }}
        </span>
      </button>
    </div>

    <!-- THE LONG RANGES. Always here, greyed until the focus makes them legal,
         with the reason on the chip rather than in a paragraph nobody reads. -->
    <div v-if="!custom && focus.longPresets.length" class="mt-2 flex flex-wrap gap-1.5">
      <button
        v-for="preset in focus.longPresets"
        :key="preset.days"
        type="button"
        class="rounded-full border px-2.5 py-1 text-xs font-medium transition active:scale-95
               disabled:cursor-not-allowed disabled:opacity-45"
        :class="selectedDays === preset.days && !blockedReason(preset.days, focusAreas, ceiling)
          ? 'border-teal-500 bg-teal-50 text-teal-900 dark:bg-teal-950/40 dark:text-teal-200'
          : blockedReason(preset.days, focusAreas, ceiling)
            ? 'border-stone-200 text-stone-500 dark:border-stone-800 dark:text-stone-500'
            : 'border-stone-200 text-stone-600 enabled:hover:border-teal-500 dark:border-stone-700 dark:text-stone-300'"
        :disabled="busy || Boolean(blockedReason(preset.days, focusAreas, ceiling))"
        :title="blockedReason(preset.days, focusAreas, ceiling) ?? shortRange(preset.start, preset.end)"
        :aria-pressed="selectedDays === preset.days && !blockedReason(preset.days, focusAreas, ceiling)"
        @click="select(preset.days)"
      >
        {{ preset.label }}
        <span v-if="blockedReason(preset.days, focusAreas, ceiling)" class="ml-1 font-normal text-stone-400 dark:text-stone-600">
          · {{ blockedReason(preset.days, focusAreas, ceiling) }}
        </span>
      </button>
    </div>

    <!-- `v-if="custom"` rather than `v-else`: the long-range chips sit between
         this and the preset grid, and a v-else must be immediately adjacent. Two
         independent conditions on one boolean survive a row inserted between. -->
    <div v-if="custom" class="mt-3 space-y-2">
      <!-- Two calendars, bounded exactly as the native pickers were: no later
           than today. Order (from ≤ to) is checked below in words — a picker
           silently refusing a day leaves you tapping a greyed-out square with no
           idea which of the two boxes was the problem. -->
      <div class="flex items-center gap-2">
        <label class="flex-1 min-w-0">
          <span class="block text-[11px] font-medium text-stone-500 dark:text-stone-400">From</span>
          <AirDate
            v-model="from"
            :max="today"
            format="d MMM yyyy"
            placeholder="Pick a day"
            class="tnum mt-0.5 w-full min-w-0 cursor-pointer rounded-lg border border-stone-200 bg-white px-2 py-1.5
                   text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>

        <label class="flex-1 min-w-0">
          <span class="block text-[11px] font-medium text-stone-500 dark:text-stone-400">To</span>
          <AirDate
            v-model="to"
            :max="today"
            format="d MMM yyyy"
            placeholder="Pick a day"
            class="tnum mt-0.5 w-full min-w-0 cursor-pointer rounded-lg border border-stone-200 bg-white px-2 py-1.5
                   text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>
      </div>

      <!-- The length, live: a day count visible only after a failed submit is a guess. -->
      <p v-if="customDayCount" class="tnum px-0.5 text-[11px]" :class="customTooLong ? 'text-rose-600 dark:text-rose-400' : 'text-stone-500 dark:text-stone-400'">
        {{ customDayCount }} day{{ customDayCount === 1 ? '' : 's' }}<span v-if="customTooLong"> — the most this focus can cover is {{ ceiling }}.</span>
      </p>
    </div>

    <!-- Quiet disclosure: custom is genuinely wanted ("that holiday in June") but rare. -->
    <div class="mt-3 flex items-center justify-between">
      <button
        type="button"
        class="rounded-lg px-1.5 py-1 text-xs font-medium text-stone-500 transition
               hover:bg-stone-200/60 active:scale-95 dark:text-stone-400 dark:hover:bg-stone-800/60"
        @click="custom = !custom"
      >
        {{ custom ? '‹ Presets' : 'Custom range ›' }}
      </button>
    </div>

    <!-- ONE primary button: the selection with focus and instruction folded in.
         Disabled until a range is chosen, in flight, or illegal — reason beneath. -->
    <button
      type="button"
      class="mt-2 w-full rounded-xl bg-teal-600 px-3 py-2.5 text-sm font-semibold text-white transition
             active:scale-[0.98] disabled:opacity-50"
      :disabled="!canGenerate"
      @click="generate"
    >
      {{ buttonLabel }}
    </button>

    <p v-if="blockNote" class="mt-1.5 px-0.5 text-[11px] text-rose-600 dark:text-rose-400">
      {{ blockNote }}
    </p>

    <!-- Muted, not an error: nothing is wrong yet, the user simply has not picked
         a range. Reuses the form's muted-hint tone (text-stone-500/400). -->
    <p v-if="rangeHint" class="mt-1.5 px-0.5 text-[11px] text-stone-500 dark:text-stone-400">
      {{ rangeHint }}
    </p>

    <p v-if="error" class="mt-2 rounded-xl bg-rose-100 px-3 py-2 text-xs text-rose-900 dark:bg-rose-950 dark:text-rose-200">
      {{ error }}
    </p>
  </section>
</template>
