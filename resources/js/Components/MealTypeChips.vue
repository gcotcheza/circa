<script setup>
/**
 * "Meal: Breakfast · Lunch · Dinner · Snack" — and a fifth state that is no chip
 * at all.
 *
 * NOT A `<select>`: on a phone iOS renders one as a system popover that covers
 * half the screen, dims the sheet behind it and takes two taps and an animation to
 * say "lunch". Four options do not earn that — the same four every day, readable
 * at a glance, and the sheet already answers questions of this shape with a chip
 * row (the "I ate" fractions, the Quick/Per-100g toggle). The option set is what
 * makes that possible: FOUR, FIXED, AND SHORT. A list that could grow, or one fed
 * from the server, would overflow the row on a 320 px screen and belongs in a
 * `<select>` or behind a search box.
 *
 * WHERE THE `—` WENT: "no meal type" is a real answer — most meals are logged
 * without one — so tapping the lit chip clears it, the same gesture ShareChips
 * uses to release an item back to following its plate. Not guessable, so it is
 * SAID: the hint appears beside the label only while something is selected. The
 * value sent to the server is unchanged (`null`, or one of the four MealType
 * cases) — a different way to touch the same field, not a different field.
 */
const props = defineProps({
  modelValue: { type: String, default: null },
})

const emit = defineEmits(['update:modelValue'])

const CHIPS = [
  { value: 'breakfast', label: 'Breakfast' },
  { value: 'lunch', label: 'Lunch' },
  { value: 'dinner', label: 'Dinner' },
  { value: 'snack', label: 'Snack' },
]

function pick(value) {
  emit('update:modelValue', props.modelValue === value ? null : value)
}
</script>

<template>
  <div>
    <div class="flex items-baseline justify-between">
      <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Meal</span>

      <span
        v-if="modelValue !== null"
        class="text-[11px] text-stone-400 dark:text-stone-500"
      >Tap again to clear</span>
    </div>

    <div
      class="mt-1 flex rounded-lg bg-stone-100 p-0.5 text-xs font-medium dark:bg-stone-800"
      role="group"
      aria-label="Meal type"
    >
      <button
        v-for="chip in CHIPS"
        :key="chip.value"
        type="button"
        class="flex-1 rounded-md px-1 py-1.5"
        :class="modelValue === chip.value
          ? 'bg-white shadow-sm dark:bg-stone-950'
          : 'text-stone-500 dark:text-stone-400'"
        :aria-pressed="modelValue === chip.value"
        @click="pick(chip.value)"
      >
        {{ chip.label }}
      </button>
    </div>
  </div>
</template>
