<script setup>
import AirDatepicker from 'air-datepicker'
import localeEn from 'air-datepicker/locale/en.js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { pickerAnchor, pickerBounds, pickerDates, pickerSelectedDates, pickerSelection } from '../lib/air-date'
import { toIsoDate } from '../lib/dates'

/**
 * One date, picked from a calendar.
 *
 * WHAT THIS REPLACED. Every date box used to be a native `<input type="date">`,
 * on the argument that the phone already ships a picker. WebKit's wheel is three
 * spinning columns that cannot show you a month, and each browser draws its own,
 * so the boxes looked like three different controls. Air Datepicker is ~10 KB of
 * dependency-free MIT JavaScript, no jQuery, themed entirely through CSS custom
 * properties. It is BUNDLED, not loaded from a CDN, because this app is
 * installed to a home screen and is expected to work with no network at all.
 *
 * THIS COMPONENT IS DELIBERATELY THIN: the lifecycle (one instance per mounted
 * input, destroyed on unmount), the direction of data (YYYY-MM-DD in, YYYY-MM-DD
 * out) and the options that must be the same everywhere. The trigger and the
 * value belong to the caller — DateJump renders it invisible over a header
 * label, ReportForm and Profile as an ordinary box.
 *
 * THE ROOT ELEMENT IS THE INPUT, and not stylistically: Air Datepicker binds its
 * show-on-focus, keyboard navigation and mobile behaviour only when given an
 * `<input>`, and treats anything else as an INLINE calendar rendered permanently
 * open. It also lets `class`, `aria-label` and `placeholder` fall through onto
 * the thing they describe.
 *
 * `isMobile: true` UNCONDITIONALLY, because it is a mode and not a media query:
 * a centred `position: fixed` panel over a backdrop that dismisses on tap, every
 * cell a 38 px thumb target, and `readonly` on the input so iOS opens no
 * software keyboard behind it. The alternative anchors a popover to the trigger,
 * which on the day view would hang off a label in a sticky header — and the
 * panel is 320 px wide, most of an iPhone. `readonly` is ALSO set in the
 * template, because the library sets it during construction, after Vue has
 * painted the input once, and a briefly typeable box is one iOS may briefly
 * offer a keyboard for.
 *
 * THE INPUT'S TEXT IS NOT BOUND. Air Datepicker writes the formatted date into
 * `$el.value` itself and dispatches `change`; binding Vue to the same property
 * would be two writers on one string, a re-render away from showing the ISO date
 * the model holds instead of the words the user reads. Model and box hold
 * different things by design, and the ONE place they are converted is
 * lib/dates.js, where the timezone reasoning lives.
 *
 * NOT ONE NULL REACHES THE LIBRARY. Air Datepicker 3.6.0 merges options with a
 * `deepMerge` that calls `value.toString()` on anything not `undefined`, so a
 * single null throws a TypeError out of the constructor and the picker never
 * exists. Passing `minDate`/`maxDate` as null whenever that end was open is what
 * broke the profile's date of birth in production. EVERY VALUE-DERIVED OPTION
 * THEREFORE COMES OUT OF lib/air-date.js, which is the half of this component
 * that has tests; nothing below builds one inline.
 */
const props = defineProps({
  /** The selected date as YYYY-MM-DD, or '' for none. */
  modelValue: { type: String, default: '' },

  /** The ends of the range, YYYY-MM-DD. Null leaves that end open, which is what
   *  an empty history sends (see HistorySpan): a floor invented for a database
   *  not yet read would hide real days behind a dead arrow. */
  min: { type: String, default: null },
  max: { type: String, default: null },

  /** What the BOX reads, in Air Datepicker's tokens — never what is emitted.
   *  `E d MMM yyyy` is "Mon 15 Jun 2026", the shape the day header uses. */
  format: { type: String, default: 'E d MMM yyyy' },
})

const emit = defineEmits(['update:modelValue'])

const input = ref(null)

/**
 * The live instance. A plain `let`, not a ref: nothing renders from it, and a
 * deep proxy over a third-party object with its own DOM buys nothing.
 */
let picker = null

/**
 * Tapping the ALREADY selected day closes the picker and changes nothing.
 *
 * `toggleSelected` is the only hook called on that tap, and its contract is
 * "should this tap unselect the date": false keeps the selection, since a picker
 * that empties itself on a double tap is how a required date goes missing.
 * Hiding from inside it is the second half — otherwise the tap does nothing, and
 * the native wheel this replaced always dismissed on a choice.
 */
function closeInsteadOfClearing() {
  picker?.hide()

  return false
}

onMounted(() => {
  const el = input.value
  const selected = pickerSelection(props.modelValue, props.min, props.max)

  picker = new AirDatepicker(el, {
    locale: localeEn,

    // The English locale ships Sunday-first. Every other week in this app starts
    // on Monday — the stress page, the report's ranges, the server's week
    // boundaries — and a calendar disagreeing would be read wrong on the one
    // page where the pick IS a week.
    firstDay: 1,

    isMobile: true,
    autoClose: true,
    toggleSelected: closeInsteadOfClearing,

    dateFormat: props.format,
    navTitles: { days: 'MMMM <i>yyyy</i>' },

    // The four options that depend on a value, and so the four a caller can null.
    ...pickerDates(props.modelValue, props.min, props.max),

    onSelect: ({ date }) => emit('update:modelValue', toIsoDate(date)),
  })

  // Air Datepicker fills the box on a `setTimeout`, which is one painted frame
  // of empty box on every screen that arrives with a date already in it.
  if (selected) el.value = picker.formatDate(selected, props.format)
})

/**
 * The value changing UNDER us — a jump that landed, a form reset, an Inertia
 * visit sending a different day to the same mounted component. `silent` stops
 * the loop: selecting normally fires `onSelect`, which emits, which the parent
 * may write straight back.
 */
watch(() => props.modelValue, (value) => {
  if (!picker || toIsoDate(picker.selectedDates[0]) === value) return

  const date = pickerSelection(value, props.min, props.max)

  if (date) {
    picker.selectDate(date, { silent: true })
    picker.setViewDate(date)
  } else {
    // Emptied: the view goes back to where an empty picker opens rather than
    // staying on the month of a date no longer selected anywhere.
    picker.clear({ silent: true })
    picker.setViewDate(pickerAnchor('', props.min, props.max))
  }
})

/** The history's floor moves as soon as an older day is ingested. */
watch(() => [props.min, props.max], ([min, max]) => {
  /*
   * `selectedDates` has to ride along: `update` re-applies `opts.selectedDates`,
   * and `opts` still holds the value this picker was CONSTRUCTED with, since
   * `selectDate` writes to the instance and never back to the options. Sending
   * the current model stops a moving bound reverting the box to its birth date.
   * `silent`, because a bound moving is not the user picking anything.
   */
  picker?.update(
    { ...pickerBounds(min, max), selectedDates: pickerSelectedDates(props.modelValue, min, max) },
    { silent: true },
  )
})

/**
 * The panel and backdrop live on `document.body`, outside this tree, so nothing
 * about unmounting removes them — and every screen using this is an Inertia page
 * that gets swapped out.
 */
onBeforeUnmount(() => {
  picker?.destroy()
  picker = null
})
</script>

<template>
  <input ref="input" type="text" readonly autocomplete="off" spellcheck="false" />
</template>
