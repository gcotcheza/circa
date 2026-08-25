<script setup>
import { computed } from 'vue'

import { inlineErrors } from '../lib/validation/inline'

/**
 * "Focus on: Stress · Sleep · Food · Training · Weight", and a box to tell Claude
 * what to look at.
 *
 * The box is the CUSTOM INSTRUCTION surface — the one place the user writes a
 * sentence that steers the analysis — so it is labelled as what it is. It sits
 * below the chips because the chips decide what facts get gathered and the
 * instruction only steers how they are read.
 *
 * WHY CHIPS AND A BOX RATHER THAN JUST THE BOX: "only stress" decides which facts
 * are assembled — whether the document has a food ledger, so whether it covers a
 * fortnight or six months — while a sentence about recovery decides only what gets
 * written. One box would mean guessing which was meant, and a wrong guess produces
 * a confident report written from facts that did not contain the answer: the worst
 * failure this app has, because it looks exactly like a right one.
 *
 * FIVE, FIXED AND SHORT — as MealTypeChips is a chip row, not a `<select>`: two
 * rows on a 320 px screen, the same five every time. MULTI-SELECT unlike
 * MealTypeChips, because "stress and sleep" is the commonest real question and
 * splitting it would be two bills; tap-again-to-clear is said out loud in the hint
 * because it is not guessable.
 *
 * NOTHING SELECTED IS NOT A SIXTH CHIP: it means everything, as every report before
 * this control was, and saying so under the row is cheaper than an "All" chip kept
 * mutually exclusive with the other five.
 */
const props = defineProps({
  areas: { type: Array, required: true },
  selected: { type: Array, default: () => [] },
  text: { type: String, default: '' },
  // Required rather than defaulted: the ceiling is HealthReportRequest's, sent
  // down with the rest of the focus payload, and a default here would be a
  // second copy of the number — right up until the day it is not.
  maxTextLength: { type: Number, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:selected', 'update:text'])

function toggle(value) {
  const next = props.selected.includes(value)
    ? props.selected.filter((v) => v !== value)
    : [...props.selected, value]

  emit('update:selected', next)
}

const remaining = computed(() => props.maxTextLength - (props.text?.length ?? 0))

/**
 * The ceiling is HealthReportRequest's, said in its words once the box is left,
 * rather than a native one the browser enforces by refusing to accept another
 * letter. The counter below still runs, and now goes negative: a box that stops
 * taking input mid-sentence is the version of this nobody could argue with.
 */
const inline = inlineErrors('HealthReportRequest', () => ({ focusText: props.text }))
</script>

<template>
  <div>
    <div class="flex items-baseline justify-between">
      <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Focus on</span>

      <span
        v-if="selected.length"
        class="text-[11px] text-stone-400 dark:text-stone-500"
      >Tap again to clear</span>
    </div>

    <div class="mt-1 flex flex-wrap gap-1.5" role="group" aria-label="Report focus">
      <button
        v-for="area in areas"
        :key="area.value"
        type="button"
        class="rounded-full border px-2.5 py-1 text-xs font-medium transition active:scale-95 disabled:opacity-50"
        :class="selected.includes(area.value)
          ? 'border-teal-500 bg-teal-50 text-teal-900 dark:bg-teal-950/40 dark:text-teal-200'
          : 'border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-400'"
        :aria-pressed="selected.includes(area.value)"
        :disabled="disabled"
        @click="toggle(area.value)"
      >
        {{ area.label }}
      </button>
    </div>

    <!-- The default, said rather than drawn: an "All" chip would be a sixth control
         to keep mutually exclusive, for a state the empty row already expresses. -->
    <p class="mt-1 text-[11px] leading-relaxed text-stone-400 dark:text-stone-500">
      <template v-if="!selected.length">
        Nothing selected — the report covers everything.
      </template>
      <template v-else>
        Only these areas are gathered, so the report can cover a longer stretch.
      </template>
    </p>

    <label class="mt-3 block">
      <span class="block text-xs font-medium text-stone-500 dark:text-stone-400">
        Tell Claude what to focus on <span class="font-normal text-stone-400 dark:text-stone-500">(optional)</span>
      </span>
      <span class="mt-0.5 block text-[11px] leading-relaxed text-stone-400 dark:text-stone-500">
        A note in your own words, read alongside everything above.
      </span>

      <textarea
        :value="text"
        rows="2"
        :disabled="disabled"
        placeholder="e.g. compare my recovery before and after I started weighing in daily"
        class="mt-1 w-full resize-none rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm
               placeholder:text-stone-400 disabled:opacity-50
               dark:border-stone-700 dark:bg-stone-950 dark:placeholder:text-stone-600"
        @input="emit('update:text', $event.target.value); inline.clear('focusText')"
        @blur="inline.check('focusText')"
      />

      <span v-if="inline.errors.focusText" class="mt-0.5 block text-xs text-rose-600 dark:text-rose-400">
        {{ inline.errors.focusText }}
      </span>

      <!-- Only once nearly full: a counter under an empty box advertises a limit nobody has neared. -->
      <span
        v-if="text && remaining < 80"
        class="tnum mt-0.5 block text-right text-[11px]"
        :class="remaining <= 0 ? 'text-rose-600 dark:text-rose-400' : 'text-stone-400 dark:text-stone-500'"
      >{{ remaining }} left</span>
    </label>
  </div>
</template>
