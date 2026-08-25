<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { preparePhoto } from '../lib/photo'
import { reanalyzePhoto, uploadMealPhoto } from '../lib/vision'
import { KIND, amendPayload, enqueue } from '../lib/queue'
import { leftoverNoteAction } from '../lib/capture-note'
import { holdUpdate } from '../lib/sw'
import { inlineErrors } from '../lib/validation/inline'

/**
 * Photograph a meal, one plate at a time.
 *
 * A FILE INPUT, NOT getUserMedia. This needs one still, not a live stream, and
 * `<input type="file" accept="image/*">` gets one from the system sheet. iOS
 * re-prompts for camera permission on every launch of a Home-Screen PWA, so a
 * getUserMedia preview would mean a permission dialog before the user has even
 * framed the shot; the system sheet is Apple's own camera, already trusted and
 * permitted, and costs no stream, video element or decoder.
 *
 * TWO INPUTS, BECAUSE `capture` IS A ONE-WAY DOOR. `capture="environment"` means
 * "the camera, and nothing else", which is right for the meal in front of you
 * and useless for the one you are logging on the train home; no attribute value
 * offers both, so there are two inputs behind two labelled buttons, sharing
 * `onFiles` so that downscale, EXIF strip, upload and analysis are one path.
 *
 * A MEAL IS A SERIES OF PLATES, EACH SENT AND ANALYSED ON ARRIVAL. Every photo
 * uploads the moment it is shrunk, so the main course is back before the dessert
 * is framed; batching behind an "analyse" button would trade that for nothing.
 * The meal uuid is fixed for the sheet, so every plate joins the same meal, and
 * each plate carries its own `clientId` (which photo) and idempotency key (which
 * analysis) — see MealPhotoRequest for why three ids is the right number.
 *
 * OFFLINE, THE PHOTO ITSELF IS QUEUED (step 8). The downscaled JPEG — 150-400
 * KB, not the 4 MB the camera produced — goes into IndexedDB as a Blob with the
 * three ids and uploads on the next flush. That is the whole reason the queue is
 * IndexedDB and not localStorage: a Blob stays a Blob rather than becoming a
 * third again as much base64 built on the main thread. Queued plates keep
 * capture order because `lib/idb.all()` sorts by `createdAt` and `flush()` sends
 * oldest first, so replayed positions are the ones they would have got.
 *
 * THE OPTIONAL NOTE, AND WHY IT CLEARS ITSELF. One skippable line: "3 eggs,
 * 120 g drained tuna". A photograph cannot tell you the tuna was drained or that
 * there are three eggs under the cheese, so this is the cheapest way there is to
 * narrow a range. It is attached to the plate being sent and then EMPTIED: a
 * hint the user did not mean is worse than no hint at all, since the whole
 * feature rests on the model treating it as fact.
 *
 * AND WHY THE LAST ONE GOES BACKWARDS INSTEAD. That describes a box read BEFORE
 * the shutter, which is not how this sheet is used: the server's logs put every
 * upload 13-23 seconds after a cold launch, so the picture is taken first and the
 * note typed afterwards, while the plate it is about is already being analysed.
 * Done used to discard that sentence in silence — every initial `vision_requests`
 * row for a photographed meal is empty, and every re-analysis row a minute later
 * has the hint in it. So the forward contract is unchanged, and Done sends the
 * leftover BACKWARDS to the most recent plate that got anywhere: re-analysed if
 * it is on the server, its queued hint rewritten if it is not.
 * `lib/capture-note.js` holds that decision and says why each rule is what it is.
 *
 * The attempt is AWAITED before the sheet closes: one small POST, and an
 * unawaited fetch fired as a modal unmounts is the kind of thing iOS is entitled
 * to cut short — a request dropped half the time is worse than no feature.
 */
const props = defineProps({
  date: { type: String, required: true },
  // Only to say so out loud when the meal is not going onto today: the date is
  // already correct, but a sheet identical on every day reads as "now".
  label: { type: String, default: '' },
  isToday: { type: Boolean, default: true },

  // Adding a course to an existing meal; null starts a new one.
  mealUuid: { type: String, default: null },
  // What the meal already has, so the strip counts the whole series rather than
  // this visit alone.
  existingCount: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'started'])

const cameraInput = ref(null)
const libraryInput = ref(null)
const error = ref(null)
const busy = ref(false)

// One plate per entry: { id, url, hint, state: 'sending' | 'sent' | 'queued' | 'failed' }
const plates = ref([])

/**
 * The note for the plate(s) about to be taken; empty is the normal case.
 *
 * `hint.trim()` is what actually travels, so a box of spaces is an empty one and
 * the column stays NULL. Anything left at Done goes to the plate it was typed
 * about rather than into the bin: `attachLeftoverNote`.
 */
const hint = ref('')

/**
 * MealPhotoRequest's own words, said as a box is left. The two boxes here are
 * the only ones a person types into, and neither carries a native constraint:
 * the note's ceiling used to be enforced by the box itself, which stopped
 * accepting letters mid-word and said nothing — and a note quietly cut short is
 * one Claude is given less of without anybody knowing.
 */
const inline = inlineErrors('MealPhotoRequest', () => ({ time: time.value, hint: hint.value }))

const sentCount = computed(() => plates.value.filter((p) => p.state !== 'failed').length)

const canFinish = computed(() => sentCount.value > 0)

// Fixed for the life of this sheet, so every plate joins one meal.
const uuid = props.mealUuid ?? crypto.randomUUID()

function nowTime() {
  const d = new Date()

  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

const time = ref(nowTime())

function takePhoto() {
  error.value = null
  cameraInput.value?.click()
}

function choosePhoto() {
  error.value = null
  libraryInput.value?.click()
}

/**
 * Sequential rather than `Promise.all`: the server assigns `position` by
 * appending, so parallel uploads would race for the same slot and the strip
 * would disagree with the review sheet about which plate was which.
 */
async function onFiles(event) {
  const files = Array.from(event.target.files ?? [])

  // Without this, picking the SAME file twice fires no change event at all,
  // which reads as a broken button.
  event.target.value = ''

  if (files.length === 0) return

  busy.value = true
  error.value = null

  // Read ONCE: one gesture is one note, so the box empties after the files
  // rather than between them.
  const note = hint.value.trim()

  for (const file of files) {
    await sendOne(file, note)
  }

  hint.value = ''

  busy.value = false
}

async function sendOne(file, note) {
  let prepared

  try {
    prepared = await preparePhoto(file)
  } catch (e) {
    error.value = e.message ?? 'That photo could not be prepared.'

    return
  }

  const clientId = crypto.randomUUID()

  const plate = { id: clientId, url: prepared.url, hint: note, state: 'sending' }

  plates.value.push(plate)

  const payload = {
    uuid,
    clientId,
    idempotencyKey: crypto.randomUUID(),
    date: props.date,
    time: time.value,
    hint: note,
    blob: prepared.blob,
  }

  // Known-offline: do not spend twenty seconds discovering it.
  if (navigator.onLine === false) return queueOne(plate, payload)

  const result = await uploadMealPhoto(payload)

  if (result.ok) {
    plate.state = 'sent'

    return
  }

  // lib/vision.js reports a failed fetch as `offline` — losing the connection
  // mid-upload is the same case as never having had one.
  if (result.status === 'offline') return queueOne(plate, payload)

  plate.state = 'failed'
  error.value = result.message
}

/**
 * Hold this plate on the phone until there is a network. The three ids are the
 * ones it has carried since it was framed, so the replay is indistinguishable
 * from the upload that would have happened then — including costing exactly one
 * analysis.
 */
async function queueOne(plate, payload) {
  const result = await enqueue({
    // The PHOTO's id: keying on the meal would collapse three plates of one
    // dinner into a single queued action.
    id: payload.clientId,
    kind: KIND.photoUpload,
    url: '/api/meals/photo',
    method: 'POST',
    payload: {
      uuid: payload.uuid,
      client_id: payload.clientId,
      idempotency_key: payload.idempotencyKey,
      date: payload.date,
      time: payload.time,
      // `send()` in lib/queue SKIPS null and undefined when building the
      // FormData, so an unhinted plate replays as exactly the request it would
      // have been — the property the whole queue is built on.
      hint: payload.hint === '' ? null : payload.hint,
    },
    blob: payload.blob,
    blobField: 'photo',
    date: props.date,
    meta: { label: 'Photo of a meal', time: payload.time },
  })

  if (!result.ok) {
    plate.state = 'failed'
    error.value = result.message

    return
  }

  plate.state = 'queued'
}

/**
 * Drop a plate that has not been sent — only the failed ones. A plate that
 * reached the server is removed from the review sheet instead, where its
 * analysis can actually be shown.
 */
function drop(index) {
  const [plate] = plates.value.splice(index, 1)

  if (plate) URL.revokeObjectURL(plate.url)
}

/**
 * The note the user typed after the shutter, sent to the plate it is about.
 * See the header for why this runs backwards while `onFiles` runs forwards, and
 * lib/capture-note.js for which plate it picks. Awaited by `finish()` so the
 * request is not raced against this component's own unmount.
 *
 * FAILURE IS SILENT, ON PURPOSE: the user is one tap from the review sheet's own
 * note box, where they had to type this sentence before today, so the worst case
 * is behaviour they already know.
 *
 * `conflict` used to be constant here, since the plate is still being analysed
 * exactly when this runs. MealPhotoController::reanalyze now supersedes when the
 * note is one the running call was not given — every case this function exists
 * for — and leaves 409 for a re-ask repeating a question already in flight.
 */
async function attachLeftoverNote() {
  const decision = leftoverNoteAction(hint.value, plates.value)

  if (decision.action === 'none') return

  const plate = plates.value.find((p) => p.id === decision.plateId)

  const result = decision.action === 'amendQueued'
    // Still on the phone: rewrite the upload rather than ask for a second
    // analysis of something with no first one.
    ? await amendPayload(decision.plateId, { hint: decision.note })
    /*
     * On the server: absent arguments mean "the sheet did not say", and this
     * sheet has nothing to say about the plate's share or items it never saw.
     */
    : await reanalyzePhoto(uuid, decision.plateId, crypto.randomUUID(), null, decision.note, null)

  if (!result.ok) return

  hint.value = ''

  // The strip is on screen for the length of the request, and the badge is the
  // only thing that says the note found a home.
  if (plate) plate.hint = decision.note
}

async function finish() {
  if (busy.value) return

  // Same flag the uploads raise: disables the buttons and shows "Sending…".
  busy.value = true

  await attachLeftoverNote()

  busy.value = false

  // Nothing on the server to review, so the day's queued-actions card is the
  // honest place to end up.
  if (plates.value.every((plate) => plate.state === 'queued')) {
    emit('close')

    return
  }

  emit('started', uuid)
}

/*
 * Do not reload this app while the capture flow is open. A backgrounded page now
 * takes a new service worker itself (resources/js/lib/sw.js), and backgrounding
 * is exactly what the system photo sheet does — without this hold, taking a
 * picture could return to a reloaded app with plates, hints and object URLs gone.
 */
const releaseUpdateHold = holdUpdate()

onBeforeUnmount(() => {
  releaseUpdateHold()

  for (const plate of plates.value) URL.revokeObjectURL(plate.url)
})

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <div class="fixed inset-0 z-[60] flex flex-col bg-stone-950/95 pb-[env(safe-area-inset-bottom)] pt-[env(safe-area-inset-top)]">
    <div class="flex items-center justify-between px-4 py-3 text-white">
      <button type="button" class="text-sm text-stone-300" @click="emit('close')">Cancel</button>
      <h2 class="text-sm font-semibold">{{ mealUuid ? 'Another course' : 'Photo' }}</h2>
      <span class="w-12" />
    </div>

    <div class="flex flex-1 flex-col items-center justify-center gap-4 overflow-y-auto px-4">
      <!-- The strip: one thumbnail per plate, in capture order. -->
      <div v-if="plates.length > 0" class="flex w-full max-w-sm flex-wrap justify-center gap-2">
        <div
          v-for="(plate, index) in plates"
          :key="plate.id"
          class="relative h-24 w-24 overflow-hidden rounded-xl border border-stone-700"
        >
          <img :src="plate.url" alt="A plate of this meal" class="h-full w-full object-cover">

          <span
            class="absolute left-1 top-1 rounded-full bg-stone-950/80 px-1.5 py-0.5 text-[10px] font-medium text-stone-200"
          >
            {{ existingCount + index + 1 }}
          </span>

          <span
            v-if="plate.state !== 'sent'"
            class="absolute inset-x-0 bottom-0 bg-stone-950/80 py-0.5 text-center text-[10px]"
            :class="plate.state === 'failed' ? 'text-rose-300' : 'text-stone-300'"
          >
            {{ plate.state === 'sending' ? 'Sending…' : plate.state === 'queued' ? 'Queued' : 'Failed' }}
          </span>

          <button
            v-if="plate.state === 'failed'"
            type="button"
            class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-stone-950/80 text-xs text-stone-200"
            aria-label="Remove this photo"
            @click="drop(index)"
          >
            ×
          </button>

          <!-- The note that went with THIS plate, so a box that empties itself
               stays legible: what was said, and about which photograph. -->
          <span
            v-if="plate.hint && plate.state !== 'failed'"
            class="absolute right-1 top-1 rounded-full bg-teal-500/90 px-1.5 py-0.5 text-[10px] font-medium text-white"
            :title="plate.hint"
          >
            note
          </span>
        </div>
      </div>

      <p v-if="plates.length === 0" class="max-w-xs text-center text-sm text-stone-400">
        Photograph the whole plate from above, or choose photos you already took.
        Get something in frame for scale — a fork, your hand, the edge of the table.
        The estimate is only as good as the sense of size it can get.
      </p>

      <p v-else class="max-w-xs text-center text-xs text-stone-400">
        Each photo is a course, estimated on its own. Add the second helping or
        the pudding whenever it arrives — you confirm them one at a time.
      </p>

      <p v-if="!isToday" class="rounded-full bg-amber-950/70 px-3 py-1 text-xs text-amber-200">
        Logging to {{ label || date }}, not today.
      </p>

      <p v-if="error" class="max-w-xs rounded-xl bg-rose-950 px-3 py-2 text-center text-sm text-rose-200">
        {{ error }}
      </p>
    </div>

    <div class="space-y-3 px-4 py-4">
      <label v-if="plates.length === 0" class="block">
        <span class="text-xs font-medium text-stone-400">Time</span>
        <input
          v-model="time"
          type="time"
          :class="[inputClass, 'mt-1 tnum']"
          v-bind="inline.on('time')"
        >
        <span v-if="inline.errors.time" class="mt-1 block text-xs text-rose-400">{{ inline.errors.time }}</span>
      </label>

      <!-- THE OPTIONAL NOTE. Above the buttons: the last thing before the
           shutter and the first that can be skipped. One line, because anything
           longer is the typed path, which is better at it. `enterkeyhint="done"`
           + blur on Enter is the whole keyboard story: no form to submit here,
           and a Return that did nothing visible is a keyboard that stays. The
           length ceiling is the server's and arrives as a sentence on blur —
           it used to be a native one that simply stopped accepting letters. -->
      <label class="block">
        <span class="text-xs font-medium text-stone-400">
          What is it? <span class="font-normal opacity-70">optional</span>
        </span>
        <input
          v-model="hint"
          type="text"
          enterkeyhint="done"
          autocapitalize="none"
          placeholder="Tell Claude what you know — “3 eggs, 120 g drained tuna”"
          :class="[inputClass, 'mt-1']"
          v-bind="inline.on('hint')"
          @keyup.enter="$event.target.blur()"
        >
        <span v-if="inline.errors.hint" class="mt-1 block text-xs text-rose-400">{{ inline.errors.hint }}</span>
        <!-- With a plate already taken the box has two futures and says both.
             The second half is the point: the note is usually typed AFTER the
             picture, and it used to be thrown away. -->
        <span class="mt-1 block text-[11px] text-stone-500">
          {{ plates.length > 0
            ? 'Goes with the next photo — or with the last one, if you tap Done instead.'
            : 'Anything you counted or weighed is used as given. Skip it and Claude estimates everything.' }}
        </span>
      </label>

      <!-- `capture="environment"` opens the rear camera straight away and is
           ALSO what makes the photo library unreachable, which is why the input
           below exists without it. No `multiple`: the button is tappable again. -->
      <input
        ref="cameraInput"
        type="file"
        accept="image/*"
        capture="environment"
        class="hidden"
        @change="onFiles"
      >

      <!-- No `capture`: iOS offers the photo library (and its own camera from
           inside it), and `multiple` belongs here rather than above. -->
      <input
        ref="libraryInput"
        type="file"
        accept="image/*"
        multiple
        class="hidden"
        @change="onFiles"
      >

      <div class="flex gap-2">
        <button
          type="button"
          class="flex-1 rounded-xl border border-stone-600 py-3 text-base font-medium text-white active:scale-[0.99] disabled:opacity-60"
          :disabled="busy"
          @click="takePhoto"
        >
          {{ plates.length > 0 ? 'Another photo' : 'Take photo' }}
        </button>

        <button
          type="button"
          class="flex-1 rounded-xl border border-stone-600 py-3 text-base font-medium text-white active:scale-[0.99] disabled:opacity-60"
          :disabled="busy"
          @click="choosePhoto"
        >
          Choose photos
        </button>
      </div>

      <button
        v-if="canFinish"
        type="button"
        class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white
               transition active:scale-[0.99] disabled:opacity-60"
        :disabled="busy"
        @click="finish"
      >
        {{ busy ? 'Sending…' : `Review ${sentCount} photo${sentCount === 1 ? '' : 's'}` }}
      </button>

      <p v-if="busy" class="text-center text-xs text-stone-400">Shrinking and sending…</p>
    </div>
  </div>
</template>
