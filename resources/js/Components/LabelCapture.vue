<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { preparePhoto } from '../lib/photo'
import { pollLabel, uploadLabel } from '../lib/supplements'
import { holdUpdate } from '../lib/sw'

/**
 * Photograph a supplement label, and wait for the transcription. THE PATH FOR THE
 * SIXTH BOTTLE: the five on the shelf are seeded from what their manufacturers
 * publish (database/seeders/SupplementSeeder.php), so this is not the setup flow
 * but what happens when something new is bought, and the copy says so.
 *
 * 2048 px, NOT 1024: a meal photo is 1024 because that is plenty to say "that is
 * rice", but a supplement-facts panel is thirty lines of 5-point type and the task
 * is transcription — an illegible µg figure comes back as a wrong number that
 * looks right, not as a wider range. Only the ceiling moves; see config/health.php.
 *
 * A FILE INPUT, NOT getUserMedia, for PhotoCapture's reason: iOS re-prompts for
 * camera permission on every Home-Screen PWA launch, and the system sheet is
 * Apple's own camera with tap-to-focus — which matters more here than for a plate,
 * because this shot has to be sharp.
 */
const emit = defineEmits(['close', 'read'])

const cameraInput = ref(null)
const libraryInput = ref(null)

const error = ref(null)
const state = ref('idle') // idle | preparing | reading | failed
const preview = ref(null)

let timer = null
let clientId = null

function stopPolling() {
  if (timer !== null) clearTimeout(timer)

  timer = null
}

async function onFile(event) {
  const [file] = Array.from(event.target.files ?? [])

  // Clearing matters: the SAME file twice fires no change event otherwise, which
  // reads as a broken button.
  event.target.value = ''

  if (!file) return

  error.value = null
  state.value = 'preparing'

  let prepared

  try {
    prepared = await preparePhoto(file, { maxEdge: 2048 })
  } catch (e) {
    state.value = 'failed'
    error.value = e.message ?? 'That photo could not be prepared.'

    return
  }

  if (preview.value) URL.revokeObjectURL(preview.value)

  preview.value = prepared.url

  clientId = crypto.randomUUID()

  const result = await uploadLabel({
    clientId,
    idempotencyKey: crypto.randomUUID(),
    blob: prepared.blob,
  })

  if (!result.ok) {
    state.value = 'failed'
    error.value = result.message

    return
  }

  state.value = 'reading'

  poll()
}

/**
 * Poll until the reading settles — every two seconds, stopping on both ends. No
 * timeout counter: the job's own `failed()` hook writes a reason onto the row if
 * the worker dies, so a poll that never settles is a bug worth seeing rather than
 * a spinner worth hiding.
 */
function poll() {
  stopPolling()

  timer = setTimeout(async () => {
    const result = await pollLabel(clientId)

    if (result?.status === 'succeeded') {
      emit('read', { clientId, label: result.label })

      return
    }

    if (result?.status === 'failed') {
      state.value = 'failed'
      error.value = result.error ?? 'That label could not be read.'

      return
    }

    poll()
  }, 2000)
}

// Do not reload while the camera sheet is open: backgrounding is exactly what the
// system photo sheet causes, and a service worker taking over then would come back
// to a reloaded page with the capture gone.
const releaseUpdateHold = holdUpdate()

onBeforeUnmount(() => {
  stopPolling()
  releaseUpdateHold()

  if (preview.value) URL.revokeObjectURL(preview.value)
})
</script>

<template>
  <div class="fixed inset-0 z-[60] flex flex-col bg-stone-950/95 pb-[env(safe-area-inset-bottom)] pt-[env(safe-area-inset-top)]">
    <div class="flex items-center justify-between px-4 py-3 text-white">
      <button type="button" class="text-sm text-stone-300" @click="emit('close')">Cancel</button>
      <h2 class="text-sm font-semibold">Read a label</h2>
      <span class="w-12" />
    </div>

    <div class="flex flex-1 flex-col items-center justify-center gap-4 overflow-y-auto px-4">
      <img
        v-if="preview"
        :src="preview"
        alt="The label being read"
        class="max-h-64 rounded-xl border border-stone-700 object-contain"
      >

      <p v-if="state === 'idle'" class="max-w-xs text-center text-sm text-stone-400">
        Photograph the supplement-facts panel — the small print with the amounts
        in it, not the front of the bottle. Fill the frame with it and hold
        still: every figure has to be readable, because they are copied exactly
        rather than estimated.
      </p>

      <p v-else-if="state === 'preparing'" class="text-sm text-stone-400">Shrinking and sending…</p>

      <p v-else-if="state === 'reading'" class="max-w-xs text-center text-sm text-stone-400">
        Reading the panel. This takes a few seconds — you will get every line to
        check against the bottle before anything is saved.
      </p>

      <p v-if="error" class="max-w-xs rounded-xl bg-rose-950 px-3 py-2 text-center text-sm text-rose-200">
        {{ error }}
      </p>
    </div>

    <div class="space-y-3 px-4 py-4">
      <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFile">
      <input ref="libraryInput" type="file" accept="image/*" class="hidden" @change="onFile">

      <div class="flex gap-2">
        <button
          type="button"
          class="flex-1 rounded-xl border border-stone-600 py-3 text-base font-medium text-white active:scale-[0.99] disabled:opacity-60"
          :disabled="state === 'preparing' || state === 'reading'"
          @click="cameraInput?.click()"
        >
          {{ state === 'failed' ? 'Try again' : 'Take photo' }}
        </button>

        <button
          type="button"
          class="flex-1 rounded-xl border border-stone-600 py-3 text-base font-medium text-white active:scale-[0.99] disabled:opacity-60"
          :disabled="state === 'preparing' || state === 'reading'"
          @click="libraryInput?.click()"
        >
          Choose photo
        </button>
      </div>
    </div>
  </div>
</template>
