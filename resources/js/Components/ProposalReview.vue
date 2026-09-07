<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useInlineValidation } from '../lib/validation/inline'
import { estimateMeal, reanalyzePhoto, removeMealPhoto } from '../lib/vision'
import { KIND, online, submitOrQueue } from '../lib/queue'
import { boxText, typedIn } from '../lib/boxes'
import { dominantItem } from '../lib/dominant'
import { applyShare, shareOf, unapplyShare } from '../lib/share'
import { decimal } from '../lib/format'
import { errorLine } from '../lib/error-rows'
import { holdUpdate } from '../lib/sw'
import ShareChips from './ShareChips.vue'
import MealTypeChips from './MealTypeChips.vue'

/**
 * Review what the model proposed, edit it, confirm it.
 *
 * ONE SHEET, TWO WAYS IN. This was PhotoReview and was never photo-specific:
 * what it reviews is a MEAL IN `proposed`, and whether a photograph or a typed
 * description put it there changes four sentences and one button. So
 * `meal.estimateKind` is branched on for WORDING, never behaviour: two review
 * screens that must agree about ranges forever is how one flattens a band.
 *
 * THE FORM SPEAKS IN ABSOLUTES, THE DATABASE IN DENSITIES. `meal_items` stores
 * per-100 g so editing a portion recomputes the item, but nobody reviews a
 * photograph in kcal per 100 g. The form edits the plate — "180-260 kcal of
 * rice, 120-200 g of it" — and the server converts through ProposedItemMapper,
 * the class the analysis job used, so confirming an untouched proposal is a
 * no-op on the numbers, to the gram. EVERY FIELD IS A PAIR, deliberately: a
 * photograph cannot give certainty about a portion, and a lone kcal box invites
 * the user to erase it. Certainty is min = max, what a typed meal claims too.
 *
 * IT REVIEWS ONE PLATE, NOT THE MEAL. Courses are photographed and confirmed on
 * their own, so this sheet is scoped to `entry` and Confirm APPENDS: the meal's
 * whole item list would rewrite the main course the moment the dessert was
 * confirmed. `client_id` scopes the write, `item.photoClientId` selects the
 * lines; `entry` is null on the text path, the server's own null.
 *
 * THE FORM HOLDS THE PLATE, THE BOXES SHOW WHAT WAS EATEN. `form.items` are the
 * model's numbers for the whole plate and are what gets posted; the share rides
 * beside them and the server multiplies once, so tapping ½ visibly halves the
 * screen before anything is confirmed. Storing the halved numbers would mean
 * dividing back out on every change of mind, and a decimal column plus a
 * repeating fraction lose a little each round trip — ½ then ⅓ then All hands
 * back 99.7 g of the model's 100 g. The chip row sets the PLATE and undecided
 * items follow it; one set on its own keeps its value when the plate's chips
 * move ("we shared the rolls, but the pho was all mine"). Overrides are DERIVED.
 *
 * THE NOTE CHANGES NOTHING BUT THE BUTTON. "3 eggs, 120 g drained tuna" is the
 * cheapest way to narrow a range, and it occurs to people while they look at the
 * answer, so it is editable here and on a meal confirmed yesterday. Only asking
 * again can apply it, and that costs money and replaces figures the user may
 * have corrected by hand, so an edit only promotes "Analyse this photo again".
 */
/*
 * This sheet holds edited numbers that exist nowhere else until Confirm, so it
 * holds off the update lib/sw.js now applies on backgrounding rather than on a
 * tap of "Updated — reload": a glance at a notification mid-correction must not
 * come back to an empty sheet. The hold lasts as long as this component.
 */
const releaseUpdateHold = holdUpdate()

onBeforeUnmount(releaseUpdateHold)

const props = defineProps({
  meal: { type: Object, required: true },
  date: { type: String, required: true },
  retentionDays: { type: Number, default: 90 },

  /*
   * WHICH PLATE, WHEN THE USER PICKED ONE. Null opens on whatever is
   * outstanding — the whole of the photo path. Set, it names a usually SETTLED
   * plate: the way in from the edit sheet of a confirmed meal, where everything
   * plate-level had no route at all, on exactly the plates the user knows most
   * about. The server has always allowed it — "confirming twice is not an error,
   * it is an edit" (MealProposalController).
   */
  focusClientId: { type: String, default: null },
})

const emit = defineEmits(['close', 'add-photo'])

const showMacros = ref(false)
const confirmingDelete = ref(false)
const confirmingPhotoRemoval = ref(false)
const reanalyzing = ref(false)
const removing = ref(false)
const actionError = ref(null)

const photos = computed(() => props.meal.photos ?? [])

/**
 * The plate being reviewed: the one asked for, else the first still outstanding
 * — uploads are analysed on arrival, but three courses picked out of the camera
 * roll at once are reviewed in the order taken. `focusClientId` names a
 * photograph, settled or not (the edit sheet's plate strip); a named plate no
 * longer on the meal falls back to the outstanding one, so this never renders as
 * an empty text-path proposal for a meal full of pictures. Null is the text path.
 */
const entry = computed(() => {
  const asked = props.focusClientId === null
    ? null
    : photos.value.find((photo) => photo.clientId === props.focusClientId) ?? null

  return asked ?? photos.value.find((photo) => photo.state !== 'settled') ?? null
})

const outstanding = computed(() => photos.value.filter((photo) => photo.state !== 'settled').length)

/**
 * The lines this sheet owns, scoped by provenance rather than `confirmed_at`: a
 * confirmed meal can hold an unconfirmed plate, and a plate can be re-analysed
 * after it was confirmed. The only question is which photograph a line came off.
 */
const scopedItems = computed(() => props.meal.items.filter(
  (item) => (item.photoClientId ?? null) === (entry.value?.clientId ?? null)
))

// Confirm bypasses Inertia and so never populates `form.errors`; discard still
// does, hence the merge.
const saving = ref(false)
const localErrors = ref({})

const processing = computed(() => saving.value || form.processing)

const errors = computed(() => ({ ...form.errors, ...localErrors.value }))


function fieldOf(item, key) {
  return `items.${form.items.indexOf(item)}.${key}`
}

/*
 * The ENTRY's state, falling back to the meal's on the text path: a confirmed
 * dinner can hold an analysing dessert, and `meals.status` never goes backwards
 * out of `confirmed` so the day's totals stay put while a photo is looked at.
 */
const state = computed(() => entry.value?.state ?? props.meal.status)

const isAnalyzing = computed(() => state.value === 'analyzing')
const hasFailed = computed(() => state.value === 'failed')
const isEmpty = computed(() => scopedItems.value.length === 0)

// A plate under review is a photograph; without one, a typed description.
const fromPhoto = computed(() => entry.value !== null || props.meal.estimateKind !== 'text')

const canDiscardMeal = computed(() => !props.meal.items.some((item) => item.confirmedAt !== null))

const wording = computed(() => fromPhoto.value
  ? {
      working: 'Analysing…',
      workingTitle: 'Claude is looking at the photo.',
      workingBody: 'This usually takes ten to thirty seconds. You can close this and come back — nothing is lost, and the meal will be waiting with its estimate.',
      failedTitle: 'The analysis did not finish.',
      failedBody: 'You can try the same photo again, or discard this and log the meal by hand.',
      emptyTitle: 'No food found in that photo.',
      emptyBody: 'Discard it and take another, or add the items yourself.',
      again: 'Analyse this photo again',
      againWithNote: 'Analyse this photo again with your note',
      againBusy: 'Sending the photo again…',
      againOffline: 'Re-analysing needs a connection',
    }
  : {
      working: 'Estimating…',
      workingTitle: 'Claude is working out the numbers.',
      workingBody: 'This usually takes ten to thirty seconds. You can close this and come back — what you typed is saved, and the meal will be waiting with its estimate.',
      failedTitle: 'The estimate did not finish.',
      failedBody: 'You can ask again, or confirm the meal exactly as you typed it — nothing you entered has been lost.',
      emptyTitle: 'Nothing could be estimated from that description.',
      emptyBody: 'Add a bit more detail and ask again, or fill the numbers in yourself.',
      again: 'Estimate this again',
      againWithNote: 'Estimate this again',
      againBusy: 'Asking again…',
      againOffline: 'Estimating needs a connection',
    })

// Re-running is offered on a plate whose full-size original still exists, and
// on any typed meal (there is nothing retention can take away from a sentence).
const canRerun = computed(() => entry.value ? entry.value.canReanalyze : !fromPhoto.value)

// The model's account of this plate's own answer, so three courses analysed in
// sequence do not all quote the note from the third.
const modelNotes = computed(() => entry.value?.modelNotes ?? props.meal.modelNotes)

const entryError = computed(() => entry.value?.error ?? props.meal.visionError)

function blankItem() {
  return {
    name: '',
    confidence: null,
    memory_adjusted: false,
    // A hand-added line describes what the user ate, so it starts whole whatever
    // the plate is set to — not half of something the model missed.
    share_fraction: 1,
    portion_g_min: null,
    portion_g_max: null,
    kcal_min: null,
    kcal_max: null,
    protein_g_min: null,
    protein_g_max: null,
    carbs_g_min: null,
    carbs_g_max: null,
    fat_g_min: null,
    fat_g_max: null,
  }
}

/**
 * A proposed item in the shape the server expects back. `item.kcal` etc. are
 * already the ABSOLUTE ranges for the stored portion (DailyView derives them
 * from the densities), so this is a rename, not a recalculation.
 */
function toFormItem(item) {
  return {
    // The row this line came off, so an edit can be told from a proposal (see
    // `statedItems`). Not read back: MealProposalRequest ignores unknown keys.
    id: item.id ?? null,
    name: item.name,
    confidence: confidenceWord(item.confidence),
    // So the row can say which numbers came from history rather than from the
    // photograph. Not read back: after Confirm the numbers are the user's.
    memory_adjusted: item.memoryAdjusted === true,

    // How much was eaten, and then the PLATE — never the halved copy. The `*Full`
    // bands are the numbers beside them on every unshared item, nearly all of them.
    share_fraction: item.shareFraction ?? 1,
    portion_g_min: item.portionFullGMin ?? item.portionGMin,
    portion_g_max: item.portionFullGMax ?? item.portionGMax,
    kcal_min: (item.kcalFull ?? item.kcal).min,
    kcal_max: (item.kcalFull ?? item.kcal).max,
    protein_g_min: (item.proteinFull ?? item.protein).min,
    protein_g_max: (item.proteinFull ?? item.protein).max,
    carbs_g_min: (item.carbsFull ?? item.carbs).min,
    carbs_g_max: (item.carbsFull ?? item.carbs).max,
    fat_g_min: (item.fatFull ?? item.fat).min,
    fat_g_max: (item.fatFull ?? item.fat).max,
  }
}

/** How much of the plate the model holds was eaten — lib/share.js. */
function shown(item, key) {
  return applyShare(item[key], shareOf(item))
}

/**
 * That figure as the box's TEXT, at the precision its label claims: kcal whole,
 * grams to a tenth — a model's answer arrives at the three decimals `meal_items`
 * stores, and 163.333 g twice in a min–max pair on half a phone is unreadable.
 * The item keeps every digit; a typed figure comes back typed (lib/boxes.js).
 */
function box(item, key) {
  return boxText(item, key, shown(item, key))
}

/**
 * The boxes are `type="text" inputmode="decimal"` and parsed here, for the
 * reasons MealSheet.vue writes out: model numbers carry three decimals, which
 * `type="number"` with a `step` calls invalid, and the keypad they are corrected
 * on types a comma. `decimal()` (lib/format.js) is the one parser.
 */
function edit(item, key, raw) {
  const value = decimal(raw)

  // Both ends: `dragOtherEnd` below can move the partner box, and a sentence
  // about the number it used to hold would outlive it.
  inline.clear(fieldOf(item, key))
  inline.clear(fieldOf(item, partnerOf(key)))

  // Their text kept verbatim (lib/boxes.js); only the model's figures are rounded.
  typedIn(item, key, raw)

  if (value === null) {
    item[key] = null

    return
  }

  item[key] = unapplyShare(value, shareOf(item))

  dragOtherEnd(item, key)
}

/**
 * ONE END OF A RANGE MOVED PAST THE OTHER: TAKE THE OTHER END WITH IT. Raise
 * the minimum above the maximum and the maximum comes up to meet it; drop the
 * maximum below the minimum and the minimum comes down. The last number typed
 * is honoured exactly and the pair is always a range — usually the zero-width
 * one, which is precisely the claim "it was 250 g" is. Without it, a stored 2 g
 * portion corrected to 250 left the sheet reading "portion 250 – 2", a
 * NEGATIVE-width band every screen downstream had to cope with. Only pairs are
 * touched, and only when both ends have a value: a cleared box is "I do not
 * know this end", not a bound to drag anything to.
 * See docs/rationale-frontend.md § "Correcting a range, and asking again with it"
 */
/** The other end of the pair this box is one of, or the box itself if it is not one. */
function partnerOf(key) {
  return key.endsWith('_min') ? key.replace(/_min$/, '_max') : key.replace(/_max$/, '_min')
}

function dragOtherEnd(item, key) {
  const isMin = key.endsWith('_min')

  if (!isMin && !key.endsWith('_max')) return

  const partner = partnerOf(key)

  if (!(partner in item)) return

  const value = item[key]
  const other = item[partner]

  if (value === null || other === null || other === undefined) return

  if (isMin ? value > other : value < other) item[partner] = value
}

/*
 * WHAT THE USER HAS CHANGED SINCE THE ANSWER ARRIVED. The ask carries the
 * sheet: `asLoaded` is what arrived, keyed by row id, and anything differing
 * from it is the user's — a GIVEN in the next request, echoed back untouched
 * like a figure typed into the meal sheet, while everything untouched stays a
 * question. Two rules decide what counts, both deliberate: TOUCHED (an
 * untouched range is not a claim, it is the answer being questioned) and
 * ZERO-WIDTH (min === max, since 150-200 g is still uncertainty and a given is
 * a number).
 * See docs/rationale-frontend.md § "Correcting a range, and asking again with it"
 */
const STATED_FIELDS = [
  ['portion_g', 'portion_g_min', 'portion_g_max'],
  ['kcal', 'kcal_min', 'kcal_max'],
  ['protein_g', 'protein_g_min', 'protein_g_max'],
  ['carbs_g', 'carbs_g_min', 'carbs_g_max'],
  ['fat_g', 'fat_g_min', 'fat_g_max'],
]

const asLoaded = ref(loadedById())

function loadedById() {
  const byId = new Map()

  scopedItems.value.forEach((item) => {
    if (item.id !== null && item.id !== undefined) byId.set(item.id, toFormItem(item))
  })

  return byId
}

/**
 * One pair of boxes as a claim, or null while it is still a question. Compared
 * PLATE against PLATE on both sides — `form.items` and the snapshot both hold
 * it — so tapping a share does not make every number on the line look edited.
 */
function statedValue(item, before, minKey, maxKey) {
  const min = item[minKey]
  const max = item[maxKey]

  if (min === null || min === undefined || max === null || max === undefined) return null

  // A line the user added by hand has no `before`: every number on it is theirs.
  const touched = before === undefined
    || Number(before[minKey]) !== Number(min)
    || Number(before[maxKey]) !== Number(max)

  if (!touched) return null

  return Number(min) === Number(max) ? Number(min) : null
}

/** The sheet as the next request's givens, or null when nothing has been stated. */
function statedItems() {
  const items = form.items.map((item) => {
    const before = asLoaded.value.get(item.id)

    const stated = {
      name: item.name,
      share_fraction: item.share_fraction ?? 1,
    }

    STATED_FIELDS.forEach(([key, minKey, maxKey]) => {
      stated[key] = statedValue(item, before, minKey, maxKey)
    })

    return stated
  })

  /*
   * Nothing corrected, nothing to say; sending the sheet anyway would hand the
   * model its own numbers as though a person typed them. Omitted, the server
   * rebuilds the question from the user's input (MealEstimateController::typedFrom).
   */
  const stated = items.some((item) => STATED_FIELDS.some(([key]) => item[key] !== null))

  return stated ? items : null
}

/** The plate's chip row, and what every item that has not been overridden follows. */
const entryShare = ref(1)

const SHARE_TOLERANCE = 0.005

function isOverridden(item) {
  return Math.abs((item.share_fraction ?? 1) - entryShare.value) >= SHARE_TOLERANCE
}

/**
 * The plate's share changed: move everything that was following it. Items the
 * user set individually are left alone — re-deciding for them would silently
 * undo "the pho was all mine" the next time they nudged the plate.
 */
function setEntryShare(fraction) {
  const following = form.items.filter((item) => !isOverridden(item))

  entryShare.value = fraction

  following.forEach((item) => {
    item.share_fraction = fraction
  })
}

/** This one line only, from here on. */
function setItemShare(item, fraction) {
  item.share_fraction = fraction
}

function releaseItem(item) {
  item.share_fraction = entryShare.value
}

/** The note as it will be sent: trimmed, and `''` when the box was emptied. */
const hintValue = computed(() => (form.hint ?? '').trim())

/**
 * The note has changed since this answer was produced — the ONLY thing an edit
 * does on its own. It promotes "Analyse this photo again" and changes what that
 * says, so spending a call on the new information is the writer's choice.
 */
const hintChanged = computed(() => hintValue.value !== (entry.value?.hint ?? ''))

/**
 * THE ONE LINE WORTH ANSWERING FOR. Ranges combine in quadrature, so a PLATE's
 * spread is usually one item's (lib/dominant.js) — 820–1380 of rolls beside
 * 75–135 of soup — and a count or a weight is the one thing the model takes as
 * fact rather than re-guessing (PromptV3Photo). The plate, not the meal: this
 * sheet reviews one photograph, and `form.items` is that photograph's lines.
 */
const dominant = computed(() => (canRerun.value && entry.value ? dominantItem(form.items) : null))

// Asked about BY NAME: the name is what goes into the note, and what survives a
// re-analysis renumbering the rows. An unnamed line cannot be asked about.
const dominantName = computed(() => (dominant.value === null ? '' : (form.items[dominant.value.index]?.name ?? '').trim()))

/*
 * Answered once, not asked twice: a re-analysis usually leaves the same item
 * widest, and asking again for a count already given reads as not listening.
 * Local to the sheet because the answer itself is in the note the server keeps.
 */
const narrowedNames = ref(new Set())

const narrowAnswer = ref('')

// Where focus goes when this block unmounts, and where the answer landed.
const hintBox = ref(null)

const narrowError = ref('')

const showNarrow = computed(() => dominantName.value !== '' && !narrowedNames.value.has(dominantName.value))

const narrowShare = computed(() => (dominant.value === null ? 0 : Math.round(dominant.value.share * 100)))

/**
 * The answer joins the note the model is already handed, and asks again through
 * the path a typed note takes — one hint, one re-analysis. Past the note's
 * ceiling it stops with the server's own sentence rather than spending a call
 * on a request that would come back refused.
 */
async function narrow() {
  const answer = narrowAnswer.value.trim()

  // An empty box refused in silence hides the reason it refused (C12), and the
  // button stays enabled so the sentence is what explains it.
  if (answer === '') {
    narrowError.value = 'Say how many there were, or what it weighed — for example 8 pieces, or 180 g.'

    return
  }

  if (!showNarrow.value || reanalyzing.value || !online.value) return

  narrowError.value = ''

  const name = dominantName.value
  const clause = `${name}: ${answer}`
  const note = hintValue.value

  // Renaming an item and answering again would otherwise stack near-duplicate
  // clauses toward the note's 500-character ceiling.
  if (note === '') form.hint = clause
  else if (!note.includes(clause)) form.hint = `${note}; ${clause}`

  // The pair a typed box sends (lib/validation/inline.js): the note has been
  // written into, so from here it is judged the way the user's own typing is.
  inline.clear('hint')
  inline.check('hint')

  if (form.errors.hint) return

  /*
   * The note keeps the answer whatever the ask does — a refused re-analysis
   * leaves it for the button below to spend. Only the SUPPRESSION waits for an
   * ask that actually left, or one lost connection would silence the nudge for
   * the rest of the sheet's life.
   */
  const asked = await rerun()

  if (!asked) return

  // This block is about to unmount: focus goes to the note the answer landed
  // in, rather than to the top of the document.
  hintBox.value?.focus()

  narrowedNames.value.add(name)
  narrowAnswer.value = ''
}

/** 0.3 / 0.6 / 0.9 back to the word the model actually chose. */
function confidenceWord(value) {
  if (value === null || value === undefined) return null

  if (value < 0.45) return 'low'
  if (value < 0.75) return 'medium'

  return 'high'
}

const form = useForm({
  date: props.date,
  time: props.meal.time,
  meal_type: props.meal.mealType,
  notes: props.meal.notes ?? '',
  // Which plate this confirm is about; null (text entry) is the server's null.
  client_id: entry.value?.clientId ?? null,
  /*
   * The PLATE's share, stored on `meal_photos` and never used to scale — the
   * items below carry their own fractions. It is what a re-analysis re-applies,
   * and what puts the chip row back on ½ when this meal is reopened tomorrow.
   */
  share_fraction: entry.value?.shareFraction ?? 1,
  /*
   * The plate's note, carried by the confirm as well as by the re-analysis: the
   * two taps are independent, and somebody who writes "that was 120 g of drained
   * tuna" and then decides the numbers are close enough has still said something
   * true. Stripped from the payload when there is no plate — see confirm().
   */
  hint: entry.value?.hint ?? '',
  items: scopedItems.value.map(toFormItem),
})

/**
 * MealProposalRequest's own ceilings, said as each box is left — including a
 * range that ends below where it starts. Lands in `form.errors`, beside a refusal after Confirm.
 */
const inline = useInlineValidation(form, 'MealProposalRequest', {
  // Confirm bypasses Inertia; without this, a stale `localErrors` refusal would outlive the value and hide the fresh one.
  forget: (field) => delete localErrors.value[field],
})

entryShare.value = form.share_fraction

// Fresh props land every time the day reloads (after an analysis finishes, for
// instance), so the form follows the meal rather than the shape it was built with.
watch(
  () => props.meal,
  (meal) => {
    form.defaults({
      date: props.date,
      time: meal.time,
      meal_type: meal.mealType,
      notes: meal.notes ?? '',
      client_id: entry.value?.clientId ?? null,
      share_fraction: entry.value?.shareFraction ?? 1,
      hint: entry.value?.hint ?? '',
      items: scopedItems.value.map(toFormItem),
    })

    form.reset()

    // The chip row is a fact about the plate, and the plate just re-rendered.
    entryShare.value = form.share_fraction

    // So is the snapshot an edit is measured against: a fresh answer has no edits.
    asLoaded.value = loadedById()

    form.clearErrors()
    inline.reset()

    // A part-typed answer is about the numbers that have just been replaced;
    // `narrowedNames` deliberately survives, since the question was answered.
    narrowAnswer.value = ''
    narrowError.value = ''
    localErrors.value = {}
    confirmingDelete.value = false
    confirmingPhotoRemoval.value = false
  }
)

function addItem() {
  form.items.push(blankItem())
}

function removeItem(index) {
  form.items.splice(index, 1)
}

/**
 * Confirm, through the offline queue (step 8). A confirmation can only exist
 * for a meal the server already has, so replaying one is a PUT of the complete
 * desired state onto a row that is definitely there — the safest queued action,
 * a no-op on the numbers the second time. The action id carries the meal, the
 * verb AND the plate: two courses confirmed offline are two writes, and an id
 * naming only the meal would have the dessert overwrite the main course.
 */
async function confirm() {
  if (saving.value) return

  form.clearErrors()

  localErrors.value = {}
  saving.value = true

  const payload = form.data()

  /*
   * No plate, no note: the server reads an ABSENT `hint` as "the sheet did not
   * say", which is what keeps this confirm, and a legacy one, from wiping a note
   * off a plate it was never about.
   */
  if (!entry.value) delete payload.hint

  const result = await submitOrQueue({
    id: `${props.meal.uuid}:confirm:${entry.value?.clientId ?? 'typed'}`,
    kind: KIND.mealConfirm,
    url: `/meals/${props.meal.uuid}/proposal`,
    method: 'PUT',
    payload,
    date: props.date,
    meta: {
      label: form.items.map((item) => item.name).filter(Boolean).join(', ') || 'Confirmed meal',
      time: form.time,
    },
  })

  saving.value = false

  if (result.ok) {
    if (!result.queued) router.reload({ preserveScroll: true })

    emit('close')

    return
  }

  localErrors.value = Object.keys(result.errors ?? {}).length > 0
    ? Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0] ?? messages]))
    : { confirm: result.message ?? 'That could not be confirmed.' }
}

/**
 * Ask the model again — same photograph, or same description. A NEW idempotency
 * key on purpose: "try again" and "the same request arrived twice" are different
 * intentions, and two rows against one input is what `vision_requests` compares.
 */
async function rerun() {
  reanalyzing.value = true
  actionError.value = null

  /*
   * The numbers corrected but not confirmed, sent with the ask: everything the
   * user has said since the last answer has to travel with the request that
   * replaces it. A lost correction costs the one number that was never a guess.
   */
  const stated = statedItems()

  const result = entry.value
    // The chip row and the note ride along too, so a re-analysed shared plate comes
    // back shared and the model is told the note written thirty seconds ago: "I ate
    // half of this, and by the way it was 120 g of tuna" has no Confirm between.
    ? await reanalyzePhoto(props.meal.uuid, entry.value.clientId, crypto.randomUUID(), entryShare.value, hintValue.value, stated)
    : await estimateMeal(props.meal.uuid, crypto.randomUUID(), stated)

  reanalyzing.value = false

  if (!result.ok) {
    actionError.value = result.message

    return false
  }

  // The day view owns the polling; reloading hands this meal back as `analyzing`.
  router.reload({ only: ['meals', 'summary'] })

  return true
}

function discard() {
  if (!confirmingDelete.value) {
    confirmingDelete.value = true

    return
  }

  form.delete(`/meals/${props.meal.uuid}`, {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  })
}

/**
 * Take this plate off the meal. Its proposal goes with it — nobody agreed to it,
 * and it would leave a review sheet with no picture behind it. Anything already
 * CONFIRMED off the plate stays: deleting a photograph is a statement about the
 * photograph, so the server keeps the food and nulls its provenance.
 */
async function removePhoto() {
  if (!entry.value) return

  if (!confirmingPhotoRemoval.value) {
    confirmingPhotoRemoval.value = true

    return
  }

  removing.value = true
  actionError.value = null

  const result = await removeMealPhoto(props.meal.uuid, entry.value.clientId)

  removing.value = false
  confirmingPhotoRemoval.value = false

  if (!result.ok) {
    actionError.value = result.message

    return
  }

  // The meal may be gone entirely (its only plate, nothing confirmed), so the
  // sheet closes and lets the day re-render rather than guessing.
  if (outstanding.value <= 1) emit('close')

  router.reload({ only: ['meals', 'summary'] })
}

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'

const confidenceLabel = { low: 'low confidence', medium: 'medium confidence', high: 'high confidence' }
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex flex-col justify-end">
      <div class="absolute inset-0 bg-stone-900/40 backdrop-blur-[2px]" @click="emit('close')" />

      <div class="relative max-h-[92dvh] overflow-y-auto rounded-t-3xl bg-stone-50 pb-[env(safe-area-inset-bottom)] dark:bg-stone-950">
        <div
          class="sticky top-0 z-10 flex items-center justify-between border-b border-stone-200 bg-stone-50/95
                    px-4 py-3 backdrop-blur dark:border-stone-800 dark:bg-stone-950/95"
        >
          <button type="button" class="text-sm text-stone-500" @click="emit('close')">Close</button>
          <!--
            "Review the estimate" is wrong on a settled plate the user came back
            to in order to write a note; nothing else about the sheet changes.
          -->
          <h2 class="text-sm font-semibold">
            {{ isAnalyzing ? wording.working
              : hasFailed ? 'Estimate failed'
                : entry?.state === 'settled' ? 'This photo'
                  : 'Review the estimate' }}
            <span v-if="entry && photos.length > 1" class="ml-1 font-normal text-stone-400">
              photo {{ entry.position + 1 }} of {{ photos.length }}
            </span>
          </h2>
          <button
            v-if="!isAnalyzing && !hasFailed"
            type="button"
            class="text-sm font-semibold text-teal-600 disabled:opacity-50 dark:text-teal-400"
            :disabled="processing || form.items.length === 0"
            @click="confirm"
          >
            Confirm
          </button>
          <span v-else class="w-14" />
        </div>

        <div class="space-y-4 px-4 py-4">
          <img
            v-if="entry"
            :src="entry.url"
            alt="The course you photographed"
            class="max-h-56 w-full rounded-2xl object-cover"
          >
          <!--
            Nothing outstanding, so the first plate stands for the meal.
            `photos[0].url`, not the 192 px THUMBNAIL `meal.photoUrl`, which is
            a smear across a phone. (That was the day card's picture until
            2026-08-10; still on the payload, read by nothing — see MealCard.vue.)
          -->
          <img
            v-else-if="photos.length > 0"
            :src="photos[0].url"
            alt="The meal you photographed"
            class="max-h-56 w-full rounded-2xl object-cover"
          >

          <!--
            Every course of this meal in the order taken, the one being reviewed
            marked — what makes a dinner legible as a series.
          -->
          <div v-if="photos.length > 1" class="flex gap-2 overflow-x-auto pb-1">
            <div
              v-for="photo in photos"
              :key="photo.clientId"
              class="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl border-2"
              :class="photo.clientId === entry?.clientId
                ? 'border-teal-500'
                : 'border-stone-200 dark:border-stone-800'"
            >
              <!-- 64 px of picture wants the 256 px file, not the 768 px one. -->
              <img :src="photo.thumbUrl ?? photo.url" alt="" class="h-full w-full object-cover">

              <span class="absolute left-0.5 top-0.5 rounded-full bg-stone-950/70 px-1 text-[10px] text-stone-100">
                {{ photo.position + 1 }}
              </span>

              <span
                v-if="photo.state !== 'settled'"
                class="absolute inset-x-0 bottom-0 bg-stone-950/70 py-px text-center text-[9px] text-stone-200"
              >
                {{ photo.state === 'analyzing' ? '…' : photo.state === 'failed' ? '!' : 'new' }}
              </span>
            </div>

            <button
              type="button"
              class="grid h-16 w-16 shrink-0 place-items-center rounded-xl border-2 border-dashed
                     border-stone-300 text-xl text-stone-400 dark:border-stone-700"
              aria-label="Add another course"
              @click="emit('add-photo', meal.uuid)"
            >
              +
            </button>
          </div>

          <!--
            THE NOTE ON THIS PLATE, under the photograph because that is what it is
            about — not the Notes box at the bottom, which is about the MEAL and never
            reaches the model. Offered in every state but `analyzing`: most useful
            when the answer came back wrong or empty, useless while the previous
            version of it is still in flight.
          -->
          <label v-if="entry && !isAnalyzing" class="block">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">
              What you know about this photo <span class="font-normal opacity-70">optional</span>
            </span>
            <input
              ref="hintBox"
              v-model="form.hint"
              type="text"
              enterkeyhint="done"
              placeholder="e.g. “3 eggs, 120 g drained tuna”"
              :class="[inputClass, 'mt-1']"
              v-bind="inline.on('hint')"
              @keyup.enter="$event.target.blur()"
            >
            <span
              class="mt-1 block text-[11px]"
              :class="hintChanged ? 'text-teal-700 dark:text-teal-400' : 'text-stone-500 dark:text-stone-400'"
            >
              {{ hintChanged
                ? 'Saved with this plate. Analyse the photo again to have Claude use it — the numbers below were worked out without it.'
                : 'What you say the food is, and any amount you counted or weighed, is used as given.' }}
            </span>
          </label>

          <div v-if="isAnalyzing" class="rounded-2xl border border-stone-200 bg-white p-4 text-sm dark:border-stone-800 dark:bg-stone-900">
            <p class="font-medium">{{ wording.workingTitle }}</p>
            <p class="mt-1 text-stone-500 dark:text-stone-400">{{ wording.workingBody }}</p>
          </div>

          <div v-else-if="hasFailed" class="space-y-3">
            <div class="rounded-2xl bg-rose-100 p-4 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
              <p class="font-medium">{{ wording.failedTitle }}</p>
              <p class="mt-1">{{ entryError ?? 'Something went wrong on the way to the model.' }}</p>
            </div>

            <p class="text-sm text-stone-500 dark:text-stone-400">{{ wording.failedBody }}</p>
          </div>

          <div v-else-if="isEmpty" class="rounded-2xl border border-dashed border-stone-300 p-4 text-sm dark:border-stone-700">
            <p class="font-medium">{{ wording.emptyTitle }}</p>
            <p v-if="modelNotes" class="mt-1 italic text-stone-500 dark:text-stone-400">{{ modelNotes }}</p>
            <p class="mt-2 text-stone-500 dark:text-stone-400">{{ wording.emptyBody }}</p>
          </div>

          <template v-if="!isAnalyzing && !hasFailed">
            <!--
              "You have had this before" (step 6) changes how the numbers below
              read: on an exact match the portions are the user's own remembered
              ones, not the model's guess; a partial match means some is new.
            -->
            <div
              v-if="meal.seenBefore && !isEmpty"
              class="rounded-xl bg-teal-50 px-3 py-2 text-xs text-teal-900 dark:bg-teal-950/60 dark:text-teal-200"
            >
              <p class="font-medium">
                {{ meal.seenBefore.exact ? 'You have had this before' : 'Looks like something you have had before' }}
                · logged {{ meal.seenBefore.timesLogged }}×
              </p>
              <p class="mt-0.5 opacity-80">
                {{ meal.seenBefore.label }} — portions and densities marked
                <span class="font-medium">from history</span> are pre-filled from those logs. Change anything that is wrong.
              </p>
            </div>

            <!--
              What Claude said about its own answer: READ-ONLY, in its own block
              rather than the Notes box. It is commentary — "assumed level
              tablespoons" — not something the user wrote, and in the editable
              field Confirm stored it as the user's words, overwriting theirs.
            -->
            <p v-if="modelNotes && !isEmpty" class="rounded-xl bg-stone-100 px-3 py-2 text-xs italic text-stone-600 dark:bg-stone-900 dark:text-stone-400">
              {{ modelNotes }}
            </p>

            <!--
              THE SHARED PLATE, above the items because it is about all of them:
              the model estimated the plate, this is where the user says how much
              was theirs. Every number below moves as it is tapped.
            -->
            <div
              v-if="!isEmpty"
              class="space-y-1.5 rounded-xl border border-stone-200 bg-white px-3 py-2.5 dark:border-stone-800 dark:bg-stone-900"
            >
              <ShareChips
                :model-value="entryShare"
                :label="fromPhoto ? 'I ate' : 'I ate'"
                @update:model-value="setEntryShare"
              />

              <p v-if="entryShare < 1" class="text-[11px] text-stone-500 dark:text-stone-400">
                The numbers below are your share. Claude estimated the whole
                plate; anything you set on its own line stays where you put it.
              </p>
            </div>

            <div class="flex items-center justify-between">
              <p class="text-xs text-stone-500 dark:text-stone-400">
                Every number is a range. Narrow it if you know better; widen it if you don't.
              </p>
              <button
                type="button"
                class="shrink-0 text-xs font-medium text-teal-600 dark:text-teal-400"
                @click="showMacros = !showMacros"
              >
                {{ showMacros ? 'Hide macros' : 'Macros' }}
              </button>
            </div>

            <div
              v-for="(item, index) in form.items"
              :key="index"
              class="space-y-2 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900"
            >
              <div class="flex items-center gap-2">
                <input
                  v-model="item.name"
                  type="text"
                  placeholder="What is it?"
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

              <p v-if="item.confidence || item.memory_adjusted" class="flex items-center gap-2 text-[11px]">
                <span v-if="item.confidence" class="text-stone-400">{{ confidenceLabel[item.confidence] }}</span>
                <span v-if="item.memory_adjusted" class="font-medium text-teal-700 dark:text-teal-400">
                  from history
                </span>
              </p>

              <!--
                The per-item override: set, this line stops following the plate's
                chips; tapped back onto the plate's own fraction, it follows again.
              -->
              <ShareChips
                compact
                label="ate"
                :model-value="item.share_fraction ?? 1"
                :plate-share="entryShare"
                :overridden="isOverridden(item)"
                @update:model-value="setItemShare(item, $event)"
                @release="releaseItem(item)"
              />

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <span class="text-[11px] text-stone-500">portion, g</span>
                  <div class="mt-0.5 flex items-center gap-1">
                    <input
                      :value="box(item, 'portion_g_min')"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, 'portion_g_min', $event.target.value)"
                      @blur="inline.check(`items.${index}.portion_g_min`)"
                    >
                    <span class="text-xs text-stone-400">–</span>
                    <input
                      :value="box(item, 'portion_g_max')"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, 'portion_g_max', $event.target.value)"
                      @blur="inline.check(`items.${index}.portion_g_max`)"
                    >
                  </div>
                </div>

                <div>
                  <span class="text-[11px] text-stone-500">kcal</span>
                  <div class="mt-0.5 flex items-center gap-1">
                    <input
                      :value="box(item, 'kcal_min')"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, 'kcal_min', $event.target.value)"
                      @blur="inline.check(`items.${index}.kcal_min`)"
                    >
                    <span class="text-xs text-stone-400">–</span>
                    <input
                      :value="box(item, 'kcal_max')"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, 'kcal_max', $event.target.value)"
                      @blur="inline.check(`items.${index}.kcal_max`)"
                    >
                  </div>
                </div>
              </div>

              <!--
                The plate, whenever it is not what the boxes show: the user is
                entitled to see the number they are dividing.
              -->
              <p v-if="(item.share_fraction ?? 1) < 1" class="text-[11px] text-stone-400">
                the whole plate: {{ item.portion_g_min }}–{{ item.portion_g_max }} g ·
                {{ Math.round(item.kcal_min) }}–{{ Math.round(item.kcal_max) }} kcal
              </p>

              <div v-if="showMacros" class="grid grid-cols-3 gap-2">
                <div v-for="macro in ['protein_g', 'carbs_g', 'fat_g']" :key="macro">
                  <span class="text-[11px] text-stone-500">{{ macro.replace('_g', '') }}, g</span>
                  <div class="mt-0.5 flex items-center gap-1">
                    <input
                      :value="box(item, `${macro}_min`)"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, `${macro}_min`, $event.target.value)"
                      @blur="inline.check(`items.${index}.${macro}_min`)"
                    >
                    <span class="text-xs text-stone-400">–</span>
                    <input
                      :value="box(item, `${macro}_max`)"
                      type="text"
                      inputmode="decimal"
                      :class="[inputClass, 'tnum']"
                      @input="edit(item, `${macro}_max`, $event.target.value)"
                      @blur="inline.check(`items.${index}.${macro}_max`)"
                    >
                  </div>
                </div>
              </div>

              <!--
                THE QUESTION WORTH ASKING, on the line that owns this PLATE's
                range: it writes into the note above and fires the SAME
                re-analysis as the button below, so there is one hint and one
                path to the model, whether it was typed or tapped in here.
              -->
              <div
                v-if="showNarrow && index === dominant?.index"
                class="space-y-1.5 rounded-xl bg-teal-50 px-3 py-2.5 dark:bg-teal-950/60"
              >
                <p :id="`narrow-help-${index}`" class="text-[11px] text-teal-900 dark:text-teal-200">
                  This one sets about {{ narrowShare }}% of this plate's range. A count or a weight would narrow it.
                </p>

                <div class="flex items-center gap-2">
                  <input
                    v-model="narrowAnswer"
                    type="text"
                    enterkeyhint="send"
                    placeholder="e.g. 8 pieces or 180 g"
                    :aria-label="`How much ${dominantName} — a count or a weight`"
                    :aria-describedby="narrowError ? `narrow-help-${index} narrow-error-${index}` : `narrow-help-${index}`"
                    :class="[inputClass, 'min-h-[44px]']"
                    @input="narrowError = ''"
                    @keyup.enter="narrow"
                  >
                  <button
                    type="button"
                    :disabled="reanalyzing || !online"
                    class="inline-flex min-h-[44px] shrink-0 items-center justify-center rounded-lg bg-teal-600 px-3
                           text-sm font-medium text-white active:scale-95 disabled:opacity-60"
                    @click="narrow"
                  >
                    Narrow
                  </button>
                </div>

                <p v-if="narrowError" :id="`narrow-error-${index}`" class="text-[11px] text-rose-700 dark:text-rose-300">
                  {{ narrowError }}
                </p>

                <p v-if="!online" class="text-[11px] text-teal-900/80 dark:text-teal-200/80">
                  {{ wording.againOffline }}
                </p>
              </div>
            </div>

            <button
              type="button"
              class="w-full rounded-xl border border-dashed border-stone-300 py-2.5 text-sm font-medium
                     text-stone-500 active:scale-[0.99] dark:border-stone-700"
              @click="addItem"
            >
              + Add an item vision missed
            </button>

            <!--
              TWO EQUAL COLUMNS. `grid-cols-2` fixes the TRACKS at half each and
              `min-w-0` clears each CELL's default `min-width: auto`; neither
              reaches the real cause, the UA `min-width` WebKit gives
              `input[type=time]`, which only an author `min-width` beats — said
              once in the `input[type='time']` rules in resources/css/app.css.
              The meal type is a chip row (MealTypeChips.vue) spanning BOTH
              columns on a second grid row, four words not fitting in half a
              phone; the grid is untouched, keeping Time in its half-width track.
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

            <label class="block">
              <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Notes</span>
              <input
                v-model="form.notes"
                type="text"
                :class="[inputClass, 'mt-1']"
                v-bind="inline.on('notes')"
              >
            </label>
          </template>

          <ul v-if="Object.keys(errors).length" class="space-y-1 rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
            <li v-for="(message, key) in errors" :key="key">{{ errorLine(key, message, form.items) }}</li>
          </ul>

          <p v-if="actionError" class="rounded-xl bg-rose-100 px-3 py-2 text-sm text-rose-900 dark:bg-rose-950 dark:text-rose-200">
            {{ actionError }}
          </p>

          <button
            v-if="!isAnalyzing && !isEmpty"
            type="button"
            :disabled="processing || form.items.length === 0"
            class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white
                   transition active:scale-[0.99] disabled:opacity-60"
            @click="confirm"
          >
            Confirm {{ form.items.length }} item{{ form.items.length === 1 ? '' : 's' }}
          </button>

          <!--
            A note the user has just written promotes this button rather than firing
            it: asking costs a call and replaces numbers they may have edited by hand.
          -->
          <button
            v-if="!isAnalyzing && canRerun"
            type="button"
            :disabled="reanalyzing || !online"
            class="w-full rounded-xl py-2.5 text-sm font-medium active:scale-[0.99] disabled:opacity-60"
            :class="hintChanged
              ? 'bg-teal-600 text-white'
              : 'border border-stone-300 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
            @click="rerun"
          >
            {{ !online
              ? wording.againOffline
              : reanalyzing
                ? wording.againBusy
                : hintChanged ? wording.againWithNote : wording.again }}
          </button>

          <!--
            Offered here as well as in the strip: a meal with ONE photograph has no
            strip, and "and then I had pudding" happens on this screen.
          -->
          <button
            v-if="!isAnalyzing && photos.length <= 1"
            type="button"
            class="w-full rounded-xl border border-stone-300 py-2.5 text-sm font-medium
                   text-stone-600 active:scale-[0.99] dark:border-stone-700 dark:text-stone-300"
            @click="emit('add-photo', meal.uuid)"
          >
            Add another course
          </button>

          <!--
            Removing THIS plate: anything already agreed to off it stays —
            deleting a picture is a statement about the picture.
          -->
          <button
            v-if="entry && !isAnalyzing"
            type="button"
            :disabled="removing"
            class="w-full rounded-xl py-2.5 text-sm font-medium disabled:opacity-60"
            :class="confirmingPhotoRemoval ? 'bg-rose-600 text-white' : 'text-rose-600 dark:text-rose-400'"
            @click="removePhoto"
          >
            {{ removing
              ? 'Removing…'
              : confirmingPhotoRemoval
                ? 'Tap again to remove this photo'
                : 'Remove this photo' }}
          </button>

          <!--
            The WHOLE meal, offered only while nothing on it has been confirmed; after
            that this would delete food the user already agreed to.
          -->
          <button
            v-if="canDiscardMeal"
            type="button"
            class="w-full rounded-xl py-2.5 text-sm font-medium"
            :class="confirmingDelete ? 'bg-rose-600 text-white' : 'text-rose-600 dark:text-rose-400'"
            @click="discard"
          >
            {{ confirmingDelete ? 'Tap again to discard the meal and its photos' : 'Discard the whole meal' }}
          </button>

          <p v-if="!isAnalyzing && entry && !entry.canReanalyze" class="text-center text-[11px] text-stone-400">
            Only the thumbnail of this photo is left — originals are kept for
            {{ retentionDays }} days — so it cannot be analysed again.
          </p>
        </div>
      </div>
    </div>
  </Teleport>
</template>
