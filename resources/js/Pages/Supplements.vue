<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { useInlineValidation } from '../lib/validation/inline'
import AppLayout from '../Layouts/AppLayout.vue'

/* Async-imported like PhotoCapture on the day view: it drags the canvas
 * re-encoder in, and this page is usually opened to flip a switch. */
const LabelCapture = defineAsyncComponent(() => import('../Components/LabelCapture.vue'))

/**
 * The shelf — not a setup wizard. What is actually taken is already seeded from
 * the manufacturers' published panels, so the common visits are: switch one off
 * when the bottle runs out, switch its replacement on, correct a figure against
 * the label. Adding a new one is therefore at the BOTTOM, the camera one of two
 * equal options — a "photograph a label" headline would suggest a chore nobody
 * has to do. EVERY SEEDED FIGURE IS EDITABLE, and the row links to the page it
 * was read off: the seed is a starting position, not an authority, and the
 * bottle in your hand outranks it.
 */
const props = defineProps({
  supplements: { type: Array, default: () => [] },
})

const active = computed(() => props.supplements.filter((s) => s.active))
const inactive = computed(() => props.supplements.filter((s) => !s.active))

// --- adding -------------------------------------------------------------
const capturing = ref(false)
const editingId = ref(null)

const blank = () => ({
  name: '',
  brand: '',
  serving_text: '',
  units_per_day: 1,
  active: true,
  notes: '',
  client_id: null,
  nutrients: [],
})

const form = useForm(blank())

/**
 * Each box says SupplementRequest's own sentence as it is left. Not one
 * ceiling on any of them is the browser's to enforce: a native one refuses
 * silently — on iOS without drawing anything — and a silently refused save is
 * the bug DecimalInputTest was written for.
 */
const inline = useInlineValidation(form, 'SupplementRequest')

const adding = ref(false)

function startByHand() {
  form.defaults(blank())
  form.reset()
  form.clearErrors()
  inline.reset()
  editingId.value = null
  adding.value = true
}

function startEdit(supplement) {
  form.defaults({
    name: supplement.name,
    brand: supplement.brand ?? '',
    serving_text: supplement.servingText ?? '',
    units_per_day: supplement.unitsPerDay,
    active: supplement.active,
    notes: supplement.notes ?? '',
    client_id: null,
    // A copy, not the prop: a cancelled edit must not leave changes on screen.
    nutrients: supplement.nutrients.map((n) => ({
      nutrient: n.nutrient,
      amount: n.amount === null ? '' : String(n.amount),
      unit: n.unit ?? '',
    })),
  })
  form.reset()
  form.clearErrors()
  inline.reset()
  adding.value = false
  editingId.value = supplement.id
}

/** Straight into the form the hand-entry path uses — one review screen, whether
 * the lines were transcribed by a model or typed. */
function onLabelRead({ clientId, label }) {
  capturing.value = false

  form.defaults({
    name: label?.name ?? '',
    brand: label?.brand ?? '',
    serving_text: label?.servingText ?? '',
    units_per_day: 1,
    active: true,
    notes: label?.notes ?? '',
    client_id: clientId,
    nutrients: (label?.nutrients ?? []).map((n) => ({
      nutrient: n.nutrient,
      amount: n.amount === null ? '' : String(n.amount),
      unit: n.unit ?? '',
    })),
  })
  form.reset()
  form.clearErrors()
  inline.reset()
  editingId.value = null
  adding.value = true
}

function addRow() {
  form.nutrients.push({ nutrient: '', amount: '', unit: '' })
}

function removeRow(index) {
  form.nutrients.splice(index, 1)
}

function cancel() {
  adding.value = false
  editingId.value = null
}

function save() {
  if (editingId.value !== null) {
    form.put(`/supplements/${editingId.value}`, { onSuccess: cancel, preserveScroll: true })

    return
  }

  form.post('/supplements', { onSuccess: cancel, preserveScroll: true })
}

/** The on/off switch without opening the editor. It posts the CURRENT values
 * plus the flipped flag because the endpoint is a PUT of the whole thing — one
 * write path rather than a general one and a special one for a toggle. */
function toggleActive(supplement) {
  router.put(
    `/supplements/${supplement.id}`,
    {
      name: supplement.name,
      brand: supplement.brand ?? '',
      serving_text: supplement.servingText ?? '',
      units_per_day: supplement.unitsPerDay,
      active: !supplement.active,
      notes: supplement.notes ?? '',
      nutrients: supplement.nutrients.map((n) => ({
        nutrient: n.nutrient,
        amount: n.amount === null ? '' : String(n.amount),
        unit: n.unit ?? '',
      })),
    },
    { preserveScroll: true }
  )
}

function destroy(supplement) {
  if (!window.confirm(`Delete ${supplement.name}? Every day it was ticked goes with it.`)) return

  router.delete(`/supplements/${supplement.id}`, { preserveScroll: true })
}

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <Head title="Supplements" />

  <AppLayout title="Supplements">
    <Link href="/" class="mb-3 inline-block text-xs font-medium text-teal-700 dark:text-teal-400">
      ← Back to the day
    </Link>

    <div class="space-y-4">
      <!-- ============================ the list ============================ -->
      <section v-if="active.length > 0" class="space-y-2">
        <h2 class="text-sm font-semibold">Taking now</h2>

        <article
          v-for="supplement in active"
          :key="supplement.id"
          class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="flex items-start gap-3">
            <img
              v-if="supplement.photoUrl"
              :src="supplement.photoUrl"
              alt=""
              class="size-10 shrink-0 rounded-lg object-cover"
              loading="lazy"
            >

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold">{{ supplement.name }}</p>
              <p class="truncate text-xs text-stone-500 dark:text-stone-400">
                <template v-if="supplement.brand">{{ supplement.brand }} · </template>
                <template v-if="supplement.unitsPerDay > 1">{{ supplement.unitsPerDay }} × </template>{{ supplement.servingText || 'per dag' }}
              </p>
            </div>

            <button
              type="button"
              class="shrink-0 rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium
                     text-stone-600 active:scale-95 dark:border-stone-700 dark:text-stone-300"
              @click="startEdit(supplement)"
            >
              Edit
            </button>
          </div>

          <!-- The printed figure sits next to it, so the row can be checked
               against the bottle without opening anything. -->
          <ul v-if="supplement.nutrients.length > 0" class="mt-3 space-y-0.5">
            <li
              v-for="(line, index) in supplement.nutrients"
              :key="index"
              class="flex items-baseline justify-between gap-3 text-xs"
            >
              <span class="min-w-0 truncate text-stone-600 dark:text-stone-300">{{ line.nutrient }}</span>
              <span class="tnum shrink-0 text-stone-500 dark:text-stone-400">
                <template v-if="line.perDay === null">—</template>
                <template v-else>
                  {{ line.perDay }} {{ line.unit }}<template v-if="supplement.unitsPerDay > 1"><span class="opacity-60"> ({{ line.amount }} × {{ supplement.unitsPerDay }})</span></template>
                </template>
              </span>
            </li>
          </ul>

          <!-- Provenance, and the standing question if there is one. -->
          <p v-if="supplement.notes" class="mt-2 rounded-lg bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {{ supplement.notes }}
          </p>

          <p class="mt-2 text-[11px] text-stone-400 dark:text-stone-500">
            <template v-if="supplement.dataSource === 'manufacturer_published'">From the manufacturer's published panel</template>
            <template v-else-if="supplement.dataSource === 'retailer_published'">From a published product page</template>
            <template v-else-if="supplement.dataSource === 'label_photo'">Read off a photo of the label</template>
            <template v-else>Entered by hand</template>
            <template v-if="supplement.sourceUrl">
              ·
              <a :href="supplement.sourceUrl" target="_blank" rel="noopener noreferrer" class="underline">source</a>
            </template>
          </p>

          <button
            type="button"
            class="mt-2 text-xs font-medium text-stone-500 dark:text-stone-400"
            @click="toggleActive(supplement)"
          >
            Stop taking this
          </button>
        </article>
      </section>

      <p
        v-else
        class="rounded-2xl border border-dashed border-stone-300 px-4 py-6 text-center text-sm
               text-stone-500 dark:border-stone-700 dark:text-stone-400"
      >
        Nothing switched on. Add one below, or turn one back on.
      </p>

      <!-- ========================== the cupboard ========================== -->
      <section v-if="inactive.length > 0" class="space-y-2">
        <h2 class="text-sm font-semibold">Not taking</h2>
        <p class="text-xs text-stone-500 dark:text-stone-400">
          Off the day card and out of the adherence count. Every day they were
          ticked is still there.
        </p>

        <article
          v-for="supplement in inactive"
          :key="supplement.id"
          class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 p-3
                 dark:border-stone-800 dark:bg-stone-900/50"
        >
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-stone-600 dark:text-stone-300">{{ supplement.name }}</p>
            <p class="truncate text-[11px] text-stone-500 dark:text-stone-400">
              <template v-if="supplement.brand">{{ supplement.brand }} · </template>
              <template v-if="supplement.unitsPerDay > 1">{{ supplement.unitsPerDay }} × </template>{{ supplement.servingText || 'per dag' }}
            </p>
          </div>

          <button
            type="button"
            class="shrink-0 rounded-lg bg-teal-600 px-2.5 py-1 text-xs font-semibold text-white active:scale-95"
            @click="toggleActive(supplement)"
          >
            Start
          </button>

          <button
            type="button"
            class="shrink-0 rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium
                   text-stone-600 active:scale-95 dark:border-stone-700 dark:text-stone-300"
            @click="startEdit(supplement)"
          >
            Edit
          </button>
        </article>
      </section>

      <!-- ============================ add / edit ========================== -->
      <section
        v-if="adding || editingId !== null"
        class="space-y-3 rounded-2xl border border-teal-500/40 bg-white p-4 dark:bg-stone-900"
      >
        <h2 class="text-sm font-semibold">
          {{ editingId === null ? 'New supplement' : 'Edit supplement' }}
        </h2>

        <label class="block">
          <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Name</span>
          <input
            v-model="form.name"
            type="text"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('name')"
          >
          <span v-if="form.errors.name" class="text-xs text-rose-600">{{ form.errors.name }}</span>
        </label>

        <div class="grid grid-cols-2 gap-2">
          <label class="block">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Brand</span>
            <input
              v-model="form.brand"
              type="text"
              :class="[inputClass, 'mt-1']"
              v-bind="inline.on('brand')"
            >
            <span v-if="form.errors.brand" class="text-xs text-rose-600">{{ form.errors.brand }}</span>
          </label>

          <label class="block">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">How many a day</span>
            <input
              v-model.number="form.units_per_day"
              type="number"
              inputmode="numeric"
              :class="[inputClass, 'mt-1 tnum']"
              v-bind="inline.on('units_per_day')"
            >
            <span v-if="form.errors.units_per_day" class="text-xs text-rose-600">{{ form.errors.units_per_day }}</span>
          </label>
        </div>

        <label class="block">
          <span class="text-xs font-medium text-stone-500 dark:text-stone-400">
            Serving the figures are for
          </span>
          <input
            v-model="form.serving_text"
            type="text"
            placeholder="per capsule, per 2 tabletten…"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('serving_text')"
          >
          <span v-if="form.errors.serving_text" class="text-xs text-rose-600">{{ form.errors.serving_text }}</span>
          <!-- The one arithmetic rule, said where it is being applied. -->
          <span class="mt-1 block text-[11px] text-stone-500">
            Copy this from the panel. The amounts below are for ONE of these, and
            a day comes to that × {{ form.units_per_day || 1 }}.
          </span>
        </label>

        <div class="space-y-2">
          <div class="flex items-baseline justify-between">
            <span class="text-xs font-medium text-stone-500 dark:text-stone-400">What it contains</span>
            <button type="button" class="text-xs font-medium text-teal-700 dark:text-teal-400" @click="addRow">
              Add a line
            </button>
          </div>

          <div v-for="(line, index) in form.nutrients" :key="index" class="flex items-center gap-1.5">
            <input
              v-model="line.nutrient"
              type="text"
              placeholder="Magnesium"
              :class="[inputClass, 'flex-1']"
              v-bind="inline.on(`nutrients.${index}.nutrient`)"
            >
            <input
              v-model="line.amount"
              type="text"
              inputmode="decimal"
              placeholder="120"
              :class="[inputClass, 'w-20 tnum']"
              v-bind="inline.on(`nutrients.${index}.amount`)"
            >
            <input
              v-model="line.unit"
              type="text"
              placeholder="mg"
              :class="[inputClass, 'w-16']"
              v-bind="inline.on(`nutrients.${index}.unit`)"
            >
            <button
              type="button"
              class="shrink-0 px-1 text-lg text-stone-400"
              aria-label="Remove this line"
              @click="removeRow(index)"
            >
              ×
            </button>
          </div>

          <p v-if="form.nutrients.length === 0" class="text-xs text-stone-500 dark:text-stone-400">
            Optional. A supplement with no figures still ticks off every evening —
            the figures are what a future weekly report will add up.
          </p>
        </div>

        <label class="flex items-center gap-2">
          <input v-model="form.active" type="checkbox" class="size-4 rounded">
          <span class="text-sm">Taking it now</span>
        </label>
        <p class="-mt-2 text-[11px] text-stone-500">
          Leave it off for a bottle you have bought but not started. It stays off
          the day card until you switch it on.
        </p>

        <label class="block">
          <span class="text-xs font-medium text-stone-500 dark:text-stone-400">Note</span>
          <input
            v-model="form.notes"
            type="text"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('notes')"
          >
          <span v-if="form.errors.notes" class="text-xs text-rose-600">{{ form.errors.notes }}</span>
        </label>

        <div class="flex gap-2 pt-1">
          <button
            type="button"
            class="flex-1 rounded-xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99] disabled:opacity-60"
            :disabled="form.processing"
            @click="save"
          >
            {{ form.processing ? 'Saving…' : 'Save' }}
          </button>

          <button
            type="button"
            class="rounded-xl border border-stone-300 px-4 py-3 text-base font-medium
                   text-stone-600 active:scale-[0.99] dark:border-stone-700 dark:text-stone-300"
            @click="cancel"
          >
            Cancel
          </button>
        </div>

        <button
          v-if="editingId !== null"
          type="button"
          class="text-xs font-medium text-rose-600 dark:text-rose-400"
          @click="destroy(supplements.find((s) => s.id === editingId))"
        >
          Delete this supplement and its history
        </button>
      </section>

      <!-- ============================== adding =========================== -->
      <section v-else class="space-y-2">
        <div class="flex gap-2">
          <button
            type="button"
            class="flex-1 rounded-2xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99]"
            @click="startByHand"
          >
            Add a supplement
          </button>

          <button
            type="button"
            class="rounded-2xl border border-teal-600/40 px-4 py-3 text-base font-semibold
                   text-teal-700 active:scale-[0.99] dark:border-teal-400/40 dark:text-teal-400"
            @click="capturing = true"
          >
            Read a label
          </button>
        </div>

        <p class="px-1 text-[11px] text-stone-500 dark:text-stone-400">
          "Read a label" photographs the supplement-facts panel and fills the form
          in for you to check. Useful for something new — the ones already here
          came with their figures.
        </p>
      </section>
    </div>

    <LabelCapture v-if="capturing" @read="onLabelRead" @close="capturing = false" />
  </AppLayout>
</template>
