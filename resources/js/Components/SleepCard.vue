<script setup>
import { computed } from 'vue'
import { duration } from '../lib/format'
import { arrivalNote } from '../lib/sleep'
import HonestyChip from './HonestyChip.vue'

/**
 * One night, from `sleep_sessions`. `fragment` means part of it hasn't
 * arrived; the chip hedges because a late sync and true absence look
 * identical from here. See docs/rationale-frontend.md § "Night Fragment
 * Note".
 */
const props = defineProps({
  sleep: { type: Object, required: true },
})

const partial = computed(() => arrivalNote(props.sleep))

const stages = computed(() => {
  const parts = [
    { key: 'deep', minutes: props.sleep.deepMinutes, class: 'bg-indigo-600' },
    { key: 'core', minutes: props.sleep.coreMinutes, class: 'bg-indigo-400' },
    { key: 'rem', minutes: props.sleep.remMinutes, class: 'bg-sky-400' },
    { key: 'awake', minutes: props.sleep.awakeMinutes, class: 'bg-stone-300 dark:bg-stone-600' },
  ].filter((p) => p.minutes)

  const total = parts.reduce((sum, p) => sum + Number(p.minutes), 0)

  return parts.map((p) => ({ ...p, width: total > 0 ? `${(100 * p.minutes) / total}%` : '0%' }))
})
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="flex items-baseline justify-between">
      <span class="text-sm font-medium text-stone-500 dark:text-stone-400">Sleep</span>
      <span class="tnum text-lg font-semibold">{{ duration(sleep.totalMinutes) }}</span>
    </div>

    <!-- Styled like the burn-floor badge: this is a floor, not the true total. -->
    <div v-if="partial" class="mt-2">
      <HonestyChip tone="warn" :label="partial" />
    </div>

    <div class="mt-2 flex h-2.5 w-full overflow-hidden rounded-full bg-stone-100 dark:bg-stone-800">
      <div v-for="stage in stages" :key="stage.key" :class="stage.class" :style="{ width: stage.width }" />
    </div>

    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-stone-500 dark:text-stone-400">
      <span><span class="mr-1 inline-block size-2 rounded-full bg-indigo-600" />deep {{ duration(sleep.deepMinutes) }}</span>
      <span><span class="mr-1 inline-block size-2 rounded-full bg-indigo-400" />core {{ duration(sleep.coreMinutes) }}</span>
      <span><span class="mr-1 inline-block size-2 rounded-full bg-sky-400" />rem {{ duration(sleep.remMinutes) }}</span>
      <span><span class="mr-1 inline-block size-2 rounded-full bg-stone-300 dark:bg-stone-600" />awake {{ duration(sleep.awakeMinutes) }}</span>
    </div>

    <p v-if="sleep.start" class="mt-2 text-xs text-stone-500 dark:text-stone-400">
      {{ sleep.start }} → {{ sleep.end }} · {{ sleep.source }}
    </p>
  </section>
</template>
