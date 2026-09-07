<script setup>
import { computed } from 'vue'
import { anchorWord, heroFigure, meter, meterLabel, paceNote, rangeLine } from '../lib/balance'
import { bestEstimate, num, percent } from '../lib/format'
import HonestyChip from './HonestyChip.vue'

/**
 * The first thing this app shows: what today's eating did to today's burn.
 *
 * WHAT THE TWO EARLIER CARDS GOT WRONG. The first stacked an "Eaten" bar and a
 * "Burned" bar and set the balance in the same small type as everything else:
 * nothing was wrong, but the number people open this screen for was the smallest
 * thing on the card, and two lengths from a common origin ask the eye to do the
 * subtraction the drawing does not contain. The second put both on ONE TRACK and
 * drew the balance as the overlapping block; it was tested to the percentage
 * point, and the user, holding the phone, said:
 *
 *     "I am still not very happy with the visual slider.
 *      Is there a different way of showing it?"
 *
 * SLIDER is the diagnosis: a rail with a coloured shape part-way along it is the
 * silhouette of a control, and a control means something by WHERE IT IS. That
 * one meant something by how LONG it was, from a seam that was never marked.
 *
 * SO: A METER, CENTRED ON BREAK-EVEN. A hairline axis with its ZERO marked,
 * "under" at one end and "over" at the other, and a bar growing out of that
 * centre — left and teal under the burn, right and orange over it — as far as
 * the day got from break-even. Position is meaning again, because the one point
 * that means anything on its own is now drawn. Deliberately NOT a slider:
 * hairline rather than channel, anchored AT the tick, no thumb and no pill. All
 * of its arithmetic is lib/balance.js, where it is tested; this file places two
 * divs at percentages it was handed. DO NOT restore the single track — it
 * shipped, and this is what the person who uses it said about it.
 *
 * WHAT DID NOT CHANGE, AND MUST NOT
 *
 *   - The balance arrives from the server as a DIRECTION and two unsigned
 *     magnitudes (App\Services\Reporting\EnergyBalance); this component never
 *     subtracts and never sees an intake or a burn. The awkward calls — what
 *     counts as clearly one side, what a straddling band should say, whether
 *     rounding precedes the word — were wrong once, and here they could not be
 *     tested.
 *   - The hero is a single ~midpoint, reversing this card's founding "the range
 *     is the hero" rule: a two-ended signed range in the 40 px slot rendered on
 *     a phone as "+498−738", a dash reading as a minus. The uncertainty stays
 *     HONESTLY VISIBLE three other ways — the ≈, the range line under the hero,
 *     the meter's fade across the band — and the legend still says the intake
 *     as a band. Do not restore the range to the hero.
 *   - Every honesty chip the two-bar card carried is still here, and days with
 *     no balance still get their sentence.
 *   - The colouring is DIRECTIONAL, not evaluative: warm is over your burn, cool
 *     is under it, neither is praise. Goal framing belongs to the reports.
 *
 * ON A DAY IN PROGRESS THE HEADLINE IS THE PROJECTION. A finished food log
 * against a two-thirds-accrued burn is reddest just after a meal and mellows by
 * midnight on its own, so hero, range and meter take the server's `projection`
 * when it sends one — the same subtraction with the rest of the day's resting
 * burn added, from the median of recent complete days
 * (App\Services\Reporting\DayPace). The MEASURED day stays on the card
 * underneath, untouched; a null projection and the card is what it always was.
 *
 * AND THE SIGN CAME OFF THE NUMBER. The hero wore a "+" or a "−" until the user
 * asked for it to go — "It's red, so I know I have eaten more than I had burned
 * off" — and they are right: by the time the eye reaches the digits, the word,
 * the arrow, the colour and the side of centre have all said it. The tiny
 * "eaten − burned" caption went too, since it existed only to say which way
 * round the sign was; the meter's "under / 0 / over" axis states the convention
 * where it is used. Do not put either back without asking them again.
 */
const props = defineProps({
  summary: { type: Object, required: true },
  isToday: { type: Boolean, default: false },
})

const kcalIn = computed(() => props.summary.kcalIn)
const kcalOut = computed(() => props.summary.kcalOut)
const flags = computed(() => props.summary.flags)

/*
 * THE BALANCE ARRIVES AS A DIRECTION, NOT AS A SIGN. This card used to do the
 * subtraction and print the signed band, which on a real day came out as
 * "Surplus  -143–-49 kcal": a minus, a range dash and another minus. The server
 * now says which side of maintenance the day landed on and gives the magnitudes
 * unsigned, small end first.
 */
const balance = computed(() => props.summary.balance ?? null)

/**
 * Where the day is HEADING: `balance`'s shape plus `projected` and the resting
 * figures behind it. Null on a finished day or one with too little history.
 */
const projection = computed(() => props.summary.projection ?? null)

/**
 * The one balance the headline, range line and meter are all drawn from.
 * Choosing it ONCE stops the number and the picture describing different days.
 */
const headline = computed(() => projection.value ?? balance.value)

/**
 * Colour is never the only channel — the word, the arrow and the side of centre
 * say the same thing — so this palette is a fast read rather than the read. Teal
 * under and orange over are the identities the app has used since the two-bar
 * card, and the meter's fills are the same two hues declared in
 * resources/css/app.css.
 */
const DIRECTIONS = {
  deficit: { arrow: '▼', tone: 'text-teal-600 dark:text-teal-400' },
  surplus: { arrow: '▲', tone: 'text-orange-600 dark:text-orange-400' },
  balanced: { arrow: '≈', tone: 'text-stone-500 dark:text-stone-400' },
}

const shown = computed(() => DIRECTIONS[headline.value?.direction] ?? DIRECTIONS.balanced)

/**
 * The hero: ONE unsigned number. "≈ 830" for an estimate, "565" for a typed day
 * (certain, so no ≈), "≈ 0" when the band straddles maintenance. A projection
 * always keeps its ≈ — part of its burn has not happened yet.
 */
const figure = computed(() => heroFigure(headline.value))

/**
 * The honest width, demoted to a small grey line under the hero. Null on a typed
 * day, which is certain and has no range to show.
 */
const rangeText = computed(() => rangeLine(headline.value))

const word = computed(() => anchorWord(headline.value))

/** What was added to the measured burn to reach the projection, and from how
 *  many measured days. Null when the headline is a measurement. */
const paceText = computed(() => paceNote(headline.value))

/**
 * Null exactly when the server sent no balance — nothing logged, or no
 * expenditure figure. There is no relationship to draw on those days and a
 * zero-centred axis would have to invent one, so nothing is drawn.
 */
const geometry = computed(() => meter(headline.value))

/**
 * One side of the meter. `anchor` is the edge pinned to the middle of the rail:
 * the under bar hangs its RIGHT edge there and runs left, the over bar its LEFT
 * edge and runs right.
 */
function bar(side, anchor) {
  if (!side) return null

  return {
    [anchor]: '50%',
    width: `${side.pct}%`,
    // A 29 kcal balance is barely 1% of one side: allowed to be nearly nothing,
    // which is the finding, but not allowed to be nothing.
    minWidth: '3px',
    background: side.background,
    filter: `drop-shadow(0 0 6px ${geometry.value.glow})`,
  }
}

const underBar = computed(() => bar(geometry.value?.under, 'right'))
const overBar = computed(() => bar(geometry.value?.over, 'left'))

/**
 * The two totals, as a legend. They used to swap sides because they named the
 * ends of a track; the meter has a centre instead, so the order is fixed now —
 * eaten first, as the card says them everywhere else. The eaten figure is the
 * hero's own idiom one size down: the estimate, then its width in brackets.
 */
const eaten = computed(() => bestEstimate(kcalIn.value))

const ends = computed(() => [
  {
    key: 'intake',
    label: 'Eaten',
    figure: eaten.value.figure,
    width: eaten.value.width,
    dot: 'bg-teal-500 dark:bg-teal-400',
  },
  {
    key: 'burn',
    // "so far" only under a projection, whose burn is a whole day and this one
    // is not; without it the two numbers look like one quantity disagreeing.
    label: projection.value ? 'Burned so far' : 'Burned',
    figure: num(kcalOut.value),
    // A measured burn is not a band, so there is no width to put beside it.
    width: null,
    dot: 'bg-orange-500 dark:bg-orange-400',
  },
])

/** Nothing at all on the day: no legend, because there is nothing to list. */
const hasFigures = computed(() =>
  (kcalIn.value?.mid ?? null) !== null || (kcalOut.value ?? null) !== null
)

/** The picture, in words, for anybody the picture is not reaching. */
const spoken = computed(() => meterLabel(headline.value))

/**
 * Changing this replays the entrance. Vue patches the existing divs in place,
 * and a CSS animation on an element that was never replaced does not run again,
 * so stepping back a day would swap the numbers with no motion. The key is the
 * figures themselves, so it changes exactly when the picture does.
 */
const revealKey = computed(() =>
  [kcalIn.value.min, kcalIn.value.mid, kcalIn.value.max, kcalOut.value, headline.value?.direction].join('/')
)
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
      <!--
        Today is LIVE, not provisional. The grey pill this replaces said
        "Provisional — day in progress", an apology for the card being correct;
        a pulsing dot says the same as a state rather than a disclaimer, and
        disappears on a day that is over.

        What moves is the ring BEHIND the dot, never the dot itself, so with
        motion gated off — `prefers-reduced-motion: reduce`, or a browser that
        never learned the query — the ring sits behind the dot at its own size
        and is simply not seen, leaving a still dot and two words with nothing
        to undo when the app.css block is skipped.
      -->
      <span
        v-if="isToday"
        class="inline-flex items-center gap-1.5 text-[11px] font-medium text-stone-500 dark:text-stone-400"
      >
        <span class="relative inline-flex size-2 items-center justify-center">
          <span class="bal-live-ring absolute inset-0 rounded-full bg-teal-500/40 dark:bg-teal-400/40" />
          <span class="relative size-2 rounded-full bg-teal-600 dark:bg-teal-400" />
        </span>
        day in progress
      </span>

      <HonestyChip
        v-else-if="!flags.hasFullMetricCoverage"
        tone="warn"
        :label="'Incomplete metric coverage'"
      />
      <HonestyChip
        v-if="flags.activeKcalIsPartial"
        tone="warn"
        :label="`Watch covered ${percent(flags.activeKcalCoverage)} — burn is a floor`"
      />
      <HonestyChip
        v-if="kcalIn.mid !== null && !flags.isCompleteLog"
        tone="warn"
        label="Log looks incomplete"
      />
    </div>

    <div :key="revealKey">
      <!-- THE HEADLINE -->
      <template v-if="headline">
        <p class="bal-word mt-3 flex flex-wrap items-baseline gap-x-1.5 text-sm font-semibold" :class="shown.tone">
          <span><span aria-hidden="true">{{ shown.arrow }}</span> {{ word }}</span>
        </p>

        <!-- ONE number, big, and UNSIGNED: the word above, the colour and the
             side of centre say the direction already. The range line rides the
             same reveal by living inside .bal-figure. -->
        <div class="bal-figure mt-1">
          <p class="flex items-baseline gap-1.5">
            <span
              class="tnum text-[clamp(1.9rem,10vw,2.6rem)] leading-none font-semibold tracking-tight"
              :class="shown.tone"
            >{{ figure }}</span>
            <span class="text-sm font-medium text-stone-400 dark:text-stone-500">kcal</span>
          </p>

          <!-- The honest width, worded so a dash can never read as a sign. -->
          <p
            v-if="rangeText"
            class="tnum mt-0.5 text-xs font-normal text-stone-400 dark:text-stone-500"
          >
            {{ rangeText }}
          </p>

          <!-- Here rather than in a chip because it is the one thing that makes
               an estimate auditable — the measured burn is two lines below. -->
          <p
            v-if="paceText"
            class="tnum mt-0.5 text-[11px] leading-relaxed text-stone-400 dark:text-stone-500"
          >
            {{ paceText }}
          </p>
        </div>
      </template>

      <!-- Nothing to subtract: the sentence takes the headline's place. -->
      <p v-else class="mt-3 text-sm text-stone-500 dark:text-stone-400">
        {{ kcalIn.mid === null ? 'Nothing logged yet — no balance to show.' : 'No expenditure data for this day.' }}
      </p>

      <!-- THE METER -->
      <div
        v-if="geometry"
        class="mt-4"
        role="img"
        :aria-label="spoken"
      >
        <div class="relative h-6 w-full">
          <!-- The axis: a hairline, edge to edge, and NOT part of the entrance —
               the scale is on screen before the day is drawn on it, so the
               reveal reads as growth out of zero, not a shape sliding in. -->
          <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-stone-200 dark:bg-stone-700" />

          <!-- BREAK-EVEN: the one point on this rail that means anything on its
               own, and taller than the bar so it reads when one crosses it. -->
          <div class="absolute top-1/2 left-1/2 h-3.5 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-stone-300 dark:bg-stone-600" />

          <div class="bal-reveal absolute inset-0">
            <!-- UNDER the burn: grows LEFT out of the tick, solid as far as the
                 balance is certain and fading across what the band leaves open. -->
            <div
              v-if="underBar"
              class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-l-[3px]"
              :style="underBar"
            />

            <!-- OVER the burn: the same to the RIGHT. A straddling day draws
                 BOTH, faded outward, because neither side of it is certain. -->
            <div
              v-if="overBar"
              class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-r-[3px]"
              :style="overBar"
            />
          </div>
        </div>

        <!-- The axis in words, so the picture explains its own geometry. -->
        <div class="relative mt-1 h-3 text-[10px] leading-none text-stone-400 dark:text-stone-500">
          <span class="absolute left-0">under</span>
          <span class="tnum absolute left-1/2 -translate-x-1/2">0</span>
          <span class="absolute right-0">over</span>
        </div>
      </div>

      <!-- A legend now rather than labels chasing the ends of a track. -->
      <div
        v-if="hasFigures"
        class="bal-ends mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
      >
        <span v-for="end in ends" :key="end.key" class="inline-flex items-center gap-1.5">
          <span class="size-1.5 shrink-0 rounded-full" :class="end.dot" />
          <span class="text-stone-500 dark:text-stone-400">{{ end.label }}</span>
          <span class="tnum font-medium text-stone-700 dark:text-stone-200">{{ end.figure }}</span>
          <span v-if="end.width" class="tnum text-stone-400 dark:text-stone-500">({{ end.width }})</span>
        </span>
      </div>

      <!-- Where the burn came from — still the only place the split is stated. -->
      <p class="mt-2.5 text-xs text-stone-500 dark:text-stone-400">
        <span class="tnum">{{ num(summary.activeKcal) }}</span> active
        · <span class="tnum">{{ num(summary.restingKcal) }}</span> resting
      </p>
    </div>
  </section>
</template>
