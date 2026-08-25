<script setup>
import AirDate from './AirDate.vue'

/**
 * Tap the date to go to another one.
 *
 * An arrow is a STEP: right for "what did I eat yesterday", useless for "what did
 * I eat in March" — eighteen months of history is five hundred presses on the day
 * view and seventy-eight on the stress page. So the label between the arrows, the
 * only thing on the row that names where you are, becomes the way out.
 *
 * THE PICKER IS A CALENDAR, NOT THE PHONE'S WHEEL. A native `<input type="date">`
 * shipped three spinning columns — a fine way to say "the 3rd of February", a poor
 * one to answer "which Saturday was that?", when a month you can see is the point
 * of jumping. So it is Air Datepicker now, wrapped in Components/AirDate.vue where
 * the reasoning about the library lives; this component's shape is unchanged.
 *
 * WHY AN INVISIBLE INPUT AND NOT A BUTTON: Air Datepicker binds open-on-focus,
 * keyboard navigation and mobile behaviour only for an `<input>` — anything else
 * becomes a permanently open inline calendar. So the input IS the control and the
 * label is what it looks like: `opacity: 0` changes painting, not hit testing.
 * `min`/`max` come from the server and stop the calendar paging back through 1970.
 * The input is `readonly` (see AirDate), which keeps iOS from raising a keyboard
 * behind the calendar, and is out of flow so it cannot size the header row.
 *
 * A PICKER, NOT VALIDATION — the app avoids the latter (see DecimalInputTest)
 * because a browser rejecting a value in its own words is a dead end on a phone.
 * Out-of-range dates are simply not offered, and the server clamps regardless.
 */
const props = defineProps({
  /** The date the picker opens on, YYYY-MM-DD. */
  date: { type: String, required: true },

  /** What the header actually reads — "Mon 15 Jun", "13–19 Apr 2025". */
  label: { type: String, required: true },

  /** The history span, from the server. Null = unbounded, as an empty database gets; see HistorySpan. */
  min: { type: String, default: null },
  max: { type: String, default: null },

  /** A quiet tag after the label, e.g. "this week". */
  hint: { type: String, default: '' },

  /**
   * The accessible name: the visible label says WHERE YOU ARE, but a screen
   * reader lands on the input. A prop on purpose — `aria-label` on a component
   * falls through onto the wrapper span, renaming a decorative box and leaving
   * the input itself unnamed.
   */
  ariaLabel: { type: String, default: 'Jump to a date' },
})

const emit = defineEmits(['jump'])

function onPick(picked) {
  // Dismissing without choosing leaves the value alone, and re-picking the day
  // you are on is not a navigation. Neither is worth a round trip.
  if (picked === '' || picked === props.date) return

  emit('jump', picked)
}
</script>

<template>
  <span class="relative inline-flex items-center gap-1">
    <!-- The dotted underline and chevron are the whole affordance: a button-shaped
         date would compete with the two arrows either side, pressed far more often. -->
    <span
      class="tnum text-sm font-medium tracking-tight underline decoration-stone-400/70 decoration-dotted
             underline-offset-4 dark:decoration-stone-500/70"
    >{{ label }}</span>

    <span v-if="hint" class="text-[11px] font-normal text-stone-400">{{ hint }}</span>

    <svg
      class="size-3.5 shrink-0 text-stone-400 dark:text-stone-500"
      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"
    >
      <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
    </svg>

    <!-- Stretched over the label, invisible, and the only thing actually tapped.
         `text-base` is 16 px: anything smaller and iOS zooms the page on focus. -->
    <AirDate
      :model-value="date"
      :min="min"
      :max="max"
      :aria-label="ariaLabel"
      class="absolute inset-0 h-full w-full cursor-pointer text-base opacity-0"
      @update:model-value="onPick"
    />
  </span>
</template>
