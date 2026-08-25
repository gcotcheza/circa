<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { setSupplementTaken, takeAllSupplements } from '../lib/supplements'

/**
 * The evening's supplements, as ONE line with a button on it.
 *
 * COLLAPSED BY DEFAULT: a row per bottle cost five rows of phone screen,
 * permanently, for a fact that is almost always "yes, all of them", pushing the
 * day's meals and the balance line below the fold. The default is the answer, not
 * the form. The per-bottle rows stay one tap away because the exceptions —
 * skipped the fish oil, ticked one by mistake — must stay repairable.
 *
 * Pressed one-handed in a kitchen, so neither of the two things you can want here
 * needs aiming at: "Take all" is a 44 px pill, everything left of it expands.
 *
 * The flip is IMMEDIATE and local — waiting for a round trip on one bar of 4G
 * would feel broken and get pressed twice. The queue underneath makes that
 * honest: the action is stored either way, so the network decides only when the
 * server hears about it, not whether.
 *
 * THE EVENING STATE IS APPEARANCE ONLY. After ~18:00, on today, with something
 * unticked, the card warms up and counts what is LEFT rather than what is DONE.
 * No notification, badge or sound — see SPEC.md for why push is out of scope;
 * this works by being noticed when the app is opened, which happens every evening
 * anyway because dinner gets logged. `2/4` and `2 still to take` are never on
 * screen together: one line carrying both senses of "2" at eleven pixels in a
 * kitchen gets misread.
 *
 * ON A PAST DAY `needsAttention` is false, so the line stays ordinary grey:
 * yesterday's blank row is a fact, not a nag. The taps still work — that "I
 * forgot to tick it" repair is why this card is per-date, not per-today.
 */
const props = defineProps({
  // The SupplementDay payload: items, takenCount, total, needsAttention.
  supplements: { type: Object, required: true },
  date: { type: String, required: true },
  isToday: { type: Boolean, default: false },
  label: { type: String, default: '' },
})

// The optimistic layer: keyed by supplement id, cleared when fresh props arrive,
// so the server is the last word but the finger is the first. A local `true` the
// server later disagrees with (a 422, a supplement deleted in another tab) is
// corrected on the next reload rather than argued with here.
const pending = ref({})

watch(
  () => props.supplements,
  () => {
    pending.value = {}
  }
)

// Per-visit UI state, deliberately NOT remembered — not saved client-side, not the
// URL, and NOT auto-opened on a past day with a partial count. Paging a week is a
// sequence of DAYS, not of investigations, and a card 52 px tall on Monday and
// 300 px tall on Tuesday reflows the meals, balance line and weight under it on
// every arrow press. The guess is only ever worth one tap.
const expanded = ref(false)

/** True while "Take all" is walking the shelf; guards a double press. */
const busy = ref(false)

const items = computed(() =>
  (props.supplements.items ?? []).map((item) => ({
    ...item,
    taken: item.id in pending.value ? pending.value[item.id] : item.taken,
  }))
)

const takenCount = computed(() => items.value.filter((item) => item.taken).length)

const remaining = computed(() => items.value.length - takenCount.value)

const allTaken = computed(() => items.value.length > 0 && remaining.value === 0)

// The SERVER's `needsAttention` recomputed against the optimistic count: taking
// the last one must clear it immediately rather than at the next reload, because
// that is the moment the card is meant to stop asking.
const wantsAttention = computed(() => props.supplements.needsAttention && remaining.value > 0)

/** The whole collapsed line, after the word "Supplements". See the header. */
const statusText = computed(() => {
  if (allTaken.value) return '— all taken'

  if (wantsAttention.value) return `· ${remaining.value} still to take`

  return `· ${takenCount.value}/${items.value.length}`
})

const statusClass = computed(() => {
  if (allTaken.value) return 'text-teal-700 dark:text-teal-400'

  if (wantsAttention.value) return 'font-medium text-amber-800 dark:text-amber-300'

  return 'text-stone-500 dark:text-stone-400'
})

async function toggle(item) {
  const next = !item.taken

  pending.value = { ...pending.value, [item.id]: next }

  const result = await setSupplementTaken(item.id, props.date, next)

  if (result.ok && !result.queued) {
    // Landed. Refetch rather than trust the local flip for the rest of the
    // session — a reload keeps a second tab, a past day and the trends page
    // telling one story.
    router.reload({ only: ['supplements'], preserveScroll: true })

    return
  }

  if (!result.ok) {
    // Queued stays flipped; a refusal is put back, and the queue's own error
    // line says what happened.
    pending.value = { ...pending.value, [item.id]: item.taken }
  }
}

/**
 * The whole shelf in one press: every unticked bottle flips first and is written
 * after (see lib/supplements.js for why this loops the per-bottle write rather
 * than a bulk endpoint), and only the ones the server REFUSED flip back — three
 * landed and one rejected reads as 3/4, not as nothing having happened.
 */
async function takeAll() {
  if (busy.value) return

  const outstanding = items.value.filter((item) => !item.taken)

  if (outstanding.length === 0) return

  busy.value = true

  pending.value = {
    ...pending.value,
    ...Object.fromEntries(outstanding.map((item) => [item.id, true])),
  }

  const results = await takeAllSupplements(outstanding, props.date)

  const refused = results.filter((result) => !result.ok)

  if (refused.length > 0) {
    pending.value = {
      ...pending.value,
      ...Object.fromEntries(refused.map((result) => [result.id, false])),
    }
  }

  busy.value = false

  // ONE reload for the press, not one per bottle — four racing partial reloads
  // redraw this card four times to answer one question. Nothing landed means
  // everything queued, and the local flip is the truth until it does.
  if (results.some((result) => result.ok && !result.queued)) {
    router.reload({ only: ['supplements'], preserveScroll: true })
  }
}
</script>

<template>
  <section
    class="overflow-hidden rounded-2xl border transition-colors"
    :class="wantsAttention
      ? 'border-amber-300 bg-amber-50/70 dark:border-amber-900/70 dark:bg-amber-950/30'
      : 'border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900'"
  >
    <!-- Nothing on the shelf. Not collapsible; the only useful thing is the way in. -->
    <div v-if="items.length === 0" class="p-4">
      <h2 class="text-sm font-semibold">Supplements</h2>

      <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">
        Nothing on the shelf yet.
      </p>

      <Link
        href="/supplements"
        class="mt-2 inline-block text-xs font-medium text-teal-700 dark:text-teal-400"
      >
        Add your supplements
      </Link>
    </div>

    <template v-else>
      <!-- THE LINE. Left of the pill expands; the pill is the ritual. The heading
           WRAPS the button (accordion pattern): a heading stays in the document
           outline, the whole line is one tap target, the button holds phrasing. -->
      <div class="flex items-stretch">
        <h2 class="flex min-h-[52px] min-w-0 flex-1">
          <button
            type="button"
            class="flex w-full items-center gap-1.5 py-3 pl-4 pr-2 text-left"
            :aria-expanded="expanded"
            aria-controls="supplement-rows"
            @click="expanded = !expanded"
          >
            <!-- The tick: it replaces four rows of ticks with their one fact. -->
            <span
              v-if="allTaken"
              class="grid size-5 shrink-0 place-items-center rounded-full bg-teal-600 text-white dark:bg-teal-500"
            >
              <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5">
                <path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>

            <span class="shrink-0 text-sm font-semibold">Supplements</span>

            <span class="tnum truncate text-xs" :class="statusClass">{{ statusText }}</span>

            <svg
              class="ml-auto size-4 shrink-0 text-stone-400 transition-transform dark:text-stone-500"
              :class="expanded ? 'rotate-180' : ''"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M6 9.5l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
        </h2>

        <!-- Gone once nothing is left to take, rather than sitting disabled: a
             dead button is read and dismissed every evening for a year. -->
        <button
          v-if="!allTaken"
          type="button"
          class="mr-3 inline-flex min-h-[44px] shrink-0 items-center justify-center self-center rounded-full
                 bg-teal-600 px-4 text-xs font-semibold text-white transition
                 active:scale-95 disabled:opacity-60 dark:bg-teal-500"
          :disabled="busy"
          @click="takeAll"
        >
          Take all
        </button>
      </div>

      <!-- The exceptions. `v-show` not `v-if`: the second press is instant,
           thumbnails are not re-fetched on every fold, and the browser skips
           them entirely while shut, the usual case. -->
      <div
        v-show="expanded"
        id="supplement-rows"
        class="border-t px-4 pb-3 pt-2"
        :class="wantsAttention
          ? 'border-amber-200 dark:border-amber-900/70'
          : 'border-stone-200 dark:border-stone-800'"
      >
        <p v-if="!isToday" class="mb-1 text-xs text-stone-500 dark:text-stone-400">
          What was taken on {{ label || date }}. Tap to correct it.
        </p>

        <ul class="-mx-1 space-y-1">
          <li v-for="item in items" :key="item.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-xl px-1 py-2 text-left transition active:scale-[0.99]"
              :class="item.taken ? '' : 'opacity-95'"
              :aria-pressed="item.taken"
              @click="toggle(item)"
            >
              <!-- The tick, at the START so the column reads as what is done. -->
              <span
                class="grid size-9 shrink-0 place-items-center rounded-full border-2 transition"
                :class="item.taken
                  ? 'border-teal-600 bg-teal-600 text-white dark:border-teal-500 dark:bg-teal-500'
                  : 'border-stone-300 text-transparent dark:border-stone-600'"
              >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                  <path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </span>

              <span class="min-w-0 flex-1">
                <span
                  class="block truncate text-sm font-medium"
                  :class="item.taken ? 'text-stone-500 line-through dark:text-stone-500' : ''"
                >{{ item.name }}</span>

                <!-- What one tap MEANS: the dose, so the record is unambiguous. -->
                <span class="block truncate text-[11px] text-stone-500 dark:text-stone-400">
                  <template v-if="item.unitsPerDay > 1">{{ item.unitsPerDay }} ×&nbsp;</template>{{ item.servingText || (item.brand ?? '') }}
                </span>
              </span>

              <img
                v-if="item.photoUrl"
                :src="item.photoUrl"
                alt=""
                class="size-9 shrink-0 rounded-lg object-cover"
                loading="lazy"
              >
            </button>
          </li>
        </ul>

        <!-- Settings: a quiet link inside the opened card, not a nav item — it is
             visited when a bottle runs out, not daily. -->
        <Link
          href="/supplements"
          class="mt-2 inline-block text-xs font-medium text-teal-700 dark:text-teal-400"
        >
          Manage supplements
        </Link>
      </div>
    </template>
  </section>
</template>
