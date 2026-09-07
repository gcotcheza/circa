<script setup>
import { computed } from 'vue'
import { bestEstimate, num, shareLabel } from '../lib/format'

/**
 * One logged meal: when, what, and how much — the "how much" kept as a band per
 * item as well as per meal. The whole card is the edit affordance: a row of small
 * pencil icons is a mis-tap generator on a phone.
 *
 * NO PHOTOGRAPH HERE — user decision, 2026-08-10. A 96 px thumbnail of the first
 * plate meant two meals filled a whole phone screen and the day stopped being
 * scannable, which is the one thing this list is for; the picture is one tap away
 * in the sheet the card opens. `meal.photoUrl` is still on the payload and the
 * thumbnail route still served — see DayCardPhotoTest.
 */
const props = defineProps({
  meal: { type: Object, required: true },
})

defineEmits(['edit'])

const typeLabels = {
  breakfast: 'Breakfast',
  lunch: 'Lunch',
  dinner: 'Dinner',
  snack: 'Snack',
}

// Vision statuses on the card. An analysing or proposed meal is on screen but NOT
// in the day's totals (the rollup counts confirmed meals only); saying so is the
// point — an unmentioned estimate would make the balance above appear wrong.
const statusLabels = {
  analyzing: 'Analysing…',
  proposed: 'Needs your OK',
  failed: 'Analysis failed',
}

const statusClasses = {
  analyzing: 'bg-stone-200 text-stone-600 dark:bg-stone-800 dark:text-stone-300',
  proposed: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  failed: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
}

// The meal's energy as one figure to read, its width kept underneath: the top of
// "588–857" was read as the likely number and meals skipped to make room for it.
const kcal = computed(() => bestEstimate(props.meal.kcal))

// Courses still waiting on the model or a tap. A CONFIRMED meal can hold an
// unconfirmed proposal — photograph the dessert after logging the main course and
// both live on the same meal. `meal.status` stays `confirmed` throughout so the
// main course stays in the day's totals; this is what says the dessert is pending.
const pending = computed(() => (props.meal.photos ?? []).filter((photo) => photo.state !== 'settled').length)

// Once anything on the meal is confirmed, the card lists only what was confirmed;
// the outstanding proposal is announced above and reviewed on its own screen.
// Listing both would show food that is not in the card's (confirmed) kcal figure.
const shownItems = computed(() => {
  const confirmed = props.meal.items.filter((item) => item.confirmedAt !== null)

  return confirmed.length > 0 ? confirmed : props.meal.items
})
</script>

<template>
  <button
    type="button"
    class="w-full rounded-2xl border border-stone-200 bg-white p-3.5 text-left transition
           active:scale-[0.995] dark:border-stone-800 dark:bg-stone-900"
    @click="$emit('edit', meal)"
  >
    <div class="flex items-baseline justify-between gap-3">
      <div class="flex items-baseline gap-2">
        <span class="tnum text-sm font-semibold">{{ meal.time }}</span>
        <span v-if="meal.mealType" class="text-xs text-stone-500 dark:text-stone-400">
          {{ typeLabels[meal.mealType] ?? meal.mealType }}
        </span>
        <span
          v-if="statusLabels[meal.status]"
          class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
          :class="statusClasses[meal.status]"
        >
          {{ statusLabels[meal.status] }}
        </span>
      </div>

      <span v-if="meal.status !== 'analyzing'" class="shrink-0 text-right">
        <span class="tnum text-sm font-semibold">
          {{ kcal.figure }}<span class="ml-1 text-xs font-normal text-stone-500">kcal</span>
        </span>
        <span
          v-if="kcal.width"
          class="tnum block text-[11px] font-normal text-stone-400 dark:text-stone-500"
        >
          {{ kcal.width }}
        </span>
      </span>
    </div>

    <!-- No thumbnail between the header and the food; see the script docblock. -->

    <p
      v-if="meal.status === 'failed' && meal.visionError"
      class="mt-2 text-xs text-rose-700 dark:text-rose-300"
    >
      {{ meal.visionError }}
    </p>

    <!-- A course still waiting. `meal.status` cannot say this: it is the MEAL's
         commitment and never goes backwards out of `confirmed`, so without this
         line an analysing dessert on a confirmed dinner would be invisible. -->
    <p v-if="pending > 0" class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-300">
      {{ pending }} more photo{{ pending === 1 ? '' : 's' }} to review
    </p>

    <ul class="mt-2 space-y-1">
      <li
        v-for="item in shownItems"
        :key="item.id"
        class="flex items-baseline justify-between gap-3 text-sm"
      >
        <span class="truncate text-stone-700 dark:text-stone-300">
          {{ item.name }}
          <span class="text-xs text-stone-400">
            {{ item.portionGMin === item.portionGMax
              ? `${num(item.portionGMin)} g`
              : `${num(item.portionGMin)}–${num(item.portionGMax)} g` }}
          </span>
          <!-- The shared plate, said out loud: without it a half portion is just
               a smaller number, and "140 g of pho" a week later cannot be told
               from a bowl that was split. The fraction is the reason. -->
          <span
            v-if="(item.shareFraction ?? 1) < 1"
            class="rounded-full bg-stone-100 px-1.5 py-px text-[10px] font-medium text-stone-500
                   dark:bg-stone-800 dark:text-stone-400"
          >{{ shareLabel(item.shareFraction) }}</span>
        </span>
        <span class="tnum shrink-0 text-xs text-stone-500 dark:text-stone-400">
          {{ item.kcal.min === item.kcal.max
            ? num(item.kcal.min)
            : `${num(item.kcal.min)}–${num(item.kcal.max)}` }}
        </span>
      </li>
    </ul>

    <!-- The user's own note stays. The MODEL's note does not — see below. -->
    <p v-if="meal.notes" class="mt-2 text-xs italic text-stone-500 dark:text-stone-400">
      {{ meal.notes }}
    </p>

    <!-- NO `meal.modelNotes` HERE, DELIBERATELY: a paragraph of the model's
         reasoning under EVERY card turned the day into a wall of italics and
         pushed the food list off the first screen. Not deleted — it shows where
         the numbers ARE read closely, MealSheet.vue and ProposalReview.vue, both
         opened from this card, so `meal.modelNotes` must stay in
         DailyView::mealProps(), which hands the sheets this same object. -->
  </button>
</template>
