/**
 * The maths behind the body card's curve — kept out of the component so it can
 * be tested without a browser. Every x coordinate is a DAY INDEX rather than a
 * millisecond stamp: whole days since 1970-01-01, from the Y-M-D parts of an ISO
 * date read as UTC. Gaps are then exact integer subtraction — in milliseconds, a
 * gap across the March clock change is 20.958333 days, and 21 is a threshold
 * this app draws a line break at — and nothing depends on the machine's
 * timezone, so a test in UTC and a phone in Amsterdam agree which day a point is.
 */

import { MONTHS, WEEKDAY_NAMES } from './dates.js'

const MS_PER_DAY = 86_400_000

/** '2026-08-09' -> 20675. */
export function dayIndex(iso) {
  const [y, m, d] = String(iso).split('-').map(Number)

  return Math.round(Date.UTC(y, m - 1, d) / MS_PER_DAY)
}

/** The inverse, as a Date whose UTC parts are the calendar date. */
export function fromDayIndex(day) {
  return new Date(day * MS_PER_DAY)
}

/** '9 Aug' */
export function shortDate(day) {
  const date = fromDayIndex(day)

  return `${date.getUTCDate()} ${MONTHS[date.getUTCMonth()]}`
}

/** 'Sun 9 Aug 2026' — the tap readout, where the weekday is worth the room. */
export function longDate(day) {
  const date = fromDayIndex(day)

  return `${WEEKDAY_NAMES[date.getUTCDay()]} ${date.getUTCDate()} ${MONTHS[date.getUTCMonth()]} ${date.getUTCFullYear()}`
}

export function yearOf(day) {
  return fromDayIndex(day).getUTCFullYear()
}

/**
 * '9 Aug' this year, '9 Aug 2024' any other year. On the All view a caption's
 * date two years back reads as this August, making the sentence around it two
 * years wrong. Qualified against the CARD's end, not the browser's clock, so the
 * date and the chart's right edge agree.
 */
export function qualifiedDate(day, endDay) {
  return yearOf(day) === yearOf(endDay) ? shortDate(day) : `${shortDate(day)} ${yearOf(day)}`
}

/**
 * 'Aug', or 'Aug ’26' once one axis holds two Augusts. The apostrophe is
 * load-bearing: this app writes dates as "9 Aug" everywhere else, so a bare
 * "Aug 25" reads as the twenty-fifth of August — indistinguishable formats
 * meaning different years.
 */
export function monthLabel(day, withYear = false) {
  const date = fromDayIndex(day)
  const month = MONTHS[date.getUTCMonth()]

  return withYear ? `${month} \u{2019}${String(date.getUTCFullYear()).slice(2)}` : month
}

/**
 * Split a series wherever more days passed than a trend can honestly span: the
 * "gaps are gaps" rule. Points come in oldest first carrying a `day`, runs out.
 *
 * WHAT THE CARD DOES WITH THEM CHANGED IN 2026-08. A hole used to be a hole in
 * the line, and a run of one a ring with nothing attached; the owner overruled
 * that — the readings should be joined. So a run is the SOLID part of one
 * continuous line and the space between two runs a dashed bridge: same split,
 * same threshold, and a run of one is a point two dashes meet at.
 */
export function splitSegments(points, maxGapDays) {
  const out = []
  let run = []

  for (const point of points) {
    if (run.length > 0 && point.day - run[run.length - 1].day > maxGapDays) {
      out.push(run)
      run = []
    }

    run.push(point)
  }

  if (run.length > 0) out.push(run)

  return out
}

const round = (n) => Math.round(n * 100) / 100

/**
 * Tangents for a monotone cubic through {x, y}, the Fritsch-Carlson shape used
 * by d3's curveMonotoneX. NOT a plain cubic spline or `curveCatmullRom`: both
 * overshoot — feed them 55.1, 55.0, 54.9 and the curve dips below 54.9, drawing
 * a weight the user never had in the one chart whose entire job is not doing
 * that. A monotone spline is flat-by-construction at every local extreme and
 * never leaves the interval its two endpoints bracket.
 */
export function monotoneTangents(points) {
  const n = points.length

  if (n < 2) return [0]

  const h = []
  const s = []

  for (let i = 0; i < n - 1; i++) {
    h[i] = points[i + 1].x - points[i].x
    s[i] = h[i] === 0 ? 0 : (points[i + 1].y - points[i].y) / h[i]
  }

  if (n === 2) return [s[0], s[0]]

  const m = new Array(n)

  for (let i = 1; i < n - 1; i++) {
    const s0 = s[i - 1]
    const s1 = s[i]

    // A sign change is a local peak or trough: flat there, so the curve cannot
    // sail past the reading it turned around on.
    if (s0 * s1 <= 0) {
      m[i] = 0
      continue
    }

    const p = (s0 * h[i] + s1 * h[i - 1]) / (h[i - 1] + h[i])

    m[i] = Math.sign(s0) * Math.min(Math.abs(s0), Math.abs(s1), Math.abs(p) / 2) * 2
  }

  // One neighbouring slope at the ends: the one-sided form, clamped to the same
  // no-overshoot rule.
  m[0] = endpointTangent(s[0], m[1])
  m[n - 1] = endpointTangent(s[n - 2], m[n - 2])

  return m
}

function endpointTangent(slope, neighbour) {
  const t = (3 * slope - neighbour) / 2

  if (slope === 0 || Math.sign(t) !== Math.sign(slope)) return 0

  return Math.abs(t) > 3 * Math.abs(slope) ? 3 * slope : t
}

/** The smooth trend line. One point draws nothing — a dot is not a trend. */
export function monotonePath(points) {
  if (points.length < 2) return null

  const first = points[0]

  if (points.length === 2) {
    return `M ${round(first.x)} ${round(first.y)} L ${round(points[1].x)} ${round(points[1].y)}`
  }

  const m = monotoneTangents(points)

  let d = `M ${round(first.x)} ${round(first.y)}`

  for (let i = 0; i < points.length - 1; i++) {
    const a = points[i]
    const b = points[i + 1]
    const third = (b.x - a.x) / 3

    d += ` C ${round(a.x + third)} ${round(a.y + m[i] * third)}`
    d += ` ${round(b.x - third)} ${round(b.y - m[i + 1] * third)}`
    d += ` ${round(b.x)} ${round(b.y)}`
  }

  return d
}

/** The same curve, closed down to the floor — the gradient wash under it. */
export function areaPath(points, floorY) {
  const line = monotonePath(points)

  if (line === null) return null

  const first = points[0]
  const last = points[points.length - 1]

  return `${line} L ${round(last.x)} ${round(floorY)} L ${round(first.x)} ${round(floorY)} Z`
}

/**
 * A few round numbers inside [min, max] — 54, 55, 56 rather than 54.13, 55.27.
 * Deliberately sparse: this axis is 300 px wide on a phone and the gridlines are
 * a hairline of stone-200; three orient the eye, six are a cage.
 */
export function valueTicks(min, max, target = 3) {
  const span = max - min

  if (!(span > 0)) return [min]

  const rough = span / Math.max(1, target)
  const magnitude = 10 ** Math.floor(Math.log10(rough))
  const normalised = rough / magnitude

  // 1, 2, 5, 10, switching at the geometric middles (√2, √10, √50) so the step
  // is the nearest nice number rather than the next one up: rounding up turns a
  // 3.6 kg axis into two gridlines, too few to read a height off.
  const step =
    magnitude *
    (normalised >= Math.SQRT2 * 5 ? 10 : normalised >= Math.sqrt(10) ? 5 : normalised >= Math.SQRT2 ? 2 : 1)

  const ticks = []

  for (let i = Math.ceil(min / step); i * step <= max + step * 1e-9; i++) {
    ticks.push(round(i * step))
  }

  return ticks
}

/**
 * Dates along the bottom, at a density the range can carry. A week or so: every
 * day, since sevens over a seven-day window produce one label on the right-hand
 * edge, and a week of daily weigh-ins is all about which morning was which —
 * seven labels sit 48 viewBox units apart, more than twice the width of "9 Aug",
 * so they fit unthinned. Up to six weeks: weekly, counted BACK from the right so
 * the last label ends the window rather than whatever Monday fell near it.
 * Beyond that: month boundaries thinned to fit, again anchored on the most
 * recent, carrying the year past a year — "Aug" twice on one axis is not an axis.
 */
export function timeTicks(startDay, endDay, maxTicks = 5) {
  const span = endDay - startDay

  if (span <= 0) return [{ day: endDay, label: shortDate(endDay) }]

  if (span <= 10) {
    const out = []

    for (let day = startDay; day <= endDay; day++) {
      out.push({ day, label: shortDate(day) })
    }

    return out
  }

  if (span <= 45) {
    const out = []

    for (let day = endDay; day >= startDay; day -= 7) {
      out.unshift({ day, label: shortDate(day) })
    }

    return out
  }

  const months = []
  const from = fromDayIndex(startDay)

  // `year` never moves: `month` runs past 11 and Date.UTC rolls the overflow
  // into the following year, which is why only one of the two is a `let`.
  const year = from.getUTCFullYear()
  let month = from.getUTCMonth() + (from.getUTCDate() === 1 ? 0 : 1)

  for (;;) {
    const day = Math.round(Date.UTC(year, month, 1) / MS_PER_DAY)

    if (day > endDay) break

    months.push(day)
    month += 1
  }

  if (months.length === 0) return [{ day: endDay, label: shortDate(endDay) }]

  const stride = Math.max(1, Math.ceil(months.length / maxTicks))
  const withYear = span > 300
  const picked = []

  for (let i = months.length - 1; i >= 0; i -= stride) {
    picked.unshift({ day: months[i], label: monthLabel(months[i], withYear) })
  }

  return picked
}

/**
 * The value axis window: the data, padded, but never tighter than `minSpan`.
 * Never zero-based, because a 2 kg change on a 0-60 kg axis is invisible and
 * making a slow change legible is the entire point of this chart. The floor on
 * the span is the other half of that bargain: without it, a fortnight in which
 * weight moved 200 g would be auto-scaled into a mountain range.
 */
export function niceBounds(values, { minSpan = 0, pad = 0.15 } = {}) {
  const finite = values.filter((v) => Number.isFinite(v))

  if (finite.length === 0) return { min: 0, max: 1 }

  let min = Math.min(...finite)
  let max = Math.max(...finite)

  if (max - min < minSpan) {
    const middle = (min + max) / 2

    min = middle - minSpan / 2
    max = middle + minSpan / 2
  }

  const margin = (max - min) * pad

  return { min: min - margin, max: max + margin }
}
