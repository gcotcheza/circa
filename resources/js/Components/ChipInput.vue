<script setup>
/**
 * A text box with the common answers offered as taps above it.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT A `<select>`
 *
 * Sex, goal, smoking and sun exposure read like enumerations but are stored as
 * text, because each honest option list ends with "something else, in your own
 * words" — a dropdown forbids that case, a bare box makes the common one four
 * taps of typing. This is both: chips fill the box, the box stays editable.
 * Same decision `supplement_nutrients` makes about nutrient names, for the same
 * reason: the consumer downstream is a language model reading prose.
 * ---------------------------------------------------------------------------
 *
 * A chip is a toggle: tapping the lit one clears the field, the only way to
 * un-answer a question answered by accident.
 */
defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
})

// `blur` is forwarded because the page above owns the field name and therefore
// the sentence: this box knows when it was left, not what it is called.
const emit = defineEmits(['update:modelValue', 'blur'])

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'

function isPicked(current, option) {
  return String(current ?? '').trim().toLowerCase() === option.toLowerCase()
}

function pick(current, option) {
  emit('update:modelValue', isPicked(current, option) ? '' : option)
}
</script>

<template>
  <div>
    <div v-if="options.length > 0" class="mb-1.5 flex flex-wrap gap-1.5">
      <button
        v-for="option in options"
        :key="option"
        type="button"
        class="rounded-full px-2.5 py-1 text-xs font-medium transition active:scale-95"
        :class="isPicked(modelValue, option)
          ? 'bg-teal-600 text-white'
          : 'border border-stone-300 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
        @click="pick(modelValue, option)"
      >
        {{ option }}
      </button>
    </div>

    <!-- No `required`, no pattern, no ceiling the browser enforces — nothing
         here it can veto as the user leaves. The length is ProfileRequest's to
         judge, and it says so in a sentence. -->
    <input
      :value="modelValue"
      type="text"
      :placeholder="placeholder"
      :class="inputClass"
      @input="emit('update:modelValue', $event.target.value)"
      @blur="emit('blur')"
    >
  </div>
</template>
