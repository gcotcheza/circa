<script setup>
import { computed } from 'vue'
import { bandFill } from '../lib/stress'

/**
 * One week of daily scores: seven points, band-coloured, with the gaps left in.
 *
 * ---------------------------------------------------------------------------
 * THE LINE IS DRAWN ONLY BETWEEN CONSECUTIVE SCORED DAYS
 *
 * Three days without a Watch are three days of no information; one stroke across
 * them would be a confident segment through nothing — the mistake the weight
 * chart avoids by joining only days that have a weigh-in. The segments break, so
 * a week with holes LOOKS like one.
 *
 * The band stripes are the only grid: 80 and 50 and 20 are the numbers that mean
 * something on this axis, and faint horizontal fields rather than labelled lines
 * keep it readable at 350 px wide, the only width it will ever have.
 *
 * Every point also carries its score, deliberate redundancy: the
 * Pay-attention/Great pair sits in the colour-vision warning band (lib/stress.js),
 * so the number is the channel that always works.
 *
 * ---------------------------------------------------------------------------
 * TRAINING DAYS ARE MARKS UNDER THE AXIS, NOT A SECOND SERIES
 *
 * A workout is not a quantity that shares this axis with a 1-99 score, and a line
 * or a bar would invite the reading the report's own prompt forbids: that the
 * session CAUSED the score. A dot beneath the weekday says the one true thing —
 * there was a session that day.
 *
 * ---------------------------------------------------------------------------
 * THE WEEK DRAWS ITSELF, MONDAY TO SUNDAY
 *
 * One pen, one pass, left to right, each dot deposited as the drawing front
 * reaches it: a week being written down, which buys a second of attention on a
 * chart otherwise identical to yesterday's.
 *
 * The timing is arc length, NOT index. A dot every DRAW_MS/6 would be right only
 * if every leg were the same length; on a 1-99 axis with real swings a flat
 * 44-to-91 Thursday is nearly twice the ink of a level Tuesday, and paced by
 * index the line visibly overshoots its own dots by mid-week. So each leg is
 * measured (plain `Math.hypot` on coordinates this file already computes — no
 * `getTotalLength()`, nothing off the DOM, nothing depending on layout), the
 * dash animation is LINEAR, and a dot's delay is its cumulative length over the
 * total. Linear is what makes those two commensurable: eased, the front's
 * position would no longer be proportional to elapsed time and every delay would
 * have to run back through the inverse of the curve. The softness comes from the
 * dots instead, which pop with a gentle ease-out as they land.
 *
 * A GAP IS TRAVEL, NOT A JUMP. An unscored leg draws nothing but still costs its
 * horizontal distance on the timeline; without that the front teleports across
 * the hole and lands early on the far side.
 *
 * All of it lives behind `prefers-reduced-motion: no-preference` in app.css —
 * including the initial hidden state, a keyframe rather than a base rule so that
 * a browser running no animation still paints the finished chart. See that block.
 * ---------------------------------------------------------------------------
 */
const props = defineProps({
  days: { type: Array, required: true },
  bands: { type: Array, required: true },
})

const W = 320
const H = 118
const PAD_L = 20
const PAD_R = 6
const PAD_T = 8
const PAD_B = 26

const step = computed(() => (W - PAD_L - PAD_R) / props.days.length)

const x = (index) => PAD_L + index * step.value + step.value / 2

// Fixed at the published 1-99 scale: auto-scaling to the week's own range makes
// an ordinary week look dramatic and a dramatic one ordinary.
const y = (score) => H - PAD_B - ((score - 1) / 98) * (H - PAD_B - PAD_T)

/**
 * How long the pen takes to cross the week, and what follows it.
 *
 * THE ONLY NUMBER THAT SETS THE PACE. Every leg's duration and every dot's delay
 * is a fraction of it — arc length over total — so changing it re-times the whole
 * draw and nothing else moves. `LABEL_LAG_MS` and `TAIL_MS` below are
 * deliberately NOT fractions: they are the beat between a dot and its number, and
 * between the finished line and the training marks, and read the same at any speed.
 *
 * 950 rather than 760, which was a shade brisk on a phone — the eye is still
 * catching up with Monday when Sunday lands. Judged by looking at it; see
 * WeekChartDrawInTest for what about this effect IS worth a test.
 */
const DRAW_MS = 950

/** A value label follows its own dot rather than arriving with it. */
const LABEL_LAG_MS = 60

/** Training marks join once the week is written: context can wait. */
const TAIL_MS = 40

/**
 * The pen's itinerary: distance between each pair of days, and how far along the
 * journey each day therefore sits (0 Monday, 1 Sunday). An unscored leg costs its
 * horizontal distance and draws nothing.
 */
const travel = computed(() => {
  const legs = []

  for (let i = 1; i < props.days.length; i++) {
    const a = props.days[i - 1]
    const b = props.days[i]

    legs.push(
      a.score === null || b.score === null
        ? step.value
        : Math.hypot(x(i) - x(i - 1), y(b.score) - y(a.score))
    )
  }

  // `|| 1` guards a one-day week: NaN delays, invisible chart.
  const total = legs.reduce((sum, leg) => sum + leg, 0) || 1

  const at = [0]
  let covered = 0

  for (const leg of legs) {
    covered += leg
    at.push(covered / total)
  }

  return { legs, total, at }
})

/** Milliseconds into the draw at which the front reaches day `index`. */
function arrival(index) {
  return Math.round(travel.value.at[index] * DRAW_MS)
}

const points = computed(() =>
  props.days
    .map((day, index) => ({ ...day, index, delay: arrival(index) }))
    .filter((day) => day.score !== null)
)

/** Segments between days that are BOTH scored AND adjacent. */
const segments = computed(() => {
  const out = []
  const { legs, total } = travel.value

  for (let i = 1; i < props.days.length; i++) {
    const a = props.days[i - 1]
    const b = props.days[i]

    if (a.score === null || b.score === null) continue

    out.push({
      key: b.date,
      x1: x(i - 1),
      y1: y(a.score),
      x2: x(i),
      y2: y(b.score),
      // Two decimals: in `stroke-dasharray`, 41.83 and 41.8321 differ by
      // nothing, and by a screenful of DOM noise.
      length: Math.round(legs[i - 1] * 100) / 100,
      delay: arrival(i - 1),
      duration: Math.round((legs[i - 1] / total) * DRAW_MS),
    })
  }

  return out
})

/**
 * The animation's inputs, as custom properties rather than `animation-delay`
 * directly: the whole animation, including the state it starts from, has to be
 * able to not exist under `prefers-reduced-motion: reduce` — and a rule can sit
 * behind a media query where an inline style cannot.
 */
function drawStyle(segment) {
  return {
    '--swd-len': String(segment.length),
    '--swd-delay': `${segment.delay}ms`,
    '--swd-dur': `${segment.duration}ms`,
  }
}

function delayStyle(ms) {
  return { '--swd-delay': `${ms}ms` }
}

/** The band-coloured dot: its two paint properties, plus when it lands. */
function dotStyle(point) {
  return {
    fill: bandFill(point.band),
    stroke: 'var(--stress-swatch-ring)',
    '--swd-delay': `${point.delay}ms`,
  }
}

/**
 * Changing this restarts the animation. Vue patches an existing SVG in place on
 * a prop swap, and a CSS animation on an element that was never replaced does not
 * run again — browsing to last week would redraw with no draw-in. Keying the
 * `<svg>` on the week makes a new element, and only when the week changed.
 */
const weekKey = computed(() => props.days.map((day) => day.date).join('/'))

const stripes = computed(() =>
  props.bands.map((band) => ({
    band: band.band,
    fill: bandFill(band.band),
    y: y(band.to),
    height: Math.max(0, y(band.from) - y(band.to)),
  }))
)

const trainingDays = computed(() =>
  props.days
    .map((day, index) => ({ ...day, index }))
    .filter((day) => day.trained)
)

const scored = computed(() => points.value.length)

const average = computed(() =>
  scored.value === 0
    ? null
    : Math.round(points.value.reduce((sum, p) => sum + p.score, 0) / scored.value)
)
</script>

<template>
  <figure class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
    <figcaption class="mb-2 flex items-baseline justify-between">
      <h3 class="text-sm font-semibold">This week</h3>
      <span class="tnum text-[11px] text-stone-500 dark:text-stone-400">
        <!-- The dot's only legend, and only when there is a dot. A tooltip is
             not a legend on a phone. -->
        <template v-if="trainingDays.length > 0">
          <span class="mr-0.5 inline-block size-1.5 rounded-full bg-teal-600 align-middle dark:bg-teal-400" />
          training ·
        </template>
        <template v-if="average !== null">avg {{ average }} over {{ scored }} scored day{{ scored === 1 ? '' : 's' }}</template>
        <template v-else>no scored days</template>
      </span>
    </figcaption>

    <!--
      `:key` restarts the draw-in on a week change; `stress-week-draw` is what
      app.css's animation rules hang off. Neither affects a pixel of the chart.
    -->
    <svg
      :key="weekKey"
      :viewBox="`0 0 ${W} ${H}`" class="stress-week-draw w-full" role="img"
      aria-label="Daily stress score for the selected week"
    >
      <!-- Band fields, very faint: the axis labels a phone has no room for.
           Opacity and fills are custom properties, so the wash is tuned per
           theme in app.css rather than guessed at here. -->
      <g :style="{ opacity: 'var(--stress-field-opacity)' }">
        <rect
          v-for="stripe in stripes"
          :key="stripe.band"
          :x="PAD_L" :y="stripe.y" :width="W - PAD_L - PAD_R" :height="stripe.height"
          :style="{ fill: stripe.fill }"
        />
      </g>

      <g class="fill-stone-400 text-[7px] dark:fill-stone-500">
        <text :x="0" :y="y(99) + 3">99</text>
        <text :x="0" :y="y(50) + 3">50</text>
        <text :x="0" :y="y(1)">1</text>
      </g>

      <g stroke-linecap="round">
        <line
          v-for="segment in segments"
          :key="segment.key"
          :x1="segment.x1" :y1="segment.y1" :x2="segment.x2" :y2="segment.y2"
          class="swd-line stroke-stone-300 dark:stroke-stone-600"
          stroke-width="1.5"
          :style="drawStyle(segment)"
        />
      </g>

      <g>
        <!-- A ring in the surface colour so a point never merges into the band
             field or the segment passing under it. Ring and dot carry the SAME
             delay: one mark in two parts, and half arriving first would read as
             a hole opening in the band field before the score lands in it. -->
        <circle
          v-for="point in points"
          :key="`ring-${point.date}`"
          :cx="x(point.index)" :cy="y(point.score)" r="4.4"
          class="swd-dot fill-white dark:fill-stone-900"
          :style="delayStyle(point.delay)"
        />
        <!-- The same hairline the small swatches get: a 7 px dot of the palest
             band is 1.50:1 on white — a dot you must already know the position
             of. -->
        <circle
          v-for="point in points"
          :key="point.date"
          :cx="x(point.index)" :cy="y(point.score)" r="3.2"
          class="swd-dot"
          :style="dotStyle(point)"
          stroke-width="0.9"
        >
          <title>{{ point.label }} {{ point.dayOfMonth }} · {{ point.score }} · {{ point.bandLabel }} · {{ point.samples }} readings</title>
        </circle>
      </g>

      <!-- The number, always. Colour is the fast channel, not the only one. -->
      <g class="fill-stone-600 text-[7px] font-medium dark:fill-stone-300" text-anchor="middle">
        <text
          v-for="point in points"
          :key="`n-${point.date}`"
          :x="x(point.index)" :y="y(point.score) - 7"
          class="swd-num"
          :style="delayStyle(point.delay + LABEL_LAG_MS)"
        >
          {{ point.score }}
        </text>
      </g>

      <!-- Training marks: one dot per day with a session on it, in the gap
           between the plot and the weekday labels so it crowds neither.

           They fade in together once the line is finished. Landing each one as
           its day is drawn was the other option and it is busier: the eye is
           following a line across the top of the chart and small things start
           blinking underneath it. -->
      <g class="swd-train" :style="delayStyle(DRAW_MS + TAIL_MS)">
        <circle
          v-for="day in trainingDays"
          :key="`t-${day.date}`"
          :cx="x(day.index)" :cy="H - PAD_B + 6" r="1.8"
          class="fill-teal-600 dark:fill-teal-400"
        >
          <title>{{ day.label }} {{ day.dayOfMonth }} · {{ day.trainedLabel }}</title>
        </circle>
      </g>

      <g class="text-[7px]" text-anchor="middle">
        <text
          v-for="(day, index) in days"
          :key="`d-${day.date}`"
          :x="x(index)"
          :y="H - 14"
          :class="day.isToday
            ? 'fill-stone-900 font-semibold dark:fill-stone-100'
            : 'fill-stone-400 dark:fill-stone-500'"
        >
          {{ day.label }}
        </text>
        <!-- A dash where a day has no score, so the gap is stated and not just
             empty space somebody has to notice. -->
        <text
          v-for="(day, index) in days"
          :key="`m-${day.date}`"
          :x="x(index)"
          :y="H - 5"
          class="fill-stone-300 text-[6px] dark:fill-stone-600"
        >
          {{ day.score === null ? (day.isFuture ? '' : '—') : day.dayOfMonth }}
        </text>
      </g>
    </svg>
  </figure>
</template>
