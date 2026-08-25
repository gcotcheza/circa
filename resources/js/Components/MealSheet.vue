<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { holdUpdate } from '../lib/sw'
import { KIND, online, submitOrQueue } from '../lib/queue'
import { useInlineValidation } from '../lib/validation/inline'
import { estimateMeal } from '../lib/vision'
import { densityFor, hasPortion, mid, payloadFor, toPer100g, toQuick } from '../lib/basis'
import { boxText, typedIn } from '../lib/boxes'
import { applyShare, shareOf, unapplyShare } from '../lib/share'
import { decimal } from '../lib/format'
import { errorLine } from '../lib/error-rows'
import ShareChips from './ShareChips.vue'
import MealTypeChips from './MealTypeChips.vue'

/*
 * Async-imported not for size (a few KB) but because a static import would pull
 * `lib/scanner.js`, and through it the decoder's module graph, into the entry
 * chunk the daily view downloads several times a day. Deferring keeps the main
 * bundle within a couple of hundred bytes of its pre-step-4 size.
 */
const BarcodeScanner = defineAsyncComponent(() => import('./BarcodeScanner.vue'))
const ScannedProduct = defineAsyncComponent(() => import('./ScannedProduct.vue'))

/**
 * Create / edit / delete one meal, as a bottom sheet.
 *
 * ---------------------------------------------------------------------------
 * THE UUID IS GENERATED HERE, BEFORE THE ROW EXISTS
 *
 * `crypto.randomUUID()` runs when the sheet opens and is reused by every retry,
 * including one replayed out of the offline queue; the server returns the
 * existing meal for a uuid it has seen, so a double-tap cannot produce two
 * dinners. Generating it on submit would give every retry a new meal, which is
 * why SPEC.md puts client UUIDs in step 3 rather than the later offline step:
 * the guarantee has to exist before the queue that depends on it.
 * ---------------------------------------------------------------------------
 *
 * TWO ENTRY MODES per item, per SPEC's "manual / recent" path:
 *   quick      — a name and a kcal figure. Stored at a 100 g basis.
 *   per 100 g  — a density and a weight. Absolutes derived.
 * Both post the same shape; MealRequest converts server-side, so the arithmetic
 * has one definition. The tabs are TWO VIEWS OF ONE ITEM, NOT TWO FORMS: they
 * used to swap which boxes were rendered, so a per-100 g item showed four empty
 * boxes under Quick — and an empty kcal box honestly reads as "no calories".
 * Switching now CONVERTS (lib/basis.js); a tab writes nothing, and the payload
 * is built at Save from the visible mode.
 * ---------------------------------------------------------------------------
 *
 * THE NUMERIC BOXES ARE `type="text" inputmode="decimal"`, AND THAT IS A FIX
 *
 * "Save changes" did nothing, silently. This is the ONLY <form> in the app with
 * a `type="submit"` button, so it was the only place NATIVE CONSTRAINT VALIDATION
 * ran, and a `step` on boxes the app itself fills with 28.35 g of carbs is a
 * refusal to submit. The rule is that any value this app can render into an input
 * must be one the input hands back: no `step`, `min` or `max` — the plausibility
 * ceilings live in ValidatesMealItems, where a refusal is a SENTENCE in the
 * error list — and `decimal()` (lib/format.js) is the one parser, because the
 * keypad types a COMMA. The form now carries `novalidate` and the item name has
 * given up its `required` too: the browser said nothing a user could read, and
 * the sentence it was standing in for now arrives on blur, in the words the
 * server would have used (lib/validation/inline.js).
 * See docs/rationale-frontend.md § "The silent submit veto, and the comma"
 * ---------------------------------------------------------------------------
 *
 * ONLY THE NAME IS REQUIRED
 *
 * Every number is optional; an item with a name alone is legal and contributes 0
 * to the day. The moment a meal cannot be written down without its calories the
 * app stops being somewhere you write down what you ate — you know you had a
 * chicken curry, you do not know what was in it — and the alternative was
 * inventing a number or logging nothing. Blanks turn the primary action into
 * "Save & estimate", which sends the list to the same model, behind the same
 * review sheet, as a photograph; "Save as-is" stays beside it. The BARCODE path
 * (step 4) fills in this same form rather than being a separate flow — a
 * per-100 g item with `food_product_id` set — so a scan can be edited,
 * re-weighed or re-logged by what already exists.
 * ---------------------------------------------------------------------------
 *
 * SAVING GOES THROUGH THE QUEUE (step 8), ONLINE AND OFF
 *
 * `submitOrQueue` tries the network and falls back to IndexedDB. Online too, so
 * that the bytes the server sees when a meal is replayed off the queue are
 * IDENTICAL to the ones it would have seen at the time, rather than two
 * definitions of one write, one of which nothing exercises; the price is the
 * extra `router.reload()` a fetch needs, which at four meals a day beats a
 * second write path. DELETE IS NOT QUEUED: replaying a deletion is the one
 * action here that destroys something, and "we will retry it later" is not a
 * promise to make about a delete that may be racing an edit from the same phone.
 * Offline, the button says so.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  date: { type: String, required: true },
  meal: { type: Object, default: null },
})

const emit = defineEmits(['close', 'estimating', 'review'])

const isEditing = computed(() => props.meal !== null)

/*
 * "There is a half-typed dinner in here. Do not reload."
 *
 * lib/sw.js now applies a deployed update when the app is backgrounded, not only
 * on a tap of "Updated — reload", because an ignored prompt means a phone
 * running a build from three deploys ago. This sheet is why the original code
 * refused to auto-reload at all, so it is what says when that is unsafe. A
 * watcher, not mounted/unmounted: this component stays mounted for the life of
 * the page and opens on a prop.
 */
let releaseUpdateHold = null

watch(
  () => props.open,
  (open) => {
    if (open && !releaseUpdateHold) {
      releaseUpdateHold = holdUpdate()

      return
    }

    if (!open && releaseUpdateHold) {
      releaseUpdateHold()
      releaseUpdateHold = null
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => releaseUpdateHold?.())

/*
 * ---------------------------------------------------------------------------
 * THE PLATES, AND WHY THIS SHEET HAS TO SHOW THEM
 *
 * This sheet edits ITEMS; everything true of a PHOTOGRAPH — the note for Claude,
 * "Analyse this photo again", "Remove this photo" — lives on the review sheet,
 * which only ever opened on an outstanding plate. So a confirmed meal had no
 * route to any of it: "I want to tell it there were three eggs in that" had no
 * answer, on precisely the meals the user knows most about.
 *
 * A strip, not a second copy of those controls: one surface owns plate-level
 * operations and this reaches it, including on a plate still being analysed.
 * Empty on meals with no photographs, so typed and scanned ones are unchanged.
 * ---------------------------------------------------------------------------
 */
const plates = computed(() => props.meal?.photos ?? [])

const plateStateLabel = {
  analyzing: 'Analysing',
  proposed: 'Needs OK',
  failed: 'Failed',
}

/*
 * ---------------------------------------------------------------------------
 * "I ATE HALF THE CHOCOLATE BAR" — THE SHARE, ON ANY ITEM, AFTER THE FACT
 *
 * The review sheet's plate-level control one level down: per item, on a meal
 * already saved. It is the only way to fraction a BARCODE item, where the packet
 * describes the whole bar and says nothing about how much was eaten.
 *
 * The boxes show what was EATEN and the form holds the plate: the payload
 * carries the whole item plus a fraction, and the server multiplies once. That
 * is why the per-100 g inference below reads `portionFullGMin` — a quick-mode
 * item shared in half is stored at 50 g, which read against the eaten portion
 * would stop looking like the 100 g BASIS it is and come back as a weight nobody
 * stated.
 * ---------------------------------------------------------------------------
 */
function blankItem() {
  return {
    name: '',
    basis: 'absolute',
    share_fraction: 1,
    kcal: null,
    protein: null,
    carbs: null,
    fat: null,
    grams: null,
    kcal_per_100g: null,
    protein_per_100g: null,
    carbs_per_100g: null,
    fat_per_100g: null,
    food_product_id: null,
  }
}

/** An item the user has not started filling in — safe to overwrite. */
function isBlank(item) {
  return item.name.trim() === '' && item.kcal === null && item.kcal_per_100g === null
}

function nowTime() {
  const d = new Date()

  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

const form = useForm({
  uuid: '',
  date: props.date,
  time: nowTime(),
  meal_type: null,
  notes: '',
  items: [blankItem()],
})

/**
 * Items with a name and no calorie figure — the ones an estimate is for. The
 * name is the test: "" with no numbers is the trailing blank row the sheet
 * always keeps open, not a food.
 */
const blanks = computed(() => form.items.filter(
  (item) => item.name.trim() !== '' && numberOf(item, item.basis === 'per_100g' ? 'kcal_per_100g' : 'kcal') === null
))

function numberOf(item, key) {
  const value = item[key]

  return value === null || value === undefined || value === '' ? null : value
}

/**
 * A stored zero read back as an empty box.
 *
 * `meal_items` density columns are NOT NULL, so an item saved with no calories
 * is 0.000 and a genuinely typed 0 is the same 0.000. "You left this blank" is
 * the useful reading of that: it puts the estimate offer in front of a meal
 * saved without its numbers, and being wrong about a real 0 costs nothing.
 *
 * THE SAME RULE RUNS SERVER-SIDE, AND IT HAS TO BE THE SAME RULE.
 * `MealEstimateController::typedItemFrom()` rebuilds "what the user typed" from
 * the same stored row and decides the three ambiguous columns identically:
 *
 *   a zero density  -> blank            (this function)
 *   a ranged value  -> blank            (a range is a previous estimate)
 *   a 100 g portion -> not a weight     (`perHundred` below; it is the BASIS an
 *                                        absolute-mode item is stored at)
 *
 * If they disagree, the boxes on screen and the description sent to the model
 * describe different meals, and the half the user cannot see wins: in production
 * the server read 100 g as a stated portion, so a line typed with no numbers
 * came back pinned at "100 g, 0 kcal".
 */
function orBlank(value) {
  return Number(value) === 0 ? null : value
}

/**
 * The boxes show what was eaten; `item` holds the whole plate (see the share
 * block above). Only the fields that SCALE are read through here — the four
 * totals in quick mode, the weight in per-100 g mode; lib/share.js says why.
 */
function shown(item, key) {
  return applyShare(item[key], shareOf(item))
}

function edit(item, key, raw) {
  const value = decimal(raw)

  inline.clear(fieldOf(item, key))

  // Their text kept verbatim (lib/boxes.js), recorded before the parse so a
  // comma or a half-typed "2." survives a redraw off the number.
  typedIn(item, key, raw)

  if (value === null) {
    item[key] = null

    return
  }

  item[key] = unapplyShare(value, shareOf(item))
}

/**
 * A density is a fact about the FOOD, so no share is applied — the one reason
 * this is separate from `edit`. Same parser as everything else, because the
 * boxes are now plain text (see the input block below).
 */
function editDensity(item, nutrient, raw) {
  inline.clear(fieldOf(item, `${nutrient}_per_100g`))

  typedIn(item, `${nutrient}_per_100g`, raw)

  item[`${nutrient}_per_100g`] = decimal(raw)
}

/**
 * WHAT GOES IN THE BOX, AS AGAINST WHAT THE ITEM HOLDS. The item keeps the
 * number it was given — 130.83333333333334 kcal/100 g, off a total divided over
 * a portion — and the box shows it at the precision its label claims. The
 * unrounded value is posted, because rounding the STATE would make a tab a
 * write; a typed value comes back as typed. The rule is in lib/boxes.js.
 */
function box(item, key) {
  return boxText(item, key, shown(item, key))
}

/** A density is not share-scaled, so it is read straight off the item. */
function densityBox(item, nutrient) {
  return boxText(item, `${nutrient}_per_100g`, item[`${nutrient}_per_100g`])
}

/**
 * The tab, and the conversion that comes with it (lib/basis.js). Nothing is
 * written: the item in memory changes shape, and the server hears about it at
 * Save and not before.
 */
function switchBasis(item, basis) {
  if (item.basis === basis) return

  if (basis === 'absolute') toQuick(item)
  else toPer100g(item)
}

/**
 * A total typed in the Quick view, on an item that has a WEIGHT.
 *
 * The weight is kept and the density re-derived over it, so a weighed portion
 * corrected to a new total stays that weight. Without this the item would be
 * re-filed at the 100 g basis and the weight gone, with nothing on screen having
 * said so. On an item with no weight there is nothing to preserve and this is
 * the old behaviour: the total IS the density, at the basis.
 */
function editTotal(item, nutrient, raw) {
  edit(item, nutrient, raw)

  if (hasPortion(item)) item[`${nutrient}_per_100g`] = densityFor(item[nutrient], item.grams)
}

const canEstimate = computed(() => blanks.value.length > 0)

/*
 * Fixed for the life of this sheet, like the uuid above: it identifies the
 * ESTIMATE, so re-tapping Save & estimate buys one Anthropic call rather than
 * one per tap. Re-generated when the sheet is rebuilt — a different meal.
 */
const estimateKey = ref(crypto.randomUUID())

const estimating = ref(false)

const confirmingDelete = ref(false)

// Local: the save no longer goes through Inertia, so no `form.errors`. The
// delete still does, hence the merge below.
const saving = ref(false)
const localErrors = ref({})

/**
 * MealRequest's own ceilings, said as each box is left, in its own words —
 * which is why no box here carries a native constraint for the browser to veto
 * silently. The name the sentence is filed under is the name the server gives
 * the box, row number included, so a refusal after Save lands in the same place
 * as one before it.
 *
 * BELOW `localErrors` DELIBERATELY: `forget` closes over it, and a closure that
 * reaches backwards past its own declaration works only for as long as nobody
 * calls it during setup.
 */
const inline = useInlineValidation(form, 'MealRequest', {
  // The sheet saves outside Inertia, so a refusal from the server lands in
  // `localErrors` — which is merged LAST and would otherwise hide the fresh
  // sentence about the value that has just changed.
  forget: (field) => delete localErrors.value[field],
})

function fieldOf(item, key) {
  return `items.${form.items.indexOf(item)}.${key}`
}

// Scrolled to on a refusal — see `showErrors`.
const errorList = ref(null)

const processing = computed(() => saving.value || estimating.value || form.processing)

const errors = computed(() => ({ ...form.errors, ...localErrors.value }))

/**
 * Rebuild the form whenever the sheet opens.
 *
 * An existing meal's items come back as ranges; a manual item is zero-width, so
 * either mode reproduces it and the mode is inferred from the portion rather
 * than stored. A RANGE COLLAPSES TO ITS CENTRE, NOT TO ITS FLOOR: taking `.min`
 * from every band meant opening an estimated meal to correct its TIME marked
 * every item down to the bottom of its own range, in silence. `mid()` is the
 * only end that does not editorialise, and the day's headline already uses it.
 *
 * The 100 g BASIS test is now the server's
 * (MealEstimateController::typedItemFrom): zero-width AND exactly 100. A ranged
 * portion of 100–140 g is an estimate that starts at 100, not the basis, and
 * reading it as "quick mode" threw the weight away.
 */
watch(
  () => [props.open, props.meal],
  () => {
    if (!props.open) return

    confirmingDelete.value = false

    if (props.meal) {
      form.defaults({
        uuid: props.meal.uuid,
        date: props.date,
        time: props.meal.time,
        meal_type: props.meal.mealType,
        notes: props.meal.notes ?? '',
        items: props.meal.items.map((item) => {
          // The PLATE's portion, not the eaten one — half of the 100 g basis a
          // quick-mode item is stored at is still that basis.
          const portion = {
            min: item.portionFullGMin ?? item.portionGMin,
            max: item.portionFullGMax ?? item.portionGMax,
          }

          const perHundred = !(portion.min === portion.max && portion.min === 100)

          return {
            name: item.name,
            basis: perHundred ? 'per_100g' : 'absolute',
            share_fraction: item.shareFraction ?? 1,
            // The whole item in both modes; the fraction beside it is how much
            // of it was eaten.
            kcal: perHundred ? null : orBlank(mid(item.kcalFull ?? item.kcal)),
            protein: perHundred ? null : orBlank(mid(item.proteinFull ?? item.protein)),
            carbs: perHundred ? null : orBlank(mid(item.carbsFull ?? item.carbs)),
            fat: perHundred ? null : orBlank(mid(item.fatFull ?? item.fat)),
            grams: perHundred ? mid(portion) : null,
            kcal_per_100g: perHundred ? orBlank(mid(item.kcalPer100g)) : null,
            protein_per_100g: perHundred ? orBlank(mid(item.proteinPer100g)) : null,
            carbs_per_100g: perHundred ? orBlank(mid(item.carbsPer100g)) : null,
            fat_per_100g: perHundred ? orBlank(mid(item.fatPer100g)) : null,
            // Round-tripped: the form REPLACES a meal's items on save, so a
            // provenance link not carried here is dropped on the first edit.
            food_product_id: item.foodProductId ?? null,
          }
        }),
      })
    } else {
      form.defaults({
        uuid: crypto.randomUUID(),
        date: props.date,
        time: nowTime(),
        meal_type: null,
        notes: '',
        items: [blankItem()],
      })
    }

    form.reset()
    form.clearErrors()
    inline.reset()

    estimateKey.value = crypto.randomUUID()
    localErrors.value = {}
  },
  { immediate: true }
)

function addItem() {
  form.items.push(blankItem())
}

// --- barcode -------------------------------------------------------------
// Two overlays, one at a time: the camera, then the product. Mounting
// BarcodeScanner triggers the dynamic import of the wasm decoder, so nothing is
// downloaded until the button is tapped.
const scanning = ref(false)
const scanned = ref(null)

function startScan() {
  scanned.value = null
  scanning.value = true
}

function onDetected(barcode) {
  scanning.value = false
  scanned.value = barcode
}

/**
 * A scanned product becomes an ordinary per-100 g item, replacing the trailing
 * blank row rather than appending: scanning first is the common order, so
 * appending would leave the empty row the form then refuses to submit.
 */
function onScannedItem(item) {
  const last = form.items[form.items.length - 1]

  if (last && isBlank(last)) {
    form.items.splice(form.items.length - 1, 1, item)
  } else {
    form.items.push(item)
  }

  scanned.value = null
}

/**
 * Not in Open Food Facts: type it, with the barcode recorded in the meal's notes
 * because `food_product_id` is a foreign key with no row to point at. Written
 * down, the item can be matched to a product once somebody adds it to OFF.
 */
function onManualFallback(barcode) {
  const note = `barcode ${barcode}`

  form.notes = form.notes ? `${form.notes} · ${note}` : note

  const last = form.items[form.items.length - 1]

  if (!last || !isBlank(last)) form.items.push(blankItem())

  scanned.value = null
}

function removeItem(index) {
  if (form.items.length === 1) {
    form.items.splice(index, 1, blankItem())

    return
  }

  form.items.splice(index, 1)
}

/**
 * Save it, and ask the model for what was left blank.
 *
 * TWO SHAPES, BECAUSE A NEW MEAL AND AN EDIT ARE DIFFERENT REQUESTS. A NEW meal
 * goes to POST /api/meals/estimate in one request that writes the meal, claims
 * the analysis and dispatches the job — the shape the offline queue can replay:
 * one URL, one payload, idempotent on two keys. An EDIT cannot use it, because
 * that endpoint refuses to reopen a meal the user already agreed to; it saves
 * and then asks through the review sheet's "estimate again" endpoint. That
 * second step is NOT queued (lib/vision.js): it needs the meal on the server
 * first, and an estimate arriving tomorrow is not what the button offered.
 *
 * THE THREE THINGS THIS USED TO GET WRONG, none of them in the endpoints
 * (tests/Feature/Vision/EstimateFromEditSheetTest pins 202, `analyzing`, a job):
 *
 *   1. THE PAGE RELOADED UNDERNEATH AN OPEN SHEET. Not needed on the success
 *      path, where the sheet is closing and `onAnalysisStarted` in Day.vue
 *      reloads the meals; needed on the failure path, where the save did
 *      happen — so it moved there.
 *   2. A FAILED SAVE STILL ASKED FOR AN ESTIMATE. `submit()` returns its result
 *      whether or not it worked and only `queued` was checked, so a 422 became a
 *      second request against a meal the server had just refused.
 *   3. NOTHING DISMISSED THE KEYBOARD, which on iOS stays up through a fetch and
 *      hides the sheet closing behind it.
 *
 * The rule now: this sheet closes exactly when there is somewhere to go and
 * something to watch when you get there, and stays open with the reason IN VIEW
 * whenever there is not.
 */
async function saveAndEstimate() {
  if (processing.value) return

  dismissKeyboard()

  if (!isEditing.value) return submit({ estimate: true })

  // No reload: the sheet is about to close and the day reloads itself. See (1).
  const saved = await submit({ close: false, reload: false })

  if (!saved?.ok || saved.queued) {
    // Queued: nothing on the server to estimate against yet, so the day's
    // queued-actions card is the honest place to end up. Failed: the reason is
    // already in `errors`, and (2) is why this no longer walks past it.
    if (saved?.queued) emit('close')
    else showErrors()

    return
  }

  estimating.value = true

  const result = await estimateMeal(props.meal.uuid, crypto.randomUUID())

  estimating.value = false

  if (!result.ok) {
    /*
     * A refusal the user has to be able to act on — already being estimated,
     * every item already has its calories, a rate limit. The sheet stays open
     * BECAUSE of it, so the day underneath still needs the save that succeeded.
     */
    localErrors.value = { estimate: result.message ?? 'That could not be estimated.' }

    router.reload({ preserveScroll: true })

    showErrors()

    return
  }

  // The day view owns the polling and the review sheet, as after a photo
  // upload: it closes this sheet, reloads, and opens the review on the meal.
  emit('estimating', props.meal.uuid)
}

/**
 * Put the reason where the user is looking. The list is at the foot of a sheet
 * usually scrolled into the middle of a long item list, which is how a 422 came
 * to look like nothing happening at all.
 */
function showErrors() {
  nextTick(() => {
    errorList.value?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}

/**
 * iOS keeps the keyboard up across a fetch, and a sheet closing behind one looks
 * like it did not close.
 */
function dismissKeyboard() {
  if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
}

/**
 * The form as it is posted: every item reduced to the canonical shape its
 * VISIBLE mode owns (lib/basis.js). The half a mode does not own is blanked, so
 * toggling out and back posts the IDENTICAL payload — which is what makes
 * looking at an item safe.
 */
function posted() {
  const payload = form.data()

  return { ...payload, items: payload.items.map(payloadFor) }
}

async function submit({ estimate = false, close = true, reload = true } = {}) {
  if (processing.value) return

  dismissKeyboard()

  form.clearErrors()

  localErrors.value = {}
  saving.value = true

  const payload = posted()

  if (estimate) {
    const result = await submitOrQueue({
      // `:estimate` keeps it off a queued plain save or photo upload of the
      // same meal.
      id: `${payload.uuid}:estimate`,
      kind: KIND.mealEstimate,
      url: '/api/meals/estimate',
      method: 'POST',
      payload: { ...payload, idempotency_key: estimateKey.value },
      date: payload.date,
      meta: {
        label: form.items.map((item) => item.name).filter(Boolean).join(', ') || 'Meal to estimate',
        time: payload.time,
      },
    })

    saving.value = false

    if (result.ok) {
      if (result.queued) {
        emit('close')
      } else {
        emit('estimating', payload.uuid)
      }

      return result
    }

    localErrors.value = Object.keys(result.errors ?? {}).length > 0
      ? Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0] ?? messages]))
      : { save: result.message ?? 'That could not be saved.' }

    showErrors()

    return result
  }

  const result = await submitOrQueue({
    /*
     * Derived from the meal uuid, so re-tapping Save on a queued sheet REPLACES
     * that action rather than adding a second. The `:update` suffix keeps an
     * edit off an upload of the same meal's photograph, keyed on the bare uuid.
     */
    id: isEditing.value ? `${props.meal.uuid}:update` : payload.uuid,
    kind: isEditing.value ? KIND.mealUpdate : KIND.mealCreate,
    url: isEditing.value ? `/meals/${props.meal.uuid}` : '/meals',
    method: isEditing.value ? 'PUT' : 'POST',
    payload,
    date: payload.date,
    meta: { time: payload.time },
  })

  saving.value = false

  if (result.ok) {
    // A fetch does not bring the new day back, so ask for it. Nothing to reload
    // when it was only queued (that card renders from the reactive store), nor
    // when the caller has a second request first — re-rendering the day under an
    // open sheet is what put the caret in somebody else's per-100 g box.
    if (!result.queued && reload) router.reload({ preserveScroll: true })

    // `close: false` is the save half of Save & estimate, which has a second
    // request to make first.
    if (close) emit('close')

    return result
  }

  // 422 arrives as a Laravel error bag; everything else as one sentence.
  localErrors.value = Object.keys(result.errors ?? {}).length > 0
    ? Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0] ?? messages]))
    : { save: result.message ?? 'That could not be saved.' }

  return result
}

function destroy() {
  if (!confirmingDelete.value) {
    confirmingDelete.value = true

    return
  }

  form.delete(`/meals/${props.meal.uuid}`, {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  })
}

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex flex-col justify-end">
      <div class="absolute inset-0 bg-stone-900/40 backdrop-blur-[2px]" @click="emit('close')" />

      <div
        class="relative max-h-[92dvh] overflow-y-auto rounded-t-3xl bg-stone-50 pb-[env(safe-area-inset-bottom)]
               dark:bg-stone-950"
      >
        <div
          class="sticky top-0 z-10 flex items-center justify-between border-b border-stone-200 bg-stone-50/95
                    px-4 py-3 backdrop-blur dark:border-stone-800 dark:bg-stone-950/95"
        >
          <button type="button" class="text-sm text-stone-500" @click="emit('close')">Cancel</button>
          <h2 class="text-sm font-semibold">{{ isEditing ? 'Edit meal' : 'Log a meal' }}</h2>
          <button
            type="button"
            class="text-sm font-semibold text-teal-600 disabled:opacity-50 dark:text-teal-400"
            :disabled="processing"
            @click="submit()"
          >
            Save
          </button>
        </div>

        <form class="space-y-4 px-4 py-4" novalidate @submit.prevent="canEstimate ? saveAndEstimate() : submit()">
          <!--
            TWO EQUAL COLUMNS. `grid-cols-2` fixes the TRACKS at half each and
            `min-w-0` on each CELL clears the `min-width: auto` a grid item gets
            by default — but neither beats the UA `min-width` WebKit gives
            `input[type=time]`, which is what the author rules in
            resources/css/app.css are for. The meal type is a chip row
            (MealTypeChips.vue says why) and spans BOTH columns on a second row,
            because "Breakfast · Lunch · Dinner · Snack" wants about 180 px and
            the track is ~175 px on the widest iPhone, ~144 px on the narrowest.
            See docs/rationale-frontend.md § "The time field and the two-column grid"
          -->
          <div class="grid grid-cols-2 gap-2">
            <label class="min-w-0">
              <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Time</span>
              <input
                v-model="form.time"
                type="time"
                :class="[inputClass, 'mt-1 tnum']"
                v-bind="inline.on('time')"
              >
            </label>

            <MealTypeChips v-model="form.meal_type" class="col-span-2 min-w-0" />
          </div>

          <!--
            The plates of this meal, and the way through to what can be said
            about one; see `plates` above. Thumbnails, not originals: 56 px of
            picture wants the 256 px file, and `thumbUrl` outlives retention.
          -->
          <section v-if="plates.length > 0" class="space-y-1.5">
            <div class="flex items-baseline justify-between">
              <span class="text-xs font-medium text-stone-500 dark:text-stone-400">
                {{ plates.length === 1 ? 'Photo' : `${plates.length} photos` }}
              </span>
              <span class="text-[11px] text-stone-400 dark:text-stone-500">
                Tap one to add a note or re-analyse
              </span>
            </div>

            <div class="flex gap-2 overflow-x-auto pb-1">
              <button
                v-for="plate in plates"
                :key="plate.clientId"
                type="button"
                class="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl border border-stone-200
                       bg-stone-100 active:scale-95 dark:border-stone-800 dark:bg-stone-800"
                :aria-label="`Photo ${plate.position + 1}${plate.hint ? ', has a note' : ''}`"
                @click="emit('review', plate.clientId)"
              >
                <img :src="plate.thumbUrl ?? plate.url" alt="" class="h-full w-full object-cover">

                <span
                  class="absolute left-0.5 top-0.5 rounded-full bg-stone-950/70 px-1 text-[10px] text-stone-100"
                >{{ plate.position + 1 }}</span>

                <!-- A plate carrying a note says so, so "did I tell it about
                     the eggs?" is answered by looking rather than by opening
                     all three. -->
                <span
                  v-if="plate.hint"
                  class="absolute right-0.5 top-0.5 rounded-full bg-teal-600/90 px-1 text-[10px] font-semibold text-white"
                  aria-hidden="true"
                >✎</span>

                <span
                  v-if="plateStateLabel[plate.state]"
                  class="absolute inset-x-0 bottom-0 bg-stone-950/70 py-px text-center text-[9px] text-stone-200"
                >{{ plateStateLabel[plate.state] }}</span>
              </button>
            </div>
          </section>

          <div v-for="(item, index) in form.items" :key="index" class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
            <div class="flex items-center gap-2">
              <input
                v-model="item.name"
                type="text"
                placeholder="What was it?"
                :class="inputClass"
                v-bind="inline.on(`items.${index}.name`)"
              >
              <button
                type="button"
                class="shrink-0 rounded-lg px-2 py-2 text-stone-400 active:scale-95"
                aria-label="Remove item"
                @click="removeItem(index)"
              >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path d="M6 7h12M9.5 7V5.5h5V7M8 7l.7 12h6.6L16 7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </button>
            </div>

            <div class="mt-2 inline-flex rounded-lg bg-stone-100 p-0.5 text-xs font-medium dark:bg-stone-800">
              <button
                type="button"
                class="rounded-md px-2.5 py-1"
                :class="item.basis === 'absolute' ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
                @click="switchBasis(item, 'absolute')"
              >
                Quick
              </button>
              <button
                type="button"
                class="rounded-md px-2.5 py-1"
                :class="item.basis === 'per_100g' ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
                @click="switchBasis(item, 'per_100g')"
              >
                Per 100 g
              </button>
            </div>

            <!-- The share, on any item and at any time — days after the meal
                 was logged, on a scan as much as on a typed line. -->
            <div class="mt-2">
              <ShareChips
                compact
                label="I ate"
                :model-value="item.share_fraction ?? 1"
                @update:model-value="item.share_fraction = $event"
              />
            </div>

            <!--
              WHICH ROW IS ON SCREEN IS DECIDED BY THE TAB, AND BY NOTHING ELSE.

              Each row carries its own `v-if` on `item.basis`, exhaustive and
              mutually exclusive. That is not verbosity where a `v-else` would
              do: a `v-else` chained onto the helper paragraph between the two
              rows, so its real condition became

                  NOT (basis === 'absolute' AND hasPortion(item))

              — true for every new Quick line, which therefore rendered BOTH
              rows. Typing "250" into that phantom grams box set `grams` to 2 on
              the first key and unmounted the row mid-word, and `payloadFor`
              reads a Quick item WITH a weight as per-100 g (lib/basis.js), so
              2 g went to the server as a stated portion and the typed kcal was
              dropped.

              THE RULES THIS NOW KEEPS, and QuickModeRowsTest pins them:

                1. In Quick mode the per-100 g row NEVER renders, so a weight
                   can only arrive by converting (the tab) or by loading one.
                2. The mode toggle is the only thing that switches rows: no
                   condition here mentions a VALUE.
                3. Typing never changes which fields exist. A field that can
                   disappear under the cursor eats input, and what it eats is
                   saved.

              The helper line below stays value-dependent: it is a sentence, not
              a field.
            -->

            <!-- The four TOTALS, filled from the per-100 g state when this tab
                 is shown. Typing in one keeps the item's weight — `editTotal`. -->
            <div v-if="item.basis === 'absolute'" class="mt-2 grid grid-cols-4 gap-2">
              <label>
                <span class="text-[11px] text-stone-500">kcal</span>
                <input
                  :value="box(item, 'kcal')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editTotal(item, 'kcal', $event.target.value)"
                  @blur="inline.check(`items.${index}.kcal`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">protein</span>
                <input
                  :value="box(item, 'protein')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editTotal(item, 'protein', $event.target.value)"
                  @blur="inline.check(`items.${index}.protein`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">carbs</span>
                <input
                  :value="box(item, 'carbs')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editTotal(item, 'carbs', $event.target.value)"
                  @blur="inline.check(`items.${index}.carbs`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">fat</span>
                <input
                  :value="box(item, 'fat')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editTotal(item, 'fat', $event.target.value)"
                  @blur="inline.check(`items.${index}.fat`)"
                >
              </label>
            </div>

            <!--
              What the Quick boxes are FOR on an item that was weighed: without
              it a total over a stated portion reads as a claim about 100 g.
              `hasPortion` is true only of a REAL weight — above zero and not the
              100 g basis (lib/basis.js). It said "Totals for the 1 g portion" in
              production because the phantom row above had put a 1 in `grams`.
            -->
            <p v-if="item.basis === 'absolute' && hasPortion(item)" class="mt-1 text-[11px] text-stone-400 dark:text-stone-500">
              These are the totals for the {{ box(item, 'grams') }} g portion, and that weight is kept —
              switch to Per 100 g to change it.
            </p>

            <!-- `editDensity` rather than v-model, only so that every numeric
                 box in this sheet is parsed by one function (lib/format.js). -->
            <div v-if="item.basis === 'per_100g'" class="mt-2 grid grid-cols-5 gap-2">
              <label>
                <span class="text-[11px] text-stone-500">grams</span>
                <input
                  :value="box(item, 'grams')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="edit(item, 'grams', $event.target.value)"
                  @blur="inline.check(`items.${index}.grams`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">kcal/100</span>
                <input
                  :value="densityBox(item, 'kcal')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editDensity(item, 'kcal', $event.target.value)"
                  @blur="inline.check(`items.${index}.kcal_per_100g`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">P/100</span>
                <input
                  :value="densityBox(item, 'protein')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editDensity(item, 'protein', $event.target.value)"
                  @blur="inline.check(`items.${index}.protein_per_100g`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">C/100</span>
                <input
                  :value="densityBox(item, 'carbs')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editDensity(item, 'carbs', $event.target.value)"
                  @blur="inline.check(`items.${index}.carbs_per_100g`)"
                >
              </label>
              <label>
                <span class="text-[11px] text-stone-500">F/100</span>
                <input
                  :value="densityBox(item, 'fat')"
                  type="text"
                  inputmode="decimal"
                  :class="[inputClass, 'tnum']"
                  @input="editDensity(item, 'fat', $event.target.value)"
                  @blur="inline.check(`items.${index}.fat_per_100g`)"
                >
              </label>
            </div>
          </div>

          <div class="flex gap-2">
            <button
              type="button"
              class="flex-1 rounded-xl border border-dashed border-stone-300 py-2.5 text-sm font-medium
                     text-stone-500 active:scale-[0.99] dark:border-stone-700"
              @click="addItem"
            >
              + Add another item
            </button>

            <!-- Explicit tap: it asks for the camera (iOS re-prompts every
                 launch) and downloads the ~1 MB decoder. -->
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-xl border border-teal-600/40 px-3 py-2.5 text-sm
                     font-medium text-teal-700 active:scale-[0.99] dark:border-teal-400/40 dark:text-teal-400"
              @click="startScan"
            >
              <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16" stroke-linecap="round" />
                <path d="M7.5 9v6M10 9v6M12.5 9v6M16.5 9v6" stroke-linecap="round" />
              </svg>
              Scan barcode
            </button>
          </div>

          <label class="block">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Notes</span>
            <input
              v-model="form.notes"
              type="text"
              :class="[inputClass, 'mt-1']"
              v-bind="inline.on('notes')"
            >
          </label>

          <!--
            What Claude said about its own answer. READ-ONLY, and outside the
            Notes box above — that box is the user's voice, and merging the two
            once made Confirm save the model's reasoning as their words. It lives
            here now that the day card no longer prints it (MealCard.vue): the
            card is a glance, this is where the numbers are being read.
          -->
          <p
            v-if="isEditing && meal.modelNotes"
            class="rounded-xl bg-stone-100 px-3 py-2 text-xs italic text-stone-600
                   dark:bg-stone-900 dark:text-stone-400"
          >
            <span class="not-italic font-medium">Claude:</span> {{ meal.modelNotes }}
          </p>

          <ul v-if="Object.keys(errors).length" ref="errorList" class="space-y-1 rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
            <li v-for="(message, key) in errors" :key="key">{{ errorLine(key, message, form.items) }}</li>
          </ul>

          <!--
            The blanks, said out loud before the button that offers to fill
            them: naming the items is what makes "estimate" a concrete offer, so
            the user can see which numbers are about to be a model's opinion.
          -->
          <p
            v-if="canEstimate"
            class="rounded-xl bg-teal-50 px-3 py-2 text-xs text-teal-900 dark:bg-teal-950/60 dark:text-teal-200"
          >
            No calories on
            <span class="font-medium">{{ blanks.map((item) => item.name.trim()).join(', ') }}</span>.
            Claude can estimate the portion and the nutrition as ranges, and you confirm or
            correct them — same as a photo. Or save it blank; it will count as 0 until you fill it in.
          </p>

          <button
            type="submit"
            :disabled="processing"
            class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white
                   transition active:scale-[0.99] disabled:opacity-60"
          >
            <template v-if="canEstimate">
              {{ estimating ? 'Asking Claude…' : saving ? 'Saving…' : 'Save & estimate' }}
            </template>
            <template v-else>{{ isEditing ? 'Save changes' : 'Log meal' }}</template>
          </button>

          <!-- An estimate is an offer, not a toll. -->
          <button
            v-if="canEstimate"
            type="button"
            :disabled="processing"
            class="w-full rounded-xl border border-stone-300 py-2.5 text-sm font-medium text-stone-600
                   active:scale-[0.99] disabled:opacity-60 dark:border-stone-700 dark:text-stone-300"
            @click="submit()"
          >
            Save as-is
          </button>

          <p v-if="canEstimate && !online" class="text-center text-[11px] text-stone-400">
            {{ isEditing
              ? 'Offline — the edit will sync, but asking for an estimate needs a connection.'
              : 'Offline — this will be saved on the phone and estimated as soon as it syncs.' }}
          </p>

          <!-- Deleting is the one write that is NOT queued (see the header), so
               offline it is unavailable rather than pretending to have worked. -->
          <button
            v-if="isEditing"
            type="button"
            class="w-full rounded-xl py-2.5 text-sm font-medium disabled:opacity-50"
            :class="confirmingDelete
              ? 'bg-rose-600 text-white'
              : 'text-rose-600 dark:text-rose-400'"
            :disabled="!online"
            @click="destroy"
          >
            {{ !online ? 'Delete needs a connection' : (confirmingDelete ? 'Tap again to delete' : 'Delete meal') }}
          </button>
        </form>
      </div>
    </div>

    <BarcodeScanner
      v-if="open && scanning"
      @detected="onDetected"
      @close="scanning = false"
    />

    <ScannedProduct
      v-if="open && scanned"
      :barcode="scanned"
      @add="onScannedItem"
      @manual="onManualFallback"
      @rescan="startScan"
      @close="scanned = null"
    />
  </Teleport>
</template>
