<script setup>
/**
 * SPEC.md: "Uncertainty is a feature." A day the Watch sat on the charger
 * under-reports burn by hundreds of kcal and looks exactly like a sedentary one.
 * The chip marks that difference inline — a phone tooltip goes untapped.
 */
defineProps({
  tone: { type: String, default: 'muted' }, // muted | warn
  label: { type: String, required: true },
})

const tones = {
  muted: 'bg-stone-200/70 text-stone-600 dark:bg-stone-800 dark:text-stone-400',
  warn: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium leading-4"
    :class="tones[tone]"
  >
    <slot name="icon" />
    {{ label }}
  </span>
</template>
