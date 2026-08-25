<script setup>
import { computed, ref, watch } from 'vue'
import { bandFill, bandLabel, WEEKDAYS } from '../lib/stress'

/**
 * The hour-of-the-week grid — 168 cells, in two modes.
 *
 * TWO GRIDS, TWO QUESTIONS. THIS WEEK (default) is seven real dates, each cell
 * the reading that actually arrived at that hour; it follows the week arrows and
 * changes as you browse, which is why it leads. TYPICAL WEEK pools the last
 * ninety days into one week, each cell a median over roughly thirteen
 * occurrences of that hour — a habit, not an event, so it does not move at all
 * when you browse.
 *
 * Same code, same four bands, but NOT the same yardstick, and the card says so.
 * A typical-week cell is a median of many readings scored against day-to-day
 * spread; a this-week cell is ONE reading scored against hour-to-hour spread,
 * about twice as wide. Scoring single readings against the daily spread would
 * saturate almost every cell and imply a day's verdict from one hour — the exact
 * dishonesty this feature exists to avoid.
 *
 * HOURS DOWN, DAYS ACROSS. On the target 350 px phone seven columns are ~42 px
 * each — tappable, with room for "Mon" — where twenty-four would be 13 px and
 * could not label their own axis. Every hour therefore carries a label, with 00,
 * 06, 12 and 18 darker as anchors; the card is tall and scrolls, the honest cost
 * of showing all 24 hours rather than six ticks. Do not transpose it back.
 * See docs/rationale-frontend.md § "Hours down the grid, days across"
 *
 * NOTHING IS PAINTED THAT WAS NOT MEASURED. The commercial app being replaced
 * fills all 168 cells every week by interpolating across the roughly one hour in
 * six it has no reading for: a smooth gradient over the four hours the Watch
 * spent on a charger, carrying the same visual authority as a measurement. Four
 * states here, drawn apart on purpose — BANDED (a reading with a comparable hour
 * behind it), UNRANKED (measured, no established shape for that hour, so neutral
 * grey), MISSING (a quieter grey; the Watch was off, and these are counted under
 * the grid), NOT YET (nothing at all — an hour that has not happened is no gap
 * in the data, and stays out of the coverage denominator too).
 *
 * MISSING was a bare outline on a white card, which made the hours nothing was
 * measured in the highest-contrast objects in the grid: a week with holes
 * presented its HOLES first. A grey quieter than every band inverts that without
 * inventing anything, and empty now means "has not happened" and nothing else.
 *
 * TAPPING, NOT HOVERING. No hover on the target device, so the tooltip is a line
 * UNDER the grid that a tap fills in. It is also the accessibility relief the
 * palette needs: the Pay-attention/Great pair sits in the colour-vision warning
 * band, and this line always states the band in words and the value as a number.
 */
const props = defineProps({
  /** The viewed week, hour by hour. Null before there is enough history. */
  weekGrid: { type: Object, default: null },
  /** Ninety days pooled into one week. Null on its own, separate gate. */
  heatmap: { type: Object, default: null },
  weekLabel: { type: String, default: '' },
})

/**
 * viewBox units, and the svg is `w-full`, so these are a RATIO not pixels: at a
 * 350 px card the scale is 1.75 — a 42 x 20 px cell, ~12 px labels. Rows are
 * shorter than they are wide because 24 square cells would be a 1 000 px column
 * nobody can see the ends of at once.
 */
const CELL_W = 24
const CELL_H = 11.5
const GAP = 1
const LABEL_W = 32
const TOP = 12

const W = LABEL_W + 7 * CELL_W
const H = TOP + 24 * CELL_H

const ANCHOR_HOURS = [0, 6, 12, 18]

const modes = computed(() => {
  const out = []

  if (props.weekGrid) out.push({ key: 'week', label: 'This week' })
  if (props.heatmap) out.push({ key: 'typical', label: 'Typical week' })

  return out
})

const mode = ref(props.weekGrid ? 'week' : 'typical')

// Browsing past the available history must not strand the card on a vanished mode.
watch(modes, (available) => {
  if (!available.some((m) => m.key === mode.value)) {
    mode.value = available[0]?.key ?? 'week'
  }
})

const source = computed(() => (mode.value === 'week' ? props.weekGrid : props.heatmap))

const selected = ref(null)

// A cell's identity survives a mode switch only by accident, so drop the selection.
watch(mode, () => {
  selected.value = null
})

const columns = computed(() =>
  WEEKDAYS.map((label, index) => ({
    label,
    weekday: index + 1,
    x: LABEL_W + index * CELL_W,
  }))
)

const rows = computed(() => {
  const cells = source.value?.cells ?? []

  return Array.from({ length: 24 }, (_, hour) => ({
    hour,
    y: TOP + hour * CELL_H,
    label: `${String(hour).padStart(2, '0')}:00`,
    anchor: ANCHOR_HOURS.includes(hour),
    cells: cells.filter((cell) => cell.hour === hour),
  }))
})

const x = (weekday) => LABEL_W + (weekday - 1) * CELL_W

function select(cell) {
  if (cell.future) return

  selected.value = isSelected(cell) ? null : cell
}

function isSelected(cell) {
  return (
    selected.value !== null &&
    selected.value.weekday === cell.weekday &&
    selected.value.hour === cell.hour
  )
}

/** Always a colour: every hour that HAS happened is painted one of the three states. */
function cellFill(cell) {
  if (cell.band) return bandFill(cell.band)

  /*
   * A this-week reading with no band was MEASURED but cannot be ranked: neutral
   * grey, because a pale band colour would read as "mildly bad" for something
   * carrying no verdict at all. A typical-week cell under `minCellSamples`
   * differs in kind but not in amount known — none — so it takes the missing
   * grey, alongside the hours the Watch simply did not see.
   */
  if (mode.value === 'week' && cell.samples > 0) return 'var(--stress-unranked)'

  return 'var(--stress-missing)'
}

/** The always-available text answer, for the native tooltip and for screen readers. */
function cellTitle(cell) {
  const when = `${WEEKDAYS[cell.weekday - 1]} ${hourLabel(cell.hour)}`

  if (mode.value === 'typical') {
    return cell.score === null
      ? `${when} · ${cell.samples} readings, not enough`
      : `${when} · ${cell.score} ${bandLabel(cell.band)}`
  }

  if (cell.samples === 0) return `${when} · no reading`

  return cell.band === null
    ? `${when} · ${cell.hrvMs} ms, hour not comparable yet`
    : `${when} · ${cell.hrvMs} ms, ${bandLabel(cell.band)} for that hour`
}

const hourLabel = (hour) => `${String(hour).padStart(2, '0')}:00`

/** Where a reading sits against this person's own middle half for that hour. */
function position(cell) {
  if (cell.typicalLowMs === null || cell.hrvMs === null) return null

  if (cell.hrvMs < cell.typicalLowMs) return 'below'
  if (cell.hrvMs > cell.typicalHighMs) return 'above'

  return 'inside'
}

const detail = computed(() => {
  const cell = selected.value

  if (!cell) return null

  const when = `${WEEKDAYS[cell.weekday - 1]} ${hourLabel(cell.hour)}`

  if (mode.value === 'typical') {
    if (cell.score === null) {
      return {
        when,
        text:
          cell.samples === 0
            ? 'no readings in this window'
            : `only ${cell.samples} reading${cell.samples === 1 ? '' : 's'} — below the ${props.heatmap.minCellSamples} needed`,
      }
    }

    return {
      when,
      text: `${cell.score} · ${bandLabel(cell.band)} · ${cell.hrvMs} ms · ${cell.samples} readings`,
    }
  }

  if (cell.samples === 0) {
    return { when, text: cell.future ? 'has not happened yet' : 'no reading — the Watch was off' }
  }

  if (cell.band === null) {
    return { when, text: `${cell.hrvMs} ms — no established shape for this hour yet` }
  }

  return {
    when,
    text:
      `${cell.hrvMs} ms — ${position(cell)} your usual ` +
      `${cell.typicalLowMs}–${cell.typicalHighMs} ms at that hour · ${bandLabel(cell.band)}`,
  }
})

function shortDate(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

/** Counted over hours that HAVE happened: "40 of 168" on a Monday is a calendar fact. */
const coverage = computed(() => {
  const grid = props.weekGrid

  if (!grid) return null

  const denominator = grid.elapsedCells < grid.totalCells ? grid.elapsedCells : grid.totalCells
  const of = grid.elapsedCells < grid.totalCells ? 'hours so far this week' : 'hours of this week'

  return `${grid.filledCells} of ${denominator} ${of} have a reading.`
})

const typicalEmptyCells = computed(() =>
  props.heatmap ? props.heatmap.totalCells - props.heatmap.filledCells : 0
)
</script>

<template>
  <figure class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
    <figcaption class="mb-2">
      <div class="flex items-baseline justify-between gap-2">
        <h3 class="text-sm font-semibold">Hour of the week</h3>
        <span class="tnum shrink-0 text-[11px] text-stone-500 dark:text-stone-400">
          <template v-if="mode === 'week'">{{ weekLabel }}</template>
          <template v-else>{{ shortDate(heatmap.from) }} – {{ shortDate(heatmap.to) }}</template>
        </span>
      </div>

      <!-- The two questions, named. -->
      <div
        v-if="modes.length > 1"
        class="mt-2 inline-flex rounded-lg bg-stone-200/70 p-0.5 text-[11px] font-medium dark:bg-stone-800"
      >
        <button
          v-for="option in modes"
          :key="option.key"
          type="button"
          class="rounded-md px-2.5 py-1"
          :class="option.key === mode ? 'bg-white shadow-sm dark:bg-stone-950' : 'text-stone-500'"
          @click="mode = option.key"
        >
          {{ option.label }}
        </button>
      </div>

      <p class="mt-1.5 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        <template v-if="mode === 'week'">
          Every hour of the week you are looking at, compared with what that hour of the day is
          normally like for you. A grey hour is one the Watch said nothing about — nothing is
          filled in for it.
        </template>
        <template v-else>
          The last {{ heatmap.days }} days folded into one week. Each cell compares that hour with
          what that hour of the day is normally like for you — so this is the shape of your week,
          not of your body clock.
        </template>
      </p>
    </figcaption>

    <svg
      :viewBox="`0 0 ${W} ${H}`"
      class="w-full touch-manipulation"
      role="img"
      aria-label="Stress by day of the week across and hour of the day down"
    >
      <g class="fill-stone-500 text-[7px] font-medium dark:fill-stone-400" text-anchor="middle">
        <text
          v-for="column in columns"
          :key="`h-${column.weekday}`"
          :x="column.x + (CELL_W - GAP) / 2"
          :y="TOP - 3.5"
        >
          {{ column.label }}
        </text>
      </g>

      <g v-for="row in rows" :key="row.hour">
        <!-- 00, 06, 12 and 18 darker: something to count from without reading all 24. -->
        <text
          :x="LABEL_W - 4"
          :y="row.y + CELL_H / 2 + 2.4"
          text-anchor="end"
          class="tnum text-[7px]"
          :class="row.anchor
            ? 'fill-stone-500 dark:fill-stone-300'
            : 'fill-stone-300 dark:fill-stone-600'"
        >
          {{ row.label }}
        </text>

        <g v-for="cell in row.cells" :key="`${cell.weekday}-${cell.hour}`">
          <!-- An hour that has not happened gets no cell and no tap target: the
               only state left that draws nothing. The fill goes through `style`
               and never the `fill` ATTRIBUTE — a presentation attribute is not a
               CSS declaration, so a var(--stress-…) there is an invalid paint,
               i.e. black. -->
          <rect
            v-if="!cell.future"
            :x="x(cell.weekday)"
            :y="row.y"
            :width="CELL_W - GAP"
            :height="CELL_H - GAP"
            rx="1.5"
            :style="{ fill: cellFill(cell) }"
            class="cursor-pointer"
            @click="select(cell)"
          >
            <title>{{ cellTitle(cell) }}</title>
          </rect>

          <!-- Selection ring in the text colour: reads on any fill, and on an empty cell. -->
          <rect
            v-if="isSelected(cell)"
            :x="x(cell.weekday) - 0.75"
            :y="row.y - 0.75"
            :width="CELL_W - GAP + 1.5"
            :height="CELL_H - GAP + 1.5"
            rx="2"
            fill="none"
            class="stroke-stone-900 dark:stroke-stone-100"
            stroke-width="1"
            pointer-events="none"
          />
        </g>
      </g>
    </svg>

    <p
      class="tnum mt-2 min-h-[1.5rem] text-[11px] leading-relaxed"
      :class="detail ? 'text-stone-700 dark:text-stone-200' : 'text-stone-400 dark:text-stone-500'"
    >
      <template v-if="detail">
        <span class="font-semibold">{{ detail.when }}</span> — {{ detail.text }}
      </template>
      <template v-else>Tap a cell to see what is behind it.</template>
    </p>

    <template v-if="mode === 'week'">
      <p class="text-[11px] leading-relaxed text-stone-500 dark:text-stone-400">
        {{ coverage }} The pale grey hours are unmeasured, not calm — the gaps are left as gaps
        rather than smoothed over.
      </p>
      <p
        v-if="weekGrid.unrankedCells > 0"
        class="mt-1 text-[11px] leading-relaxed text-stone-500 dark:text-stone-400"
      >
        {{ weekGrid.unrankedCells }} hour{{ weekGrid.unrankedCells === 1 ? '' : 's' }} had a reading
        but no established shape yet, and {{ weekGrid.unrankedCells === 1 ? 'is' : 'are' }} drawn
        in the darker grey.
      </p>
      <p class="mt-1 text-[10px] leading-relaxed text-stone-400 dark:text-stone-500">
        Each cell is a SINGLE reading against your own usual for that hour, where one standard
        deviation is about {{ weekGrid.spreadPercent }}%. Single readings are rough by nature: an
        amber hour is an unusual hour, never a verdict on the day. The daily score above is a
        median of a whole day against sixty of them.
      </p>
    </template>

    <p
      v-else-if="typicalEmptyCells > 0"
      class="text-[11px] leading-relaxed text-stone-500 dark:text-stone-400"
    >
      {{ typicalEmptyCells }} of {{ heatmap.totalCells }} hours are grey — fewer than
      {{ heatmap.minCellSamples }} readings in {{ heatmap.days }} days. Grey means unmeasured, not
      calm.
    </p>
  </figure>
</template>
