/**
 * The stress bands, as the client draws them.
 *
 * THE COLOURS ARE NOT IN THIS FILE, AND THAT IS THE POINT. Every band names a
 * CSS custom property; the four hexes behind each one (light and dark) live in
 * exactly one place, resources/css/app.css, with the working that produced them.
 * So the browser themes the palette rather than this module: no component asks
 * which theme is on, the heatmap's 168 rects cost one
 * `style="fill: var(--stress-normal)"` each instead of a class-per-band Tailwind
 * cannot see (a class name existing only as a value in an object like this one),
 * and a theme change repaints without Vue being involved.
 *
 * Everything these return is therefore a `var(...)`, valid anywhere a colour is
 * — `fill`, `background-color`, `color` — as long as it goes through a STYLE. It
 * must not be handed to an SVG presentation attribute (`fill="..."`), which is
 * not a CSS declaration and does not substitute custom properties.
 *
 * WHAT THE PASTELS COST: this order-and-valence ramp passes normal-vision separation and
 * dark-surface contrast, and warns on light-surface contrast and on red-green
 * CVD separation, where it is a real regression on the saturated ramp it
 * replaces. That is acceptable ONLY because nothing in this feature is encoded
 * by colour alone, and nothing in it may become so — every score is printed as a
 * number beside its swatch, the legend always carries the four labels, the week
 * chart writes each value above its point, and a tapped heatmap cell answers
 * "Mon 09:00 · 42 · Pay attention" in words. Removing any of those affordances
 * would turn a warning into a defect.
 * See docs/rationale-frontend.md § "The stress band pastels, measured"
 */
export const BANDS = {
  great: { label: 'Great', fill: 'var(--stress-great)', ink: 'var(--stress-ink-great)' },
  normal: { label: 'Normal', fill: 'var(--stress-normal)', ink: 'var(--stress-ink-normal)' },
  attention: {
    label: 'Pay attention',
    fill: 'var(--stress-attention)',
    ink: 'var(--stress-ink-attention)',
  },
  overload: { label: 'Overload', fill: 'var(--stress-overload)', ink: 'var(--stress-ink-overload)' },
}

/** The fill for a band key, or a neutral for anything unknown. */
export function bandFill(band) {
  return BANDS[band]?.fill ?? 'var(--stress-unknown)'
}

/**
 * The colour for a band's NAME rather than a shape, separate from `fill` on
 * purpose: a pastel is unreadable as text on white, and the deepened dark fills
 * are too dim as text on stone-900.
 */
export function bandInk(band) {
  return BANDS[band]?.ink ?? 'var(--stress-unknown)'
}

export function bandLabel(band) {
  return BANDS[band]?.label ?? 'Unknown'
}

/**
 * The band swatch beside a score. The ring is what makes a 10 px patch of pastel
 * locatable on a white card (see --stress-swatch-ring), and it is inset rather
 * than a border so the swatch keeps the size the layout gave it.
 */
export function bandSwatchStyle(band) {
  return {
    backgroundColor: bandFill(band),
    boxShadow: 'inset 0 0 0 1px var(--stress-swatch-ring)',
  }
}

/**
 * Why a day has no score, in one sentence the user can act on. The server sends
 * a reason code rather than a sentence so the wording lives with the copy.
 */
export function reasonLabel(reason, minSamples = 3) {
  switch (reason) {
    case 'no_readings':
      return 'No HRV readings — the Watch was off.'
    case 'too_few_readings':
      return `Fewer than ${minSamples} HRV readings. Too thin to score.`
    case 'no_baseline':
      return 'Not enough history behind this day to compare it with yet.'
    default:
      return 'No score.'
  }
}

/** "20 readings · high confidence" — the honesty line under every score. */
export function coverageLabel(day) {
  if (!day || day.samples === 0) return 'no readings'

  const readings = `${day.samples} reading${day.samples === 1 ? '' : 's'}`

  return day.confidence === 'none' ? readings : `${readings} · ${day.confidence} confidence`
}

export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

/**
 * "Thu 6 Aug". The weekday is there because the whole page is organised by week,
 * and "6 Aug" alone forces a glance back at the chart to work out which column
 * it was. Parsed with an explicit midnight so the browser reads a local date
 * rather than a UTC instant — an hour earlier, and a different day at the start
 * of a month.
 */
export function formatDay(iso) {
  if (!iso) return ''

  return new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  })
}

/** "6 Aug" — the same date where the week is already established by context. */
export function formatShortDate(iso) {
  if (!iso) return ''

  return new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

/** "07:00" — an hour of the day, zero-padded so a column of them lines up. */
export function formatHour(hour) {
  return `${String(hour).padStart(2, '0')}:00`
}

/**
 * A signed number with a real minus sign: U+2212 rather than a hyphen, because
 * at the sizes these deltas are shown at a hyphen next to a tabular digit reads
 * as a hyphenation, and "-1" has been misread as "1" often enough in this app's
 * own charts to be worth the glyph.
 */
export function signed(value, decimals = 0) {
  if (value === null || value === undefined) return ''

  const fixed = Math.abs(value).toFixed(decimals)

  if (Number(fixed) === 0) return `±${fixed}`

  return `${value > 0 ? '+' : '−'}${fixed}`
}

/**
 * Why a day of the viewed week is not in the comparison. Same reason codes as
 * reasonLabel, shorter phrasing: this one appears inside a list of several.
 */
export function skippedLabel(reason, count) {
  const days = `${count} day${count === 1 ? '' : 's'}`

  switch (reason) {
    case 'no_readings':
      return `${days} with no readings`
    case 'too_few_readings':
      return `${days} too thin to score`
    case 'no_baseline':
      return `${days} without enough history behind them`
    default:
      return `${days} unscored`
  }
}
