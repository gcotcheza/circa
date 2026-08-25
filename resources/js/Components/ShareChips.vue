<script setup>
import { computed, ref, watch } from 'vue'

/**
 * "I ate: All · ¾ · ½ · ⅓ · ¼ · custom %"
 *
 * The model estimates what is ON the plate — the only thing a photograph settles
 * — and is never asked how much was eaten, since inviting a guess would put an
 * invented number exactly where a tap belongs. So the plate arrives whole and this
 * is how the user says "the four of us shared those spring rolls". The alternative
 * was editing eleven numbers by hand, so instead the user typed "I only ate half
 * of this plate, it was a shared plate" into the meal's notes and hoped. Notes are
 * inert; the day counted the whole dinner.
 *
 * FRACTIONS, NOT A SLIDER: nobody knows they ate 43% of a bowl of pho. The honest
 * resolutions are the ones a table divides into, and a continuum would be false
 * precision on an estimate that is already a range. `custom %` covers what the
 * chips cannot say (chocolate eaten over three days) and is last and quietest.
 *
 * A chip is matched with a tolerance rather than by equality: a third is 0.3333
 * once through a `decimal(5,4)` column and must still read as ⅓, not "33.3%".
 */
const props = defineProps({
  modelValue: { type: Number, default: 1 },

  /** The item-level control: same fractions, a row that fits beside a name. */
  compact: { type: Boolean, default: false },

  /**
   * The plate's own share, for an ITEM control only: an un-overridden item follows
   * it, and tapping the chip the plate is already on means "stop deciding this one
   * separately" — the user's only way to cancel an override.
   */
  plateShare: { type: Number, default: null },

  /** True when this item has been set on its own and no longer follows. */
  overridden: { type: Boolean, default: false },

  label: { type: String, default: 'I ate' },
})

const emit = defineEmits(['update:modelValue', 'release'])

const THIRD = 1 / 3

const CHIPS = [
  { value: 1, label: 'All' },
  { value: 0.75, label: '¾' },
  { value: 0.5, label: '½' },
  { value: THIRD, label: '⅓' },
  { value: 0.25, label: '¼' },
]

/**
 * Close enough to be the same chip. A third stored as 0.3333 is 0.000033 from 1/3;
 * 33 typed as a percentage is 0.0033 away and deliberately NOT the same, because a
 * user who typed a number should see their number. Half a percent splits them.
 */
function matches(a, b) {
  return Math.abs(a - b) < 0.005
}

const isCustom = computed(() => !CHIPS.some((chip) => matches(chip.value, props.modelValue)))

const showCustom = ref(isCustom.value)

const percent = ref(Math.round(props.modelValue * 100))

watch(
  () => props.modelValue,
  (value) => {
    percent.value = Math.round(value * 100)

    if (isCustom.value) showCustom.value = true
  }
)

function pick(value) {
  showCustom.value = false

  // Tapping the plate's own share RELEASES the override rather than pinning the
  // item to the same number: the two diverge the moment the plate changes. "The
  // pho is all mine" must survive the plate moving to ⅓, and "everything on this
  // plate was shared equally" must start following again — without this the second
  // is unsayable and a touched item is stuck at whatever it was touched to.
  if (props.plateShare !== null && props.overridden && matches(value, props.plateShare)) {
    emit('release')

    return
  }

  emit('update:modelValue', value)
}

function applyPercent() {
  const value = Number(percent.value)

  if (!Number.isFinite(value)) return

  // 1–100, clamped here rather than by a native `min`/`max` the browser would
  // enforce by refusing the submit: zero is not a share but a line that should
  // not be in the meal, which the sheet already has a button for.
  emit('update:modelValue', Math.min(100, Math.max(1, Math.round(value))) / 100)
}
</script>

<template>
  <div :class="compact ? 'flex flex-wrap items-center gap-1' : 'flex flex-wrap items-center gap-1.5'">
    <span
      class="shrink-0 text-stone-500 dark:text-stone-400"
      :class="compact ? 'text-[11px]' : 'text-xs font-medium'"
    >{{ label }}</span>

    <div
      class="inline-flex rounded-lg bg-stone-100 p-0.5 font-medium dark:bg-stone-800"
      :class="compact ? 'text-[11px]' : 'text-xs'"
    >
      <button
        v-for="chip in CHIPS"
        :key="chip.label"
        type="button"
        class="rounded-md tabular-nums"
        :class="[
          compact ? 'px-1.5 py-0.5' : 'px-2.5 py-1',
          !showCustom && matches(chip.value, modelValue)
            ? 'bg-white shadow-sm dark:bg-stone-950'
            : 'text-stone-500',
        ]"
        @click="pick(chip.value)"
      >
        {{ chip.label }}
      </button>

      <button
        type="button"
        class="rounded-md"
        :class="[
          compact ? 'px-1.5 py-0.5' : 'px-2.5 py-1',
          showCustom ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500',
        ]"
        @click="showCustom = true"
      >
        %
      </button>
    </div>

    <label v-if="showCustom" class="flex items-center gap-1">
      <input
        v-model="percent"
        type="number"
        inputmode="numeric"
        class="w-14 rounded-lg border border-stone-300 bg-white px-1.5 py-0.5 text-right text-xs tabular-nums
               outline-none focus:border-teal-500 dark:border-stone-700 dark:bg-stone-950"
        @input="applyPercent"
      >
      <span class="text-[11px] text-stone-500">%</span>
    </label>

    <!-- Said out loud, because the rule is not guessable: an item set on its own
         STOPS following the plate's chips and stays put when the plate changes. -->
    <span
      v-if="compact && overridden"
      class="rounded-full bg-teal-50 px-1.5 py-px text-[10px] font-medium text-teal-700
             dark:bg-teal-950/60 dark:text-teal-300"
    >
      just this one
    </span>
  </div>
</template>
