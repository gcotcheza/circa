<script setup>
import { computed, ref, watch } from 'vue'
import { forPortion, lookupProduct } from '../lib/products'
import { boxText, typedIn } from '../lib/boxes'
import { dayMonth } from '../lib/dates'
import { decimal, num } from '../lib/format'

/**
 * What a scanned barcode turned out to be, and how much of it was eaten.
 *
 * THE NUMBERS ARE EDITABLE, AND THAT IS THE POINT. Open Food Facts is
 * crowd-sourced and a few entries are simply wrong — SPEC.md's reason for parsing
 * OFF into columns rather than reading the jsonb back: "a bad OFF entry is
 * correctable locally". So the per-100 g figures are pre-filled, not imposed; what
 * is logged is what is on screen when "Add to meal" is tapped, and
 * `food_product_id` records that the numbers CAME from this barcode without
 * claiming they are still identical to it. A missing energy figure forces the
 * panel open, kcal being the one value a meal cannot be saved without.
 *
 * Every value is ZERO-WIDTH: a declaration is point data, not an estimate, so
 * unlike the vision path there is no honest range. The uncertainty is in the grams.
 */
const props = defineProps({
  barcode: { type: String, required: true },
})

const emit = defineEmits(['add', 'manual', 'close', 'rescan'])

const state = ref('loading')
const message = ref('')
const source = ref(null)
const product = ref(null)

const name = ref('')
const grams = ref(100)
const editing = ref(false)
const values = ref({ kcal: null, protein: null, carbs: null, fat: null })

// `() => load()` not `load`: a watcher passes the new value, `load` takes options.
watch(() => props.barcode, () => load(), { immediate: true })

async function load({ refresh = false } = {}) {
  state.value = 'loading'
  message.value = ''

  const result = await lookupProduct(props.barcode, { refresh: refresh === true })

  if (result.status !== 'found') {
    state.value = result.status
    message.value = result.message ?? 'The lookup failed.'

    return
  }

  source.value = result.source
  product.value = result.product

  name.value = result.product.name
  grams.value = 100
  values.value = {
    kcal: result.product.kcalPer100g,
    protein: result.product.proteinPer100g,
    carbs: result.product.carbsPer100g,
    fat: result.product.fatPer100g,
  }

  // Nothing can be logged until a kcal figure is typed, so open the editor rather
  // than raise an error the user has to decode.
  editing.value = result.product.kcalPer100g === null

  state.value = 'found'
}

const portion = computed(() => ({
  kcal: forPortion(values.value.kcal, grams.value),
  protein: forPortion(values.value.protein, grams.value),
  carbs: forPortion(values.value.carbs, grams.value),
  fat: forPortion(values.value.fat, grams.value),
}))

const missingMacros = computed(() =>
  ['protein', 'carbs', 'fat'].filter((macro) => values.value[macro] === null || values.value[macro] === '')
)

const canAdd = computed(
  () => Number(values.value.kcal) >= 0 && values.value.kcal !== null && values.value.kcal !== '' && Number(grams.value) > 0
)

const fetched = computed(() => {
  if (!product.value?.fetchedAt) return ''

  return dayMonth(new Date(product.value.fetchedAt))
})

function add() {
  if (!canAdd.value) return

  const density = (key) => (values.value[key] === null || values.value[key] === '' ? null : Number(values.value[key]))

  emit('add', {
    name: name.value.trim() || product.value.name,
    basis: 'per_100g',
    // The whole product, as the packet declares it. "I ate half the bar" is the
    // share chip on the item row: the declaration is a fact about the food.
    share_fraction: 1,
    kcal: null,
    protein: null,
    carbs: null,
    fat: null,
    grams: Number(grams.value),
    kcal_per_100g: density('kcal'),
    protein_per_100g: density('protein'),
    carbs_per_100g: density('carbs'),
    fat_per_100g: density('fat'),
    // The provenance link. Nullable on the row; set here and nowhere else.
    food_product_id: product.value.barcode,
  })
}

/**
 * A per-100 g figure as the TEXT of its box, at the precision the label claims.
 * OFF stores energy in kJ as often as kcal, so a converted one arrives as 430.211
 * (app/Services/Food/Nutriments::kcalPer100g divides by 4.184, three decimals) —
 * four of those across a phone screen have their ends cut off, and the third
 * decimal of a kJ conversion was never declared by any contributor. A figure the
 * user corrects is left exactly as typed; lib/boxes.js has the whole rule. The
 * grams box skips this: it holds 100 or a typed weight, neither with a tail to hide.
 */
function box(key) {
  return boxText(values.value, key, values.value[key])
}

function editValue(key, raw) {
  typedIn(values.value, key, raw)

  values.value[key] = decimal(raw)
}

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex flex-col justify-end">
      <div class="absolute inset-0 bg-stone-900/50 backdrop-blur-[2px]" @click="emit('close')" />

      <div class="relative max-h-[92dvh] overflow-y-auto rounded-t-3xl bg-stone-50 pb-[env(safe-area-inset-bottom)] dark:bg-stone-950">
        <div
          class="sticky top-0 z-10 flex items-center justify-between border-b border-stone-200 bg-stone-50/95 px-4 py-3
                    backdrop-blur dark:border-stone-800 dark:bg-stone-950/95"
        >
          <button type="button" class="text-sm text-stone-500" @click="emit('close')">Cancel</button>
          <h2 class="text-sm font-semibold">Scanned product</h2>
          <span class="tnum text-[11px] text-stone-400">{{ barcode }}</span>
        </div>

        <!-- Looking it up -->
        <div v-if="state === 'loading'" class="px-4 py-10 text-center text-sm text-stone-500">
          Looking up {{ barcode }}…
        </div>

        <!-- Not in Open Food Facts: the scan worked, the database is incomplete.
             Manual entry with the barcode attached is the useful next step. -->
        <div v-else-if="state === 'not_found'" class="space-y-3 px-4 py-6">
          <p class="text-sm font-medium">Not in Open Food Facts</p>
          <p class="text-sm text-stone-500 dark:text-stone-400">
            The barcode scanned cleanly — <span class="tnum">{{ barcode }}</span> — but nobody has
            added this product yet. Enter it by hand; the barcode goes in the notes so it can be
            matched up later.
          </p>

          <button
            type="button"
            class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99]"
            @click="emit('manual', barcode)"
          >
            Enter it manually
          </button>

          <button type="button" class="w-full py-2 text-sm text-stone-500" @click="emit('rescan')">
            Scan a different barcode
          </button>
        </div>

        <!-- Anything else that went wrong: retryable, and said so. -->
        <div v-else-if="state !== 'found'" class="space-y-3 px-4 py-6">
          <p class="text-sm font-medium">
            {{ state === 'invalid_barcode' ? 'Not a barcode' : 'Lookup failed' }}
          </p>
          <p class="text-sm text-stone-500 dark:text-stone-400">{{ message }}</p>

          <button
            v-if="state !== 'invalid_barcode'"
            type="button"
            class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99]"
            @click="load()"
          >
            Try again
          </button>

          <button
            type="button"
            class="w-full rounded-xl border border-stone-300 py-2.5 text-sm font-medium dark:border-stone-700"
            @click="emit('manual', barcode)"
          >
            Enter it manually instead
          </button>
        </div>

        <!-- Found -->
        <div v-else class="space-y-4 px-4 py-4">
          <div class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
            <input v-model="name" type="text" :class="[inputClass, 'font-semibold']">

            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-stone-500 dark:text-stone-400">
              <span v-if="product.brand">{{ product.brand }}</span>
              <span v-if="product.brand">·</span>
              <!-- Where the number came from. "Cached" is not a disclaimer but the reason it was instant. -->
              <span>{{ source === 'cache' ? `Cached · fetched ${fetched}` : 'Open Food Facts' }}</span>
              <button
                type="button"
                class="text-teal-600 underline-offset-2 hover:underline dark:text-teal-400"
                @click="load({ refresh: true })"
              >
                refresh
              </button>
            </p>
          </div>

          <!-- Portion -->
          <label class="block">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">How much?</span>
            <div class="mt-1 flex items-center gap-2">
              <input
                :value="grams"
                type="text"
                inputmode="decimal"
                :class="[inputClass, 'tnum']"
                @input="grams = decimal($event.target.value)"
              >
              <span class="text-sm text-stone-500">g</span>
            </div>
          </label>

          <div class="flex gap-2">
            <button
              v-for="preset in [30, 50, 100, 200]"
              :key="preset"
              type="button"
              class="tnum flex-1 rounded-lg border border-stone-300 py-1.5 text-xs font-medium
                     active:scale-95 dark:border-stone-700"
              :class="Number(grams) === preset ? 'border-teal-500 text-teal-600 dark:text-teal-400' : 'text-stone-500'"
              @click="grams = preset"
            >
              {{ preset }} g
            </button>
          </div>

          <!-- What that portion is, recomputed as the grams change. -->
          <div class="grid grid-cols-4 gap-2 rounded-2xl bg-teal-600/10 p-3 text-center">
            <div>
              <p class="tnum text-lg font-semibold text-teal-700 dark:text-teal-300">{{ num(portion.kcal, 0) }}</p>
              <p class="text-[11px] text-stone-500">kcal</p>
            </div>
            <div v-for="macro in ['protein', 'carbs', 'fat']" :key="macro">
              <p class="tnum text-lg font-semibold">{{ num(portion[macro], 1) }}</p>
              <p class="text-[11px] capitalize text-stone-500">{{ macro }}</p>
            </div>
          </div>

          <p v-if="missingMacros.length" class="text-xs text-amber-700 dark:text-amber-400">
            Open Food Facts has no {{ missingMacros.join(', ') }} figure for this product.
            Left blank it is logged as zero — type it off the label if it matters.
          </p>

          <!-- Per-100 g values, correctable -->
          <button
            type="button"
            class="text-xs font-medium text-teal-600 dark:text-teal-400"
            @click="editing = !editing"
          >
            {{ editing ? 'Hide' : 'Check / correct' }} the per-100 g values
          </button>

          <div v-if="editing" class="grid grid-cols-4 gap-2">
            <label v-for="key in ['kcal', 'protein', 'carbs', 'fat']" :key="key">
              <span class="text-[11px] text-stone-500">{{ key === 'kcal' ? 'kcal/100' : `${key[0].toUpperCase()}/100` }}</span>
              <input
                :value="box(key)"
                type="text"
                inputmode="decimal"
                :class="[inputClass, 'tnum']"
                @input="editValue(key, $event.target.value)"
              >
            </label>
          </div>

          <button
            type="button"
            :disabled="!canAdd"
            class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white
                   transition active:scale-[0.99] disabled:opacity-50"
            @click="add"
          >
            Add to meal
          </button>

          <button type="button" class="w-full py-2 text-sm text-stone-500" @click="emit('rescan')">
            Scan another
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
