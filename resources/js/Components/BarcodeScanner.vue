<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { createBarcodeDetector } from '../lib/scanner'

/**
 * Full-screen camera scanner.
 *
 * A DELIBERATE, EXPLICIT TAP: iOS asks for camera permission again on every launch
 * of a web app (the grant does not persist as for an installed app), so the camera
 * is never opened speculatively — a denial is remembered for the session. The panel
 * behind the prompt exists because iOS's dialog says only "health.example.com would
 * like to access the camera", so the context for that question has to be on the
 * screen underneath it already.
 *
 * THE DECODER LOADS HERE, NOT ON PAGE LOAD: `createBarcodeDetector` dynamically
 * imports ~1 MB of WebAssembly, so nothing fetches it until "Scan barcode" is tapped.
 */
const emit = defineEmits(['detected', 'close'])

const video = ref(null)
const canvas = document.createElement('canvas')

// shallowRef: deep reactivity would have Vue proxy a live MediaStream's internals.
const stream = shallowRef(null)

const state = ref('starting') // starting | scanning | denied | failed
const message = ref('')
const engine = ref(null)
const torchOn = ref(false)
const torchSupported = ref(false)
const searching = ref(false)
const manual = ref('')

let timer = null
let running = false
let detect = null

/**
 * ~8 frames a second, never two decodes at once. A wasm decode of an 800 px frame
 * takes tens of milliseconds; off requestAnimationFrame it would queue faster than
 * it completes, heat the phone and stutter the preview being aimed with. Eight
 * looks instant and leaves the main thread alone.
 */
const FRAME_INTERVAL_MS = 120

/** The scan window, as a fraction of the preview. Matches the drawn viewfinder. */
const WINDOW = { width: 0.86, height: 0.34 }

/** Decoding above this width buys nothing and costs milliseconds per frame. */
const MAX_SCAN_WIDTH = 800

/** EAN-8 is the shortest barcode a product carries, so fewer digits cannot be one. */
const MIN_BARCODE_DIGITS = 8

/** What was typed, as the lookup wants it: `inputmode` asks for a keypad, it does not enforce one. */
const digits = computed(() => manual.value.replace(/[^0-9]/g, ''))

const enoughDigits = computed(() => digits.value.length >= MIN_BARCODE_DIGITS)

onMounted(start)
onBeforeUnmount(stop)

async function start() {
  state.value = 'starting'
  message.value = ''

  if (!navigator.mediaDevices?.getUserMedia) {
    fail('This browser will not give a web page camera access. Type the number instead.')

    return
  }

  try {
    // `ideal`, not `exact`: a front-camera-only laptop should get a preview, not
    // an OverconstrainedError. The hint keeps thin bars alive through downscaling.
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
      audio: false,
    })
  } catch (error) {
    if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
      state.value = 'denied'
      message.value =
        'Camera access was refused. iOS asks again next time you open the app — ' +
        'or type the barcode number below.'
    } else if (error?.name === 'NotFoundError' || error?.name === 'OverconstrainedError') {
      fail('No camera found on this device. Type the number instead.')
    } else if (error?.name === 'NotReadableError') {
      fail('The camera is busy in another app. Close it and try again.')
    } else {
      fail('The camera could not be started. Type the number instead.')
    }

    return
  }

  const track = stream.value.getVideoTracks()[0]
  const capabilities = track?.getCapabilities?.() ?? {}

  torchSupported.value = 'torch' in capabilities

  video.value.srcObject = stream.value

  // iOS will not play an unmuted video inline without a gesture, nor play inline
  // at all without playsinline. Both are set as ATTRIBUTES — Safari has
  // historically read the attribute, not the property, when deciding at load time.
  try {
    await video.value.play()
  } catch {
    fail('The camera preview would not start. Type the number instead.')

    return
  }

  try {
    const detector = await createBarcodeDetector()

    detect = detector.detect
    engine.value = detector.engine
  } catch {
    fail('The barcode decoder failed to load. Type the number instead.')

    return
  }

  state.value = 'scanning'
  running = true

  // Ten seconds of pointing needs different advice ("more light, hold it
  // flatter") than a fresh start.
  window.setTimeout(() => {
    searching.value = running
  }, 10000)

  tick()
}

let consecutiveErrors = 0

async function tick() {
  if (!running) return

  try {
    const frame = grabFrame()

    if (frame) {
      const results = await detect(frame)

      if (results?.length) {
        found(results[0].rawValue)

        return
      }
    }

    consecutiveErrors = 0
  } catch {
    // One bad frame is normal (video between frames, tab backgrounded). A run of
    // them means the decoder is broken, and spinning would cook the battery.
    if (++consecutiveErrors > 20) {
      fail('The scanner stopped responding. Type the number instead.')

      return
    }
  }

  timer = window.setTimeout(tick, FRAME_INTERVAL_MS)
}

/**
 * The viewfinder rectangle, in the camera's own pixels. The preview is
 * `object-fit: cover`, so the screen shows a centre-crop: scanning the whole frame
 * would decode barcodes the user cannot see, and a naive central box would drift
 * from the drawn rectangle at every aspect ratio. Undoing the cover transform
 * makes the viewfinder mean exactly what it looks like.
 */
function grabFrame() {
  const el = video.value

  // clientWidth is 0 while animating in or hidden; dividing puts NaN in canvas.width.
  if (!el || el.readyState < 2 || !el.videoWidth || !el.clientWidth) return null

  const scale = Math.max(el.clientWidth / el.videoWidth, el.clientHeight / el.videoHeight)
  const visibleW = el.clientWidth / scale
  const visibleH = el.clientHeight / scale

  const cropW = Math.round(visibleW * WINDOW.width)
  const cropH = Math.round(visibleH * WINDOW.height)
  const cropX = Math.round((el.videoWidth - cropW) / 2)
  const cropY = Math.round((el.videoHeight - cropH) / 2)

  const outW = Math.min(cropW, MAX_SCAN_WIDTH)
  const outH = Math.round((cropH * outW) / cropW)

  canvas.width = outW
  canvas.height = outH

  const context = canvas.getContext('2d', { willReadFrequently: true })

  context.drawImage(el, cropX, cropY, cropW, cropH, 0, 0, outW, outH)

  return context.getImageData(0, 0, outW, outH)
}

function found(rawValue) {
  // Stop the camera BEFORE anything else: the lookup can take a second, and a
  // live preview (and torch) through it looks like the scan did not register.
  stop()

  if (navigator.vibrate) navigator.vibrate(30)

  emit('detected', rawValue)
}

async function toggleTorch() {
  const track = stream.value?.getVideoTracks?.()[0]

  if (!track) return

  try {
    await track.applyConstraints({ advanced: [{ torch: !torchOn.value }] })
    torchOn.value = !torchOn.value
  } catch {
    torchSupported.value = false
  }
}

function submitManual() {
  if (!enoughDigits.value) return

  found(digits.value)
}

function fail(text) {
  stop()
  state.value = 'failed'
  message.value = text
}

function stop() {
  running = false
  searching.value = false

  if (timer) {
    window.clearTimeout(timer)
    timer = null
  }

  // Releasing the tracks is what turns the phone's camera indicator off.
  stream.value?.getTracks?.().forEach((track) => track.stop())
  stream.value = null
  torchOn.value = false

  if (video.value) video.value.srcObject = null
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex flex-col bg-stone-950 text-white">
      <!-- Camera -->
      <div class="relative flex-1 overflow-hidden">
        <video
          ref="video"
          class="size-full object-cover"
          playsinline
          muted
          autoplay
        />

        <!-- Viewfinder: four panels, not a shadow box — crisper cut-out at low DPI. -->
        <div v-if="state === 'scanning' || state === 'starting'" class="pointer-events-none absolute inset-0">
          <div class="absolute inset-x-0 top-0 h-[33%] bg-stone-950/55" />
          <div class="absolute inset-x-0 bottom-0 h-[33%] bg-stone-950/55" />
          <div class="absolute inset-y-[33%] left-0 w-[7%] bg-stone-950/55" />
          <div class="absolute inset-y-[33%] right-0 w-[7%] bg-stone-950/55" />

          <div class="absolute inset-y-[33%] inset-x-[7%] rounded-lg border-2 border-white/80" />

          <div
            v-if="state === 'scanning'"
            class="absolute inset-x-[7%] top-1/2 h-0.5 -translate-y-1/2 bg-teal-400/90"
          />
        </div>

        <p
          v-if="state === 'starting'"
          class="absolute inset-x-6 top-1/2 -translate-y-1/2 text-center text-sm text-white/90"
        >
          Starting the camera…<br>
          <span class="text-xs text-white/60">
            iOS asks for permission every time the app is opened.
          </span>
        </p>

        <p
          v-else-if="state === 'scanning'"
          class="absolute inset-x-6 bottom-6 text-center text-sm text-white/90"
        >
          {{ searching
            ? 'Still looking — more light, and hold the barcode flat and filling the box.'
            : 'Point the barcode at the box.' }}
        </p>

        <div
          v-else-if="state === 'denied' || state === 'failed'"
          class="absolute inset-x-6 top-1/2 -translate-y-1/2 rounded-2xl bg-stone-900/95 p-4 text-center"
        >
          <p class="text-sm">{{ message }}</p>

          <button
            v-if="state === 'denied'"
            type="button"
            class="mt-3 rounded-lg bg-white/15 px-3 py-1.5 text-sm font-medium"
            @click="start"
          >
            Ask again
          </button>
        </div>
      </div>

      <!-- Controls -->
      <div class="space-y-3 bg-stone-950 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="flex-1 rounded-xl bg-white/10 py-3 text-sm font-semibold active:scale-[0.99]"
            @click="emit('close')"
          >
            Cancel
          </button>

          <button
            v-if="torchSupported"
            type="button"
            class="rounded-xl px-4 py-3 text-sm font-semibold active:scale-95"
            :class="torchOn ? 'bg-amber-400 text-stone-900' : 'bg-white/10'"
            @click="toggleTorch"
          >
            {{ torchOn ? 'Torch on' : 'Torch' }}
          </button>
        </div>

        <!-- Always available, not only after a failure: a creased barcode, or one
             under a fridge sticker, never scans — and the digits are printed
             right there underneath it. -->
        <!-- `novalidate` and no `pattern`: in a form with a submit button a
             pattern is a veto drawn as a bubble this app cannot word or style.
             `inputmode` brings the keypad up anyway, and whether the digits ARE
             a barcode is the lookup's answer, which comes back as a sentence. -->
        <form class="flex items-center gap-2" novalidate @submit.prevent="submitManual">
          <input
            v-model="manual"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            placeholder="…or type the number under the barcode"
            class="tnum w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2.5 text-base
                   text-white placeholder:text-white/40 focus:border-teal-400 focus:outline-none"
          >
          <button
            type="submit"
            class="shrink-0 rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold disabled:opacity-40"
            :disabled="!enoughDigits"
          >
            Look up
          </button>
        </form>

        <!-- The button gates a network lookup, not a save, so it stays disabled
             on too few digits — but a dead button that says nothing is the same
             silence this app just took out of its forms. -->
        <p v-if="manual && !enoughDigits" class="text-[11px] text-white/50">
          A barcode is at least {{ MIN_BARCODE_DIGITS }} digits — keep going.
        </p>

        <p v-if="engine" class="text-center text-[11px] text-white/35">
          {{ engine === 'wasm' ? 'zxing-wasm decoder' : 'native decoder' }}
        </p>
      </div>
    </div>
  </Teleport>
</template>
