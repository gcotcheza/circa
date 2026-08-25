<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import DateJump from '../Components/DateJump.vue'
import BalanceCard from '../Components/BalanceCard.vue'
import ActivityStrip from '../Components/ActivityStrip.vue'
import SleepCard from '../Components/SleepCard.vue'
import WorkoutsCard from '../Components/WorkoutsCard.vue'
import MealCard from '../Components/MealCard.vue'
import MealSheet from '../Components/MealSheet.vue'
import MemoryPicker from '../Components/MemoryPicker.vue'
import QueuedMealCard from '../Components/QueuedMealCard.vue'
import IngestIndicator from '../Components/IngestIndicator.vue'
import SupplementsCard from '../Components/SupplementsCard.vue'
import { startsInsideScroller, swipeIntent } from '../lib/swipe'
// `band` draws the macro line under the Meals heading. It was missing here: an
// unresolved template identifier compiles to `_ctx.band` and throws at render
// time, unhit only because `summary.protein.mid !== null` has been false so far.
import { band, num } from '../lib/format'
import { pollAnalysis } from '../lib/vision'
import { actions } from '../lib/queue'

/* Async-imported like the barcode scanner: a static import would put the canvas
 * re-encoder and upload client into the entry chunk the daily view downloads
 * several times a day, for code unneeded until the camera button is tapped. */
const PhotoCapture = defineAsyncComponent(() => import('../Components/PhotoCapture.vue'))
const ProposalReview = defineAsyncComponent(() => import('../Components/ProposalReview.vue'))

/**
 * The daily view — intake against burn, meals, movement and sleep, for one local
 * calendar day. Navigated by arrow (unambiguous), by swipe (one-handed reach)
 * and by picker (neither of those gets you to March) — all the same partial visit.
 */
const props = defineProps({
  date: String,
  label: String,
  previous: String,
  next: String,
  isToday: Boolean,
  isFuture: Boolean,
  today: String,
  // The header picker's two ends: the first day with any data, and today. Null
  // earliest means an empty database, and the picker is left unbounded.
  earliest: String,
  summary: Object,
  meals: Array,
  sleep: Object,
  // A LIST and never a total: their active kcal is already in
  // `summary.activeKcal` (the Watch recorded it regardless), so nothing on this
  // page may add them to the balance.
  workouts: Array,
  // Quick-add: meal_memory by frecency, with the derived recent list as fallback
  // while memory is empty. MemoryPicker decides which it draws.
  frequentMeals: Array,
  recentMeals: Array,
  photoRetentionDays: Number,
  // {min, mid, max, window}, or null while the estimator is still collecting.
  // The progress story lives on the trends page; here it is a fact or nothing.
  tdee: Object,
  // Last export age, server-computed: the server's clock against the last arrival.
  ingest: Object,
  // For THIS date: the shelf, that day's ticks, and whether the card wants
  // something. The evening flag is server-computed like `ingest` — it states the
  // app's reporting timezone, not the phone's clock.
  supplements: Object,
})

/* Meals this phone is still holding, filtered to the day viewed — a queued lunch
 * belongs under Tuesday even while unsent. Drawn after the real cards and looking
 * different: they are not in the totals above, and pretending otherwise would
 * make the balance look wrong rather than incomplete. */
const queuedForDay = computed(() =>
  actions.value.filter((action) => action.date === props.date)
)

const sheetOpen = ref(false)
const editing = ref(null)

// `reviewingUuid` is a uuid rather than the meal object, so a reload after an
// analysis finishes hands the review screen fresh props, not a stale copy.
const capturing = ref(false)
const reviewingUuid = ref(null)

/* Which PLATE the review sheet opens on. Null is the ordinary photo path — the
 * sheet finds the first outstanding plate itself. Set, it is the client id of a
 * photo tapped in the edit sheet's strip, the only route by which a CONFIRMED
 * meal reaches its plates' notes and "Analyse this photo again" (MealSheet). */
const reviewingPhotoId = ref(null)

function closeReview() {
  reviewingUuid.value = null
  reviewingPhotoId.value = null
}

/* The meal a new course is photographed onto, or null for a new meal. "And then
 * I had pudding" lands on a dinner that may already be confirmed and counted, so
 * the capture sheet must be able to name an existing meal, not always mint a
 * uuid. */
const capturingFor = ref(null)

const capturingForMeal = computed(
  () => props.meals.find((meal) => meal.uuid === capturingFor.value) ?? null
)

const reviewing = computed(
  () => props.meals.find((meal) => meal.uuid === reviewingUuid.value) ?? null
)

function go(date) {
  // `next` is null on today: the arrow disables, a swipe cannot, so refuse here.
  if (!date) return

  router.get('/', { date }, { preserveScroll: true, preserveState: false })
}

function openNew() {
  editing.value = null
  sheetOpen.value = true
}

/**
 * A card opens the screen matching its status: confirmed is an edit, anything
 * the vision pipeline still holds is a review — the ordinary meal sheet would
 * flatten a proposal's ranges to point estimates on save (MealProposalRequest).
 */
/**
 * Is anything here still waiting on the model or a tap? `meals.status` cannot
 * say: it is the MEAL's commitment and never reverts out of `confirmed`, or an
 * analysing dessert would pull the main course out of the day's totals.
 */
function isPending(meal) {
  return (meal.photos ?? []).some((photo) => photo.state !== 'settled')
}

function openMeal(meal) {
  if (isPending(meal) || ['analyzing', 'proposed', 'failed'].includes(meal.status)) {
    reviewingPhotoId.value = null
    reviewingUuid.value = meal.uuid

    return
  }

  editing.value = meal
  sheetOpen.value = true
}

/**
 * A plate tapped in the edit sheet's strip. The edit sheet closes first: two
 * sheets over one state is how a caret ends up in somebody else's box (see
 * MealSheet::submit). The uuid comes from `editing`, the strip's own object.
 */
function openPlate(clientId) {
  const uuid = editing.value?.uuid

  if (!uuid) return

  sheetOpen.value = false
  reviewingPhotoId.value = clientId
  reviewingUuid.value = uuid
}

// --- analysis polling ----------------------------------------------------
// Owned here rather than by the capture sheet, so closing the sheet, navigating
// away or reloading all keep working: the state is server-side in
// `meals[].status` and the poller only notices it changed.
const analyzing = computed(() => props.meals.filter(
  (meal) => meal.status === 'analyzing' || (meal.photos ?? []).some((photo) => photo.state === 'analyzing')
))

let timer = null

function stopPolling() {
  if (timer !== null) clearTimeout(timer)

  timer = null
}

function scheduleTick() {
  stopPolling()

  if (analyzing.value.length === 0) return

  timer = setTimeout(async () => {
    const results = await Promise.all(analyzing.value.map((meal) => pollAnalysis(meal.uuid)))

    /* The poll watches `pending` — plates still analysing or awaiting a tap —
     * because a confirmed dinner with an analysing dessert reports
     * `status: 'confirmed'` throughout, so watching status would poll forever or
     * never start. Any drop earns a refetch, which brings the proposed items, note
     * and error text back in one shape; `only` keeps it to what can change. */
    if (results.some((result) => result?.meal && !hasAnalyzingPlate(result.meal))) {
      router.reload({ only: ['meals', 'summary'] })

      return
    }

    scheduleTick()
  }, 2500)
}

/** Straight off the poll payload — VisionState serialises the same shape. */
function hasAnalyzingPlate(meal) {
  return meal.status === 'analyzing' || (meal.photos ?? []).some((photo) => photo.state === 'analyzing')
}

watch(analyzing, scheduleTick, { immediate: true })

onBeforeUnmount(stopPolling)

function startCapture() {
  capturingFor.value = null
  capturing.value = true
}

/**
 * Photograph another course onto an existing meal. The review sheet closes
 * first: leaving it open over the camera adding the plate would put two screens
 * on the same state.
 */
function addPhotoTo(uuid) {
  closeReview()
  capturingFor.value = uuid
  capturing.value = true
}

/**
 * An analysis started — a photo landed, or a typed meal was sent to be
 * estimated. One handler for both: from here the paths are the same meal in the
 * same state. Every sheet closes, the meals are refetched, and the review opens
 * on what came back, with the poller above taking over.
 *
 * THE REVIEW SHEET OPENS AFTER THE RELOAD. Setting it immediately was invisible
 * on the photo path (a new meal is not in `props.meals` yet), but on the ESTIMATE
 * path the meal is already there with pre-estimate props — still `confirmed`,
 * without the line just typed — so the review showed "Nothing could be estimated
 * from that description" for the whole round trip. If the reload never lands, the
 * day still shows the card analysing, which is honest rather than stale numbers.
 */
function onAnalysisStarted(uuid) {
  capturing.value = false
  capturingFor.value = null
  sheetOpen.value = false

  router.reload({
    only: ['meals', 'summary'],
    onSuccess: () => {
      reviewingUuid.value = uuid
    },
  })
}

// --- swipe ---------------------------------------------------------------
/* Horizontal intent only, and only outside a strip. A mostly-vertical drag is a
 * scroll, and so is a sideways drag inside something that scrolls sideways —
 * that one used to navigate, and since `go()` re-renders the page, dragging the
 * "Log again" strip changed the day and rebuilt every inner scroller at zero.
 * The gesture yields to whatever scroller it started in, found by measurement
 * rather than class name (lib/swipe.js), so new strips are covered unlisted. */
const swipeRoot = ref(null)
const touch = { x: 0, y: 0, exempt: false }

function onTouchStart(event) {
  touch.x = event.changedTouches[0].clientX
  touch.y = event.changedTouches[0].clientY
  touch.exempt = startsInsideScroller(event.target, swipeRoot.value)
}

function onTouchEnd(event) {
  const intent = swipeIntent({
    dx: event.changedTouches[0].clientX - touch.x,
    dy: event.changedTouches[0].clientY - touch.y,
    exempt: touch.exempt,
  })

  if (intent) go(intent === 'previous' ? props.previous : props.next)
}
</script>

<template>
  <Head :title="label" />

  <AppLayout :title="isToday ? 'Today' : label">
    <div ref="swipeRoot" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
      <!-- DATE NAVIGATION -------------------------------------------------
           `[1fr_auto_1fr]` rather than `justify-between`: the sides differ (an
           arrow left; an arrow and "Today" right), and space-between would push
           the date 25 px off centre and move it as the button comes and goes. -->
      <div class="mb-4 grid grid-cols-[1fr_auto_1fr] items-center">
        <button
          type="button"
          class="justify-self-start rounded-full p-2 text-stone-500 active:scale-90 dark:text-stone-400"
          aria-label="Previous day"
          @click="go(previous)"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M15 5l-7 7 7 7" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>

        <!-- The label is the jump control now, with "back to today" its own
             button: one label cannot be both where you are and where you go. -->
        <DateJump
          :date="date"
          :label="label"
          :min="earliest"
          :max="today"
          aria-label="Jump to a date"
          @jump="go"
        />

        <div class="flex items-center justify-self-end">
          <button
            v-if="!isToday"
            type="button"
            class="rounded-lg px-2 py-1 text-sm font-medium text-teal-600 transition
                   hover:bg-stone-200/60 active:scale-95 dark:text-teal-400 dark:hover:bg-stone-800/60"
            @click="go(today)"
          >
            Today
          </button>
          <!-- Holds the width so the date does not shift when you step off
               today. Same trick as the stress page's "Later ›". -->
          <span v-else class="px-2 py-1 text-sm text-transparent select-none" aria-hidden="true">Today</span>

          <button
            type="button"
            class="rounded-full p-2 text-stone-500 active:scale-90 disabled:opacity-30 dark:text-stone-400"
            aria-label="Next day"
            :disabled="isToday"
            @click="go(next)"
          >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
        </div>
      </div>

      <div class="space-y-4">
        <BalanceCard :summary="summary" :is-today="isToday" />

        <!-- The Watch measures what it can see; this is what four weeks of
             eating and weighing imply. Disagreement is information, not a bug. -->
        <p v-if="tdee" class="-mt-1 px-1 text-xs text-stone-500 dark:text-stone-400">
          Your fitted expenditure is
          <span class="tnum font-medium text-stone-700 dark:text-stone-200">
            {{ num(tdee.min) }}–{{ num(tdee.max) }}
          </span>
          kcal/day, from the {{ tdee.window.days }} days to {{ tdee.window.end }}.
        </p>

        <ActivityStrip :summary="summary" />

        <!-- Under the movement numbers because those kcal and kilometres are
             partly THIS. Shown, never summed — see WorkoutsCard. -->
        <WorkoutsCard v-if="workouts.length > 0" :workouts="workouts" />

        <!-- A locked iPhone has no HealthKit access, so an overnight export gap
             is normal — hence the 36 h warning threshold. -->
        <IngestIndicator :ingest="ingest" />

        <!-- Meals -->
        <section class="space-y-2">
          <div class="flex items-baseline justify-between">
            <h2 class="text-sm font-semibold">Meals</h2>
            <span v-if="summary.protein.mid !== null" class="text-xs text-stone-500 dark:text-stone-400">
              P {{ band(summary.protein, 0, 'g') }}
              · C {{ band(summary.carbs, 0, 'g') }}
              · F {{ band(summary.fat, 0, 'g') }}
            </span>
          </div>

          <MealCard
            v-for="meal in meals"
            :key="meal.uuid"
            :meal="meal"
            @edit="openMeal"
          />

          <QueuedMealCard
            v-for="action in queuedForDay"
            :key="action.id"
            :action="action"
          />

          <p
            v-if="meals.length === 0 && queuedForDay.length === 0"
            class="rounded-2xl border border-dashed border-stone-300 px-4 py-6 text-center text-sm
                   text-stone-500 dark:border-stone-700 dark:text-stone-400"
          >
            Nothing logged for this day.
          </p>

          <div class="flex gap-2">
            <button
              type="button"
              class="flex-1 rounded-2xl bg-teal-600 py-3 text-base font-semibold text-white
                     transition active:scale-[0.99]"
              @click="openNew"
            >
              Log a meal
            </button>

            <!-- Explicit tap, always: on iOS it opens the system camera sheet,
                 and a file input rather than getUserMedia is why no permission
                 prompt appears before the shot is even framed. -->
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-2xl border border-teal-600/40 px-4 py-3 text-base
                     font-semibold text-teal-700 active:scale-[0.99] dark:border-teal-400/40 dark:text-teal-400"
              aria-label="Photograph a meal"
              @click="startCapture"
            >
              <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2L9 5h6l1.5 2h2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5v-9Z" stroke-linejoin="round" />
                <circle cx="12" cy="13" r="3.2" />
              </svg>
              Photo
            </button>
          </div>
        </section>

        <!-- Below the meals: the same question about the same evening. Works on
             a past day, so a forgotten tick can be corrected rather than lost. -->
        <SupplementsCard
          v-if="supplements"
          :supplements="supplements"
          :date="date"
          :is-today="isToday"
          :label="label"
        />

        <!-- Quick add: meals eaten most often and most recently, one tap. -->
        <MemoryPicker
          :date="date"
          :frequent-meals="frequentMeals"
          :recent-meals="recentMeals"
        />

        <SleepCard v-if="sleep" :sleep="sleep" />

        <section
          v-if="summary.weightKg !== null"
          class="flex items-baseline justify-between rounded-2xl border border-stone-200 bg-white p-4
                 dark:border-stone-800 dark:bg-stone-900"
        >
          <span class="text-sm font-medium text-stone-500 dark:text-stone-400">Weight</span>
          <span class="tnum text-lg font-semibold">
            {{ num(summary.weightKg, 1) }}<span class="ml-1 text-xs font-normal text-stone-500">kg</span>
          </span>
        </section>
      </div>
    </div>

    <MealSheet
      :open="sheetOpen"
      :date="date"
      :meal="editing"
      @close="sheetOpen = false"
      @estimating="onAnalysisStarted"
      @review="openPlate"
    />

    <PhotoCapture
      v-if="capturing"
      :date="date"
      :label="label"
      :is-today="isToday"
      :meal-uuid="capturingFor"
      :existing-count="capturingForMeal?.photos?.length ?? 0"
      @started="onAnalysisStarted"
      @close="capturing = false; capturingFor = null"
    />

    <ProposalReview
      v-if="reviewing"
      :meal="reviewing"
      :date="date"
      :retention-days="photoRetentionDays"
      :focus-client-id="reviewingPhotoId"
      @add-photo="addPhotoTo"
      @close="closeReview"
    />
  </AppLayout>
</template>
