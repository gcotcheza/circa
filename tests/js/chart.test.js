import assert from 'node:assert/strict'
import test from 'node:test'

import {
  areaPath,
  dayIndex,
  longDate,
  monotonePath,
  monotoneTangents,
  niceBounds,
  qualifiedDate,
  shortDate,
  splitSegments,
  timeTicks,
  valueTicks,
} from '../../resources/js/lib/chart.js'

/**
 * resources/js/lib/chart.js — the claims the body card makes with geometry, none
 * of them checkable by reading the page: the curve never shows an unmeasured
 * value, the thin line joins exactly the readings the rings mark, and neither
 * draws a SOLID stroke across a stretch nobody weighed in. All decided here by
 * arithmetic with no DOM, leaving the component only the stroke for each piece —
 * the one part checked by looking. Run by `npm test` and by scripts/ci.sh.
 */

const at = (iso) => dayIndex(iso)

test('a date becomes a whole number of days, in nobody’s timezone', () => {
  assert.equal(at('1970-01-01'), 0)
  assert.equal(at('1970-01-02'), 1)

  // Across the European clock change the same interval in milliseconds is
  // 20.958 days, and 21 is a threshold — integer subtraction, not division.
  assert.equal(at('2026-04-01') - at('2026-03-11'), 21)

  // And it is stable whatever the machine thinks the local time is.
  assert.equal(at('2025-11-09'), 20401)
  assert.equal(at('2026-01-05') - at('2025-11-09'), 57)
})

test('the labels are the ones the axis prints', () => {
  assert.equal(shortDate(at('2026-08-09')), '9 Aug')
  assert.equal(longDate(at('2026-08-09')), 'Sun 9 Aug 2026')
})

test('a caption’s date carries a year only when it is not the card’s own', () => {
  const today = at('2026-08-15')

  assert.equal(qualifiedDate(at('2026-08-09'), today), '9 Aug')

  // "covers through 9 Aug" about a two-year-old file is a different sentence.
  assert.equal(qualifiedDate(at('2024-08-09'), today), '9 Aug 2024')

  // Compared against the CARD's end, not the machine's clock.
  assert.equal(qualifiedDate(at('2024-08-09'), at('2024-12-31')), '9 Aug')
})

/* -------------------------------------------------------------------------
 * GAPS
 * ---------------------------------------------------------------------- */

test('a run is broken wherever more days passed than a trend can span', () => {
  const points = ['2025-10-13', '2025-11-09', '2026-01-05', '2026-01-12'].map((date) => ({
    date,
    day: at(date),
  }))

  const runs = splitSegments(points, 21)

  // 27 then 57 days: two breaks, three runs, the middle one a single reading.
  assert.equal(runs.length, 3)
  assert.deepEqual(
    runs.map((run) => run.map((p) => p.date)),
    [['2025-10-13'], ['2025-11-09'], ['2026-01-05', '2026-01-12']]
  )
})

test('exactly the threshold still joins, one day more does not', () => {
  const run = (a, b) => splitSegments([{ day: at(a) }, { day: at(b) }], 21).length

  assert.equal(run('2026-03-01', '2026-03-22'), 1)
  assert.equal(run('2026-03-01', '2026-03-23'), 2)
})

test('an empty series is no runs rather than one empty run', () => {
  assert.deepEqual(splitSegments([], 21), [])
})

/* -------------------------------------------------------------------------
 * THE CURVE
 * ---------------------------------------------------------------------- */

/** Every y a cubic path mentions: the on-curve points AND the control points. */
function pathYs(d) {
  return d
    .split(/[MLC]/)
    .filter((chunk) => chunk.trim() !== '')
    .flatMap((chunk) => chunk.trim().split(/\s+/).map(Number))
    .filter((_, index) => index % 2 === 1)
}

test('the curve never leaves the interval its readings bracket', () => {
  // A Catmull-Rom or natural cubic through these dips below 54.9 on the last
  // stretch — a weight the scale never showed.
  const points = [
    { x: 0, y: 56.4 },
    { x: 10, y: 55.1 },
    { x: 20, y: 55.0 },
    { x: 30, y: 54.9 },
  ]

  const ys = pathYs(monotonePath(points))

  assert.ok(Math.min(...ys) >= 54.9 - 1e-9, `dipped to ${Math.min(...ys)}`)
  assert.ok(Math.max(...ys) <= 56.4 + 1e-9, `rose to ${Math.max(...ys)}`)
})

test('a spike is a spike and not a launch ramp', () => {
  const points = [
    { x: 0, y: 55 },
    { x: 10, y: 58 },
    { x: 20, y: 55 },
  ]

  // Zero tangent at a turning point stops the curve sailing past the reading.
  assert.equal(monotoneTangents(points)[1], 0)
  assert.ok(Math.max(...pathYs(monotonePath(points))) <= 58 + 1e-9)
})

test('a flat series draws a flat line', () => {
  const points = [0, 10, 20, 30].map((x) => ({ x, y: 42 }))

  assert.deepEqual(new Set(pathYs(monotonePath(points))), new Set([42]))
})

test('one reading is not a trend, two are a straight segment', () => {
  assert.equal(monotonePath([{ x: 0, y: 1 }]), null)
  assert.equal(monotonePath([]), null)
  assert.equal(monotonePath([{ x: 0, y: 1 }, { x: 10, y: 2 }]), 'M 0 1 L 10 2')
})

test('the wash is the same curve, closed to the floor', () => {
  const points = [
    { x: 0, y: 20 },
    { x: 10, y: 30 },
    { x: 20, y: 25 },
  ]

  const area = areaPath(points, 150)

  assert.ok(area.startsWith(monotonePath(points)))
  assert.ok(area.endsWith('L 20 150 L 0 150 Z'))
  assert.equal(areaPath([{ x: 0, y: 1 }], 150), null)
})

/* -------------------------------------------------------------------------
 * TWO LINES, AND THE DASHES BETWEEN THEM
 * ---------------------------------------------------------------------- */

/**
 * WeightChart's `shapes` and `bridges`, x = day and y = number. The RULE — which
 * stretches are solid, which dashed, and that raw line and trend agree — survives
 * the component's projection, so it is checkable with no DOM. If this and
 * `WeightChart.vue` stop corresponding, that is the bug.
 */
function draw(points, maxGapDays = 21) {
  const runs = splitSegments(points, maxGapDays)
  const path = (run, pick) => monotonePath(run.map((p) => ({ x: p.day, y: pick(p) })))
  const both = (run) => ({ line: path(run, (p) => p.trend), raw: path(run, (p) => p.value) })

  const dashed = []

  for (let i = 1; i < runs.length; i++) {
    dashed.push(both([runs[i - 1][runs[i - 1].length - 1], runs[i][0]]))
  }

  return { solid: runs.map(both).filter((shape) => shape.line !== null), dashed }
}

/** Two weigh-ins, a 27-day hole, one lone reading, a 57-day hole, two more. */
const history = [
  ['2025-10-06', 56.4, 56.4],
  ['2025-10-13', 55.9, 56.2],
  ['2025-11-09', 55.2, 55.9],
  ['2026-01-05', 54.4, 55.5],
  ['2026-01-12', 54.6, 55.3],
].map(([date, value, trend]) => ({ date, day: at(date), value, trend }))

test('the line reaches every reading — solid where measured, dashed where guessed', () => {
  const { solid, dashed } = draw(history)

  // The lone November reading is joined anyway, once from each side. It used to
  // float with no line at all; the owner overruled that.
  assert.equal(solid.length, 2)
  assert.equal(dashed.length, 2)

  // A straight L and nothing else: a spline across eight weeks would have a
  // SHAPE, and a shape is a claim about a stretch nobody recorded.
  assert.equal(dashed[0].line, `M ${at('2025-10-13')} 56.2 L ${at('2025-11-09')} 55.9`)
  assert.equal(dashed[1].line, `M ${at('2025-11-09')} 55.9 L ${at('2026-01-05')} 55.5`)

  // Nothing SOLID crosses either hole.
  assert.equal(solid[0].line, `M ${at('2025-10-06')} 56.4 L ${at('2025-10-13')} 56.2`)
  assert.equal(solid[1].line, `M ${at('2026-01-05')} 55.5 L ${at('2026-01-12')} 55.3`)
})

test('the thin line has no licence to join what the trend would not', () => {
  const { solid, dashed } = draw(history)

  // One splitSegments for both: the raw line cannot stroke where the trend does not.
  assert.ok(solid.every((shape) => shape.raw !== null))
  assert.deepEqual(
    dashed.map((bridge) => bridge.raw),
    [
      `M ${at('2025-10-13')} 55.9 L ${at('2025-11-09')} 55.2`,
      `M ${at('2025-11-09')} 55.2 L ${at('2026-01-05')} 54.4`,
    ]
  )
})

test('the thin line goes through the rings, not near them', () => {
  const readings = [55.4, 56.1, 54.8, 55.6].map((value, day) => ({
    day,
    value,
    // A trend that lags under a fast drop — the case the thin line exists for.
    trend: 56.4 - day * 0.2,
  }))

  const { solid } = draw(readings)

  // A cubic segment ends on its second anchor, so every reading is a literal
  // coordinate — rings and thin line are one statement, not two that nearly agree.
  for (const point of readings) {
    assert.ok(solid[0].raw.includes(`${point.day} ${point.value}`), solid[0].raw)
  }

  assert.notEqual(solid[0].raw, solid[0].line)
})

/* -------------------------------------------------------------------------
 * AXES
 * ---------------------------------------------------------------------- */

test('a week is labelled a day at a time', () => {
  const ticks = timeTicks(at('2026-08-03'), at('2026-08-09'))

  // Counting back by sevens, as the 4w window does, would leave one label on the
  // right-hand edge — and one label is not an axis.
  assert.deepEqual(
    ticks.map((t) => t.label),
    ['3 Aug', '4 Aug', '5 Aug', '6 Aug', '7 Aug', '8 Aug', '9 Aug']
  )

  // Eleven days is past where a label a day fits, and back to sevens.
  assert.equal(timeTicks(at('2026-07-29'), at('2026-08-09')).length, 2)
})

test('the value axis lands on round numbers inside the window', () => {
  assert.deepEqual(valueTicks(53.7, 57.3), [54, 55, 56, 57])

  // Nothing outside the window: a gridline above the top points at the border.
  for (const tick of valueTicks(22.06, 24.94)) {
    assert.ok(tick >= 22.06 && tick <= 24.94)
  }
})

test('a short range is labelled weekly, counted back from today', () => {
  const ticks = timeTicks(at('2026-07-13'), at('2026-08-09'))

  assert.deepEqual(
    ticks.map((t) => t.label),
    ['19 Jul', '26 Jul', '2 Aug', '9 Aug']
  )
})

test('a long range is labelled by month, and by year once it needs to be', () => {
  const quarter = timeTicks(at('2026-05-11'), at('2026-08-09'))

  assert.deepEqual(
    quarter.map((t) => t.label),
    ['Jun', 'Jul', 'Aug']
  )

  // Fifteen months: "Aug" twice on one axis is not an axis.
  const all = timeTicks(at('2024-04-03'), at('2026-08-09'))

  assert.ok(all.length <= 5)
  assert.ok(all.every((t) => /^[A-Z][a-z]{2} \u{2019}\d{2}$/u.test(t.label)), JSON.stringify(all))
  // The apostrophe, not a space: "Aug 26" beside "9 Aug" reads as a date.
  assert.equal(all[all.length - 1].label, 'Aug \u{2019}26')
})

/* -------------------------------------------------------------------------
 * BOUNDS
 * ---------------------------------------------------------------------- */

test('the axis is never zero-based and never tighter than the noise', () => {
  const { min, max } = niceBounds([54.5, 56.9], { minSpan: 2, pad: 0.15 })

  assert.ok(min > 50, `${min} is most of a person below the data`)
  assert.ok(min < 54.5 && max > 56.9)
})

test('a fortnight that barely moved is not magnified into a mountain range', () => {
  const { min, max } = niceBounds([55.0, 55.2], { minSpan: 2, pad: 0 })

  assert.equal(Math.round((max - min) * 1000) / 1000, 2)
  // Centred on the readings rather than pinned to one end of them.
  assert.equal(Math.round(((min + max) / 2) * 1000) / 1000, 55.1)
})

test('nothing measured is a window, not a crash', () => {
  assert.deepEqual(niceBounds([]), { min: 0, max: 1 })
  assert.deepEqual(niceBounds([null, undefined, NaN]), { min: 0, max: 1 })
})
