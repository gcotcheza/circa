<script setup>
import { computed, ref } from 'vue'
import { num } from '../lib/format'
import {
  areaPath,
  dayIndex,
  longDate,
  monotonePath,
  niceBounds,
  qualifiedDate,
  splitSegments,
  timeTicks,
  valueTicks,
} from '../lib/chart'

/**
 * The body card: the trend is the story, the readings are the texture.
 *
 * THE CURVE is the smoothed series from App\Services\Reporting\WeightEma (the
 * TDEE estimator fits its slope to the same numbers), interpolated with a
 * MONOTONE cubic: an ordinary spline through 55.1, 55.0, 54.9 dips below 54.9,
 * and inventing a weight is the one thing this chart may not do.
 *
 * THE RINGS are the raw weigh-ins, open and subdued: daily noise is ±1 kg,
 * larger than a month of real change, so a reading is a fact about a morning and
 * the curve is the fact about the season.
 *
 * THE THIN LINE joins them, the same monotone cubic over the RAW values. Without
 * it a fast change leaves the lagging EMA below every recent ring and the card
 * reads as a line that missed its points; drawn, the lag is visibly the distance
 * between two lines. Under the trend, so the eye finds the bold one first.
 *
 * THE X AXIS IS TIME, NOT MEASUREMENT INDEX. The app this replaces spaces its
 * Year view evenly — 23 Aug, 6 Sep, 23 Sep, 13 Apr, 24 Jul — drawing a
 * seven-month silence as wide as a fortnight: tidier, and a lie about the rate
 * of change. A sparse window has empty space in it, and that is the truth.
 *
 * PAST `gapDays` (config, 21) BOTH LINES CARRY ON DASHED, faded and straight,
 * never a solid spline across a stretch nobody measured; the wash fills under
 * the SOLID runs only, and one reading from before the window is admitted so a
 * curve already running does not appear to begin at the left edge.
 * See docs/rationale-frontend.md § "Why the body chart bridges its gaps".
 *
 * THE WHOLE OF IT DRAWS ITSELF, oldest reading to newest and arc-length paced,
 * borrowed from StressWeekChart so two charts in one scroll do not have two
 * personalities. See docs/rationale-frontend.md § "The body chart's draw-in",
 * and the `body-trend-draw` block in app.css for the CSS half.
 */
const props = defineProps({
  /** App\Services\Reporting\BodyTrend::props(). Null until something is weighed. */
  body: { type: Object, default: null },
})

/*
 * viewBox units, not pixels (~1.09 at a 350 px card). Taller than the other two
 * charts on this page: this one is read for a shape rather than for a total.
 */
const W = 320
const H = 168
const PAD_L = 24
const PAD_R = 8
const PLOT_TOP = 14
const PLOT_BOTTOM = 150

const PLOT_W = W - PAD_L - PAD_R
const PLOT_H = PLOT_BOTTOM - PLOT_TOP

/** Above this many readings in view, rings become a wall; they thin to dots. */
const RING_LIMIT = 30

/** And below this many, every reading might carry its own number — see `labelled`. */
const LABEL_LIMIT = 10

/** "56.7" at 7 px is about 16 viewBox units wide; this is that plus a gap. */
const LABEL_ROOM = 18

/**
 * One colour per series, resolved in app.css so both themes live in one place.
 * Violet is this card's identity where energy is orange and steps are blue.
 */
const ACCENTS = {
  weight: 'var(--body-weight)',
  fat: 'var(--body-fat)',
  muscle: 'var(--body-muscle)',
}

const accentOf = (key) => ACCENTS[key] ?? ACCENTS.weight

const rangeKey = ref(props.body?.range ?? 'all')
const seriesKey = ref(props.body?.series[0]?.key ?? 'weight')

/** Held as a DATE, not an index: switching series keeps the day you were looking at. */
const selectedDate = ref(null)

const series = computed(
  () => props.body?.series.find((s) => s.key === seriesKey.value) ?? props.body?.series[0] ?? null
)

const ranges = computed(() => props.body?.ranges ?? [])

const range = computed(
  () => ranges.value.find((r) => r.key === rangeKey.value) ?? ranges.value[ranges.value.length - 1] ?? null
)

const gapDays = computed(() => props.body?.gapDays ?? 21)
const accent = computed(() => accentOf(series.value?.key))
const digits = computed(() => series.value?.digits ?? 1)
const unit = computed(() => series.value?.unit ?? '')

/** Every reading of the selected series, with its day index attached once. */
const all = computed(() => (series.value?.points ?? []).map((p) => ({ ...p, day: dayIndex(p.date) })))

const endDay = computed(() => dayIndex(props.body?.today ?? '1970-01-01'))

const startDay = computed(() => {
  if (range.value?.days) return endDay.value - range.value.days + 1

  // "All" starts at the first reading, plus air so the edge does not slice the ring.
  const first = all.value[0]

  if (!first) return endDay.value - 27

  const span = Math.max(28, endDay.value - first.day)

  return first.day - Math.round(span * 0.03)
})

const spanDays = computed(() => Math.max(1, endDay.value - startDay.value))

const inRange = computed(() =>
  all.value.filter((p) => p.day >= startDay.value && p.day <= endDay.value)
)

/** The one reading before the window, when a line would cross the left edge. */
const entry = computed(() => {
  const first = inRange.value[0]

  if (!first) return null

  const before = all.value.filter((p) => p.day < startDay.value)
  const previous = before[before.length - 1]

  if (!previous) return null

  return first.day - previous.day <= gapDays.value ? previous : null
})

const bounds = computed(() => {
  const values = inRange.value.flatMap((p) => [p.value, p.trend])

  // Both of the entry point's numbers: the thin line enters from its RAW value,
  // a kilo off its trend, and would otherwise flatten against the top of the clip.
  if (entry.value) values.push(entry.value.trend, entry.value.value)

  return niceBounds(values, { minSpan: series.value?.minSpan ?? 2 })
})

const x = (day) => PAD_L + ((day - startDay.value) / spanDays.value) * PLOT_W

const y = (value) =>
  PLOT_BOTTOM - ((value - bounds.value.min) / (bounds.value.max - bounds.value.min || 1)) * PLOT_H

const segments = computed(() =>
  splitSegments(entry.value ? [entry.value, ...inRange.value] : inRange.value, gapDays.value)
)

/**
 * The only number that sets the pace: every run, wipe and ring delay is a
 * fraction of it. 950 is the stress chart's number, deliberately — a card in the
 * same scroll drawing at its own speed reads as a different app.
 */
const DRAW_MS = 950

/** A value label follows its own ring rather than arriving with it. */
const LABEL_LAG_MS = 60

/** The beat between the finished trend and the quiet things behind it. */
const TAIL_MS = 40

/**
 * `Math.hypot` measures the CONTROL POLYLINE; the pen draws the SPLINE through
 * it, which is always longer, and a dash shorter than its own path leaves the
 * far end of a run visible before the pen arrives. Padding UP is safe — a run
 * finishing a few percent early is the error nobody can see. 1.06 is three times
 * the worst overshoot measured: 1.8 % over the real history, 2.0 % synthetic.
 * See docs/rationale-frontend.md § "The body chart's draw-in".
 */
const CURVE_PAD = 1.06

/**
 * The pen's itinerary: one pass over the runs in time order, accumulating the
 * crossing to each run and then its own legs. Distances come back in viewBox
 * units and become milliseconds in `arrival`, so re-timing is one constant.
 */
const journey = computed(() => {
  const runs = []
  const crossings = []

  let covered = 0
  let previous = null

  for (const run of segments.value) {
    const opening = { x: x(run[0].day), y: y(run[0].trend) }

    if (previous !== null) {
      const span = Math.hypot(opening.x - previous.x, opening.y - previous.y)

      crossings.push({ at: covered, length: span })
      covered += span
    }

    const from = covered
    const stops = []
    let last = null

    for (const point of run) {
      const here = { x: x(point.day), y: y(point.trend) }

      if (last !== null) covered += Math.hypot(here.x - last.x, here.y - last.y)

      stops.push({ date: point.date, at: covered })
      last = here
    }

    runs.push({ from, length: covered - from, stops })
    previous = last
  }

  // `|| 1` guards a single-reading window, where every division below is a NaN.
  return { runs, crossings, total: covered || 1 }
})

/** Milliseconds into the draw at which the front reaches `distance`. */
const arrival = (distance) => Math.round((distance / journey.value.total) * DRAW_MS)

/** And how long it spends covering `length` of it. */
const crossing = (length) => Math.round((length / journey.value.total) * DRAW_MS)

/**
 * date -> the moment the front arrives, keyed by date because the readings are
 * drawn from four lists (rings, dots, labels, high/low) agreeing on nothing else.
 */
const reached = computed(() => {
  const out = new Map()

  for (const run of journey.value.runs) {
    for (const stop of run.stops) out.set(stop.date, arrival(stop.at))
  }

  return out
})

const arrivalOf = (date) => reached.value.get(date) ?? 0

/**
 * The measured stretches: one wash and two solid strokes per unbroken run. `raw`
 * and `line` are the same run projected twice, so building both from one
 * `segments` shares the gap rule — the thin line cannot join what the bold one
 * refused to.
 */
const shapes = computed(() =>
  segments.value
    .map((segment, index) => {
      const trend = segment.map((p) => ({ x: x(p.day), y: y(p.trend) }))
      const readings = segment.map((p) => ({ x: x(p.day), y: y(p.value) }))
      const leg = journey.value.runs[index]

      return {
        key: `segment-${index}`,
        line: monotonePath(trend),
        raw: monotonePath(readings),
        area: areaPath(trend, PLOT_BOTTOM),

        // Two decimals: this lands in `stroke-dasharray`, where 41.83 and
        // 41.8321 differ by nothing and by a screenful of DOM noise.
        length: Math.round(leg.length * CURVE_PAD * 100) / 100,
        delay: arrival(leg.from),
        duration: crossing(leg.length),
      }
    })
    .filter((shape) => shape.line !== null)
)

/**
 * And the guessed ones: last reading of a run to the first of the next.
 * Straight, not splined — a curve across an eight-week silence would have a
 * shape, and a shape is a claim about what happened in there.
 */
const bridges = computed(() => {
  const out = []

  for (let i = 1; i < segments.value.length; i++) {
    const previous = segments.value[i - 1]
    const from = previous[previous.length - 1]
    const to = segments.value[i][0]

    const leg = journey.value.crossings[i - 1]

    out.push({
      key: `bridge-${i}`,
      line: monotonePath([
        { x: x(from.day), y: y(from.trend) },
        { x: x(to.day), y: y(to.trend) },
      ]),
      raw: monotonePath([
        { x: x(from.day), y: y(from.value) },
        { x: x(to.day), y: y(to.value) },
      ]),
      // No floor under this one: the wipe IS the pen while it crosses, so any
      // duration but its exact share of the journey changes speed at every gap.
      delay: arrival(leg.at),
      duration: crossing(leg.length),
    })
  }

  return out
})

/** The holes the dashes cross — counted for the footnote. */
const breaks = computed(() => bridges.value.length)

const gridlines = computed(() =>
  valueTicks(bounds.value.min, bounds.value.max).map((value) => ({
    value,
    y: y(value),
    // "55", not "55.0" — a trailing zero on a round axis is noise in a 24 px gutter.
    label: num(value, Number.isInteger(value) ? 0 : 1),
  }))
)

const dateTicks = computed(() =>
  timeTicks(startDay.value, endDay.value).map((tick) => ({ ...tick, x: x(tick.day) }))
)

const dense = computed(() => inRange.value.length > RING_LIMIT)

/** The tightest horizontal spacing between two readings in view, in viewBox units. */
const spacing = computed(() => {
  let tightest = Infinity

  for (let i = 1; i < inRange.value.length; i++) {
    tightest = Math.min(tightest, x(inRange.value[i].day) - x(inRange.value[i - 1].day))
  }

  return tightest
})

/**
 * A reading with no SOLID line through it — two dashes meet here and nothing
 * else does. It keeps its ring even where everything else thins to a dot: as a
 * 1.5-unit dot mid-bridge, the only measured thing in that stretch would be
 * quieter than the guess through it (2024-04-03, 2024-09-14 — typed in, not a
 * trend).
 */
const alone = computed(
  () => new Set(segments.value.filter((run) => run.length === 1).map((run) => run[0].date))
)

const rings = computed(() =>
  dense.value ? inRange.value.filter((p) => alone.value.has(p.date)) : inRange.value
)

const dots = computed(() =>
  dense.value ? inRange.value.filter((p) => !alone.value.has(p.date)) : []
)

/**
 * Whether every reading can carry its own number. A count alone is not the test:
 * the six-month body-fat window holds ten readings, three in April and seven
 * inside five weeks of July — the last seven labels land on each other. What
 * decides it is the TIGHTEST spacing against a four-character label at 7 px.
 */
const labelled = computed(
  () => inRange.value.length > 0 && inRange.value.length <= LABEL_LIMIT && spacing.value >= LABEL_ROOM
)

/** The high and low reading of the window, marked when there is no room to label everything. */
const extremes = computed(() => {
  if (inRange.value.length < 2 || labelled.value) return []

  let low = inRange.value[0]
  let high = inRange.value[0]

  for (const point of inRange.value) {
    if (point.value < low.value) low = point
    if (point.value > high.value) high = point
  }

  if (low.date === high.date) return []

  return [
    { ...high, key: `high-${high.date}`, dy: -6.5 },
    { ...low, key: `low-${low.date}`, dy: 9 },
  ]
})

const latest = computed(() => inRange.value[inRange.value.length - 1] ?? null)

const change = computed(() => {
  const first = inRange.value[0]

  if (!first || !latest.value || first.date === latest.value.date) return null

  return { amount: latest.value.trend - first.trend, since: first.day }
})

/**
 * "+1.9 kg since 13 May", measured on the TREND: two raw weigh-ins differ mostly
 * by two mornings' water. Dated from the first reading IN VIEW, since "over 3
 * months" is untrue when the first six weeks of the window are empty. No colour
 * and no arrow — this app has no opinion about which way a body should go.
 */
const changeText = computed(() => {
  if (!change.value) return null

  const sign = change.value.amount < 0 ? '−' : '+'

  // "since 3 Apr" on the All view means April 2024, and reads as this year.
  const since = qualifiedDate(change.value.since, endDay.value)

  return `${sign}${num(Math.abs(change.value.amount), digits.value)} ${unit.value} since ${since}`
})

/**
 * "Scale export covers through 9 Aug" — the one thing the chart cannot draw:
 * nothing syncs muscle mass, so a line that stopped six weeks ago looks like one
 * that stopped changing. Which series is hand-fed, the day it reaches and whether
 * the lag is worth warning about are the SERVER's calls, in the payload
 * (App\Services\Reporting\BodyTrend); the browser's clock is never consulted,
 * since a phone a day out would otherwise decide the colour.
 */
const exportNote = computed(() => {
  const note = series.value?.export

  if (!note) return null

  return {
    stale: note.stale === true,
    text: `${note.label} covers through ${qualifiedDate(dayIndex(note.through), endDay.value)}`,
    days: note.lagDays,
  }
})

const selected = computed(() => inRange.value.find((p) => p.date === selectedDate.value) ?? null)

/**
 * Tap to read a day, answered in a line UNDER the chart: the target device has
 * no hover, and the stress heatmap uses the same idiom. A tap and not a drag —
 * capturing the pointer to scrub would stop a swipe from the chart scrolling the
 * page it sits in. The x comes off the svg's own bounding box, which the viewBox
 * is scaled to.
 */
function pick(event) {
  const box = event.currentTarget.getBoundingClientRect()

  if (box.width === 0 || inRange.value.length === 0) return

  const local = ((event.clientX - box.left) / box.width) * W
  const day = startDay.value + ((local - PAD_L) / PLOT_W) * spanDays.value

  let best = inRange.value[0]

  for (const point of inRange.value) {
    if (Math.abs(point.day - day) < Math.abs(best.day - day)) best = point
  }

  selectedDate.value = best.date === selectedDate.value ? null : best.date
}

/** Keep a value label inside the card when its point is against an edge. */
function anchor(day) {
  const position = x(day)

  if (position < PAD_L + 14) return 'start'
  if (position > W - PAD_R - 14) return 'end'

  return 'middle'
}

/**
 * The animation's inputs, as custom properties rather than `animation-delay`
 * directly: the animation and its starting state must be able to not exist under
 * `prefers-reduced-motion: reduce`, and only a rule can go behind a media query.
 * Each carries its own paint too — `style` is one attribute, and the accent must
 * arrive through a declaration (see `defs`).
 */
function trendStyle(shape) {
  return {
    stroke: accent.value,
    '--btd-len': String(shape.length),
    '--btd-delay': `${shape.delay}ms`,
    '--btd-dur': `${shape.duration}ms`,
  }
}

function bridgeStyle(bridge) {
  return {
    stroke: accent.value,
    '--btd-delay': `${bridge.delay}ms`,
    '--btd-dur': `${bridge.duration}ms`,
  }
}

/** A ring is born as the front reaches the morning it belongs to. */
function ringStyle(point) {
  return { stroke: accent.value, '--btd-delay': `${arrivalOf(point.date)}ms` }
}

/** The crowded view's dots take their fill from the group; only the beat is theirs. */
function dotStyle(point) {
  return { '--btd-delay': `${arrivalOf(point.date)}ms` }
}

function labelStyle(point) {
  return { '--btd-delay': `${arrivalOf(point.date) + LABEL_LAG_MS}ms` }
}

/**
 * The two quiet layers, arriving together once the trend has finished. Both keep
 * the opacity they were already drawn at: the fade runs from 0 to the element's
 * own computed style, so `--body-wash-opacity` stays the one place it is set.
 */
const tail = computed(() => ({ '--btd-delay': `${DRAW_MS + TAIL_MS}ms` }))

const washStyle = computed(() => ({ opacity: 'var(--body-wash-opacity)', ...tail.value }))

const rawStyle = computed(() => ({ opacity: 'var(--body-raw-opacity)', ...tail.value }))

/**
 * Changing this restarts the draw: Vue patches the existing SVG in place when a
 * chip changes the numbers, and a CSS animation on an element that was never
 * replaced does not run again. Both chips are in the key, the tap selection
 * deliberately not — see docs/rationale-frontend.md § "The body chart's draw-in".
 */
const drawKey = computed(() => `${series.value?.key ?? ''}/${range.value?.key ?? ''}`)
</script>

<template>
  <figure class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
    <template v-if="body && series">
      <figcaption>
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h3 class="text-sm font-semibold">{{ series.label }}</h3>
            <p class="mt-0.5 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
              <template v-if="changeText">{{ changeText }}</template>
              <template v-else-if="latest">one reading in this window</template>
              <template v-else>nothing measured in this window</template>
            </p>
          </div>

          <p v-if="latest" class="shrink-0 text-right leading-none">
            <span class="tnum text-2xl font-semibold">{{ num(latest.value, digits) }}</span>
            <span class="ml-0.5 text-xs text-stone-500 dark:text-stone-400">{{ unit }}</span>
            <span class="tnum mt-1 block text-[11px] text-stone-400 dark:text-stone-500">
              trend {{ num(latest.trend, digits) }}
            </span>
          </p>
        </div>

        <!-- The weigh-in window. Its own control, and not the day buttons at the
             top of the page: see TrendController for why they are two things. -->
        <div
          class="mt-2.5 grid grid-cols-6 gap-0.5 rounded-xl bg-stone-200/70 p-0.5 text-[11px] font-medium dark:bg-stone-800"
        >
          <button
            v-for="option in ranges"
            :key="option.key"
            type="button"
            class="rounded-lg py-1"
            :class="option.key === range?.key ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
            :aria-pressed="option.key === range?.key"
            @click="rangeKey = option.key"
          >
            {{ option.label }}
          </button>
        </div>
      </figcaption>

      <p
        v-if="inRange.length === 0"
        class="py-10 text-center text-sm text-stone-500 dark:text-stone-400"
      >
        Nothing measured in this window.
      </p>

      <!-- `:key` restarts the draw-in when a chip changes the chart, and
           `body-trend-draw` is what the animation rules in app.css hang off.
           Neither affects a pixel of the chart itself. -->
      <svg
        v-else
        :key="drawKey"
        :viewBox="`0 0 ${W} ${H}`"
        class="body-trend-draw mt-1 w-full touch-manipulation"
        role="img"
        :aria-label="`${series.label} over ${range?.title}`"
        @pointerdown="pick"
      >
        <defs>
          <!-- THE WASH IS A FLAT FILL WEARING A MASK, not a gradient of the
               accent: the colour arrives as var(--body-…), which resolves only
               in a CSS DECLARATION, never in a presentation attribute (the trap
               the stress heatmap documents, where an invalid paint renders
               black). `style="stop-color: var(…)"` on a <stop> ought to work by
               that rule, and a gradient stop is not the place to bet a silently
               black card on it. So the fade carries no colour — white to
               transparent, as a luminance mask over a path filled with the
               accent through `style`. userSpaceOnUse, so it spans the PLOT and
               two runs either side of a gap are washed identically. -->
          <linearGradient
            id="body-trend-fade"
            gradientUnits="userSpaceOnUse"
            :x1="0"
            :y1="PLOT_TOP"
            :x2="0"
            :y2="PLOT_BOTTOM"
          >
            <stop offset="0" stop-color="#ffffff" stop-opacity="0.95" />
            <stop offset="1" stop-color="#ffffff" stop-opacity="0" />
          </linearGradient>

          <mask id="body-trend-wash" maskContentUnits="userSpaceOnUse">
            <rect
              :x="PAD_L"
              :y="PLOT_TOP - 4"
              :width="PLOT_W"
              :height="PLOT_H + 4"
              fill="url(#body-trend-fade)"
            />
          </mask>

          <clipPath id="body-trend-plot">
            <rect :x="PAD_L" :y="PLOT_TOP - 4" :width="PLOT_W" :height="PLOT_H + 4" />
          </clipPath>
        </defs>

        <!-- Few, and very light: three of them orient the eye, six are a cage. -->
        <g class="stroke-stone-200 dark:stroke-stone-800">
          <line
            v-for="line in gridlines"
            :key="`grid-${line.value}`"
            :x1="PAD_L"
            :x2="W - PAD_R"
            :y1="line.y"
            :y2="line.y"
            stroke-width="0.6"
          />
        </g>

        <g class="fill-stone-400 text-[7px] dark:fill-stone-500">
          <text v-for="line in gridlines" :key="`v-${line.value}`" :x="0" :y="line.y + 2.4">
            {{ line.label }}
          </text>
        </g>

        <!-- FOUR LAYERS, BOTTOM TO TOP: wash, thin raw line, bold trend, then
             the rings outside this group — the order of the claims, from mood to
             fact. Each line is drawn twice, solid over the measured runs and
             dashed over the bridges, both out of the same `segments`, so no
             arrangement of the data can put a solid stroke across a gap. -->
        <g clip-path="url(#body-trend-plot)">
          <!-- Under the SOLID runs only; the header says why. -->
          <g class="btd-tail" mask="url(#body-trend-wash)" :style="washStyle">
            <path
              v-for="shape in shapes"
              :key="`area-${shape.key}`"
              :d="shape.area"
              :style="{ fill: accent }"
            />
          </g>

          <!-- The readings joined. Group opacity, so the dashed bridges below
               come out at raw × bridge and stay the faintest thing drawn; it
               fades in as one layer, with no pen of its own. -->
          <g class="btd-tail" :style="rawStyle" fill="none" stroke-linecap="round">
            <path
              v-for="shape in shapes"
              :key="`raw-${shape.key}`"
              :d="shape.raw"
              :style="{ stroke: accent }"
              stroke-width="1.1"
              stroke-linejoin="round"
            />

            <path
              v-for="bridge in bridges"
              :key="`raw-${bridge.key}`"
              :d="bridge.raw"
              :style="{ stroke: accent }"
              stroke-width="1.1"
              stroke-dasharray="2 2"
              opacity="0.5"
            />
          </g>

          <!-- The trend, and the only thing here actually drawn: each run is its
               own pen stroke, timed to its share of the journey. A run that
               begins before the window starts left of the plot and is clipped,
               so the line enters the frame already moving — as it was. -->
          <g fill="none" stroke-linecap="round">
            <path
              v-for="shape in shapes"
              :key="`line-${shape.key}`"
              :d="shape.line"
              class="btd-line"
              :style="trendStyle(shape)"
              stroke-width="2"
              stroke-linejoin="round"
            />

            <!-- Dashed AND faded: a dash pattern alone lands at the same ink as
                 the solid stroke on a phone, which would make the bridge the
                 most eye-catching thing on a sparse window — backwards for the
                 one part nobody measured. Wiped, not drawn by a dash offset. -->
            <path
              v-for="bridge in bridges"
              :key="`line-${bridge.key}`"
              :d="bridge.line"
              class="btd-bridge"
              :style="bridgeStyle(bridge)"
              stroke-width="2"
              stroke-dasharray="3 3"
              opacity="0.5"
            />
          </g>
        </g>

        <!-- The tapped day, before the rings so the ring stays on top of it. -->
        <line
          v-if="selected"
          :x1="x(selected.day)"
          :x2="x(selected.day)"
          :y1="PLOT_TOP"
          :y2="PLOT_BOTTOM"
          class="stroke-stone-300 dark:stroke-stone-600"
          stroke-width="0.6"
          stroke-dasharray="2 2"
        />

        <!-- Open rings while they can breathe, small dots once rings would be a
             wall — and a ring regardless for a reading that stands alone. -->
        <g v-if="dots.length > 0" :style="{ fill: accent }" opacity="0.55">
          <circle
            v-for="point in dots"
            :key="`dot-${point.date}`"
            :cx="x(point.day)"
            :cy="y(point.value)"
            r="1.5"
            class="btd-ring"
            :style="dotStyle(point)"
          />
        </g>

        <circle
          v-for="point in rings"
          :key="`ring-${point.date}`"
          :cx="x(point.day)"
          :cy="y(point.value)"
          r="2.9"
          class="btd-ring fill-white dark:fill-stone-900"
          :style="ringStyle(point)"
          stroke-width="1.3"
        />

        <!-- Every reading labelled while they fit; otherwise only the high and
             the low, which are the two a glance is looking for anyway. -->
        <g class="fill-stone-500 text-[7px] font-medium dark:fill-stone-400">
          <template v-if="labelled">
            <text
              v-for="point in inRange"
              :key="`n-${point.date}`"
              :x="x(point.day)"
              :y="y(point.value) - 6.5"
              :text-anchor="anchor(point.day)"
              class="btd-num"
              :style="labelStyle(point)"
            >
              {{ num(point.value, digits) }}
            </text>
          </template>
          <template v-else>
            <text
              v-for="point in extremes"
              :key="point.key"
              :x="x(point.day)"
              :y="y(point.value) + point.dy"
              :text-anchor="anchor(point.day)"
              class="btd-num"
              :style="labelStyle(point)"
            >
              {{ num(point.value, digits) }}
            </text>
          </template>
        </g>

        <!-- The tapped reading, filled, so the eye finds it among the rings. -->
        <circle
          v-if="selected"
          :cx="x(selected.day)"
          :cy="y(selected.value)"
          r="3"
          :style="{ fill: accent }"
        />

        <g class="fill-stone-400 text-[7px] dark:fill-stone-500" text-anchor="middle">
          <text v-for="tick in dateTicks" :key="`t-${tick.day}`" :x="tick.x" :y="H - 4">
            {{ tick.label }}
          </text>
        </g>
      </svg>

      <!-- What the scale measures on the same trip, one at a time: two axes on a
           350 px card is a puzzle, and the question is "how is this one going". -->
      <div
        v-if="body.series.length > 1"
        class="mt-2.5 inline-flex rounded-lg bg-stone-200/70 p-0.5 text-[11px] font-medium dark:bg-stone-800"
      >
        <button
          v-for="option in body.series"
          :key="option.key"
          type="button"
          class="flex items-center gap-1.5 rounded-md px-2.5 py-1"
          :class="option.key === series.key ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
          :aria-pressed="option.key === series.key"
          @click="seriesKey = option.key"
        >
          <span
            class="inline-block h-1.5 w-1.5 rounded-full"
            :style="{ backgroundColor: accentOf(option.key) }"
          />
          {{ option.label }}
        </button>
      </div>

      <!-- Grey it is just the date to export from next time; amber it says the
           line now misses more than a routine gap. Same triangle as the
           dashboard's last-sync line, for the same silent failure. -->
      <p
        v-if="exportNote"
        class="mt-2 flex items-start gap-1.5 text-[11px] leading-relaxed"
        :class="exportNote.stale ? 'text-amber-700 dark:text-amber-400' : 'text-stone-500 dark:text-stone-400'"
      >
        <svg
          v-if="exportNote.stale"
          class="mt-0.5 size-3.5 shrink-0"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
        >
          <path d="M12 4.5 2.5 20h19L12 4.5Z" stroke-linejoin="round" />
          <path d="M12 10v4M12 17h.01" stroke-linecap="round" />
        </svg>

        <span>
          {{ exportNote.text }}<template v-if="exportNote.stale">
            — {{ exportNote.days }} day{{ exportNote.days === 1 ? '' : 's' }} behind the rest of
            this card; export again from there</template>.
        </span>
      </p>

      <!-- The tooltip a phone can actually use. -->
      <p
        class="tnum mt-2 min-h-[1.5rem] text-[11px] leading-relaxed"
        :class="selected ? 'text-stone-700 dark:text-stone-200' : 'text-stone-400 dark:text-stone-500'"
      >
        <template v-if="selected">
          <span class="font-semibold">{{ longDate(selected.day) }}</span>
          — {{ num(selected.value, digits) }} {{ unit }} · trend
          {{ num(selected.trend, digits) }}
        </template>
        <template v-else>Tap the chart to read a day.</template>
      </p>

      <p class="text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        {{ inRange.length }} reading{{ inRange.length === 1 ? '' : 's' }} in this window. The rings
        are what the scale said, the thin line joins them, the bold one is the trend — and both
        go dashed across a stretch with nothing weighed in it.
      </p>

      <p
        v-if="breaks > 0"
        class="mt-1 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400"
      >
        {{ breaks }} such stretch{{ breaks === 1 ? '' : 'es' }} here, longer than {{ gapDays }} days
        — crossed, not measured.
      </p>
    </template>

    <template v-else>
      <figcaption class="mb-2">
        <h3 class="text-sm font-semibold">Weight</h3>
      </figcaption>
      <p class="py-6 text-center text-sm text-stone-500 dark:text-stone-400">
        Nothing weighed yet.
      </p>
    </template>
  </figure>
</template>
