/**
 * The server's rules, decided in the browser, in the server's own words.
 *
 * Every sentence here was written by Laravel and exported
 * (rules.generated.json, `php artisan validation:export`), so a box that
 * refuses on blur says exactly what the 422 would. The gating is copied, not
 * approximated, and tests/js/validation-agreement.test.js proves it over
 * ~2000 real-validator cases.
 *
 * Pure on purpose: no DOM, no Vue, and the rules arrive as an argument so
 * `node --test` can read the same committed artifact the bundle imports.
 *
 * See docs/rationale-frontend.md § "Saying it before the round trip"
 */

/** Rules that run even when there is nothing in the box — Laravel's `implicitRules`. */
const IMPLICIT = new Set(['required'])

/** Rules that only decide whether the others run. */
const CONTROL = new Set(['nullable', 'sometimes'])

/** What makes `max:120` count in units rather than characters — Laravel's `numericRules`. */
const NUMERIC = new Set(['numeric', 'integer', 'decimal'])

const ARRAY = new Set(['array', 'list'])

/** Str::isUuid, which is what `uuid` validates through. */
const UUID = /^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/

/**
 * WHITESPACE IS NARROWER IN PHP THAN IN JAVASCRIPT, AND THE GAP RUNS BACKWARD:
 * each set below is PHP's own, measured rather than remembered.
 * See docs/rationale-frontend.md § "Saying it before the round trip"
 */

/** PHP's trim() charlist: space, tab, newline, carriage return, NUL, vertical tab — and NOT form feed. */
/* eslint-disable-next-line no-control-regex -- the control characters ARE the charlist PHP uses */
const PHP_BLANK = /^[ \t\n\r\0\x0B]*$/

/** is_numeric's leading and trailing whitespace, which DOES include form feed. */
/* eslint-disable-next-line no-control-regex -- the control characters ARE the charlist PHP uses */
const NUMERIC_STRING = /^[ \t\n\r\x0B\x0C]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\x0B\x0C]*$/

/** filter_var(..., FILTER_VALIDATE_INT), which is what `integer` validates through — form feed rejected. */
/* eslint-disable-next-line no-control-regex -- the control characters ARE the charlist PHP uses */
const INTEGER_STRING = /^[ \t\n\r\x0B]*[+-]?\d+[ \t\n\r\x0B]*$/

const DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]

/**
 * The rules for one box, and the item number it belongs to: `items.2.kcal` is
 * validated by the `items.*.kcal` entry, and the index only fills in the name.
 */
export function fieldFor(catalogue, request, field) {
  const fields = catalogue?.requests?.[request]?.fields

  if (!fields || typeof field !== 'string') return null

  if (fields[field]) return { entry: fields[field], index: null }

  const segments = field.split('.')
  const at = segments.findIndex((segment) => /^\d+$/.test(segment))

  if (at === -1) return null

  const pattern = segments.map((segment) => (/^\d+$/.test(segment) ? '*' : segment)).join('.')

  return fields[pattern] ? { entry: fields[pattern], index: Number(segments[at]) } : null
}

/**
 * What the server would say about this box, or null if it would say nothing —
 * on purpose covering three different silences (value fine, server-only rule,
 * unexported field) that all mean: say nothing yet, let submit answer it.
 */
export function messageFor(catalogue, request, field, data) {
  const found = fieldFor(catalogue, request, field)

  if (!found) return null

  const rules = found.entry.rules ?? []
  const names = rules.map((rule) => rule.rule)
  const value = valueAt(data, field)
  const present = value !== undefined

  const context = {
    data,
    index: found.index,
    numeric: names.some((name) => NUMERIC.has(name)),
    array: names.some((name) => ARRAY.has(name)),
    field,
  }

  // `sometimes` is about the whole field: a key never sent is not being asked about.
  if (names.includes('sometimes') && !present) return null

  const nullable = names.includes('nullable')

  for (const rule of rules) {
    if (rule.server_only || CONTROL.has(rule.rule)) continue

    const implicit = IMPLICIT.has(rule.rule)

    if (!runs(value, present, implicit, nullable)) continue
    if (passes(rule, value, context)) continue

    return sentence(rule.message, found)
  }

  return null
}

/** Laravel's isValidatable, minus the parts no exported rule reaches. */
function runs(value, present, implicit, nullable) {
  if (typeof value === 'string' && blank(value)) {
    if (!implicit) return false
  } else if (!present && !implicit) {
    return false
  }

  return !(!implicit && nullable && value === null)
}

function passes(rule, value, context) {
  const params = rule.params ?? []

  switch (rule.rule) {
    case 'required':
      return required(value)
    case 'string':
      return typeof value === 'string'
    case 'numeric':
      return isNumeric(value)
    case 'integer':
      return isInteger(value)
    case 'boolean':
      return [true, false, 0, 1, '0', '1'].some((accepted) => accepted === value)
    case 'array':
      return isArray(value)
    case 'uuid':
      return typeof value === 'string' && UUID.test(value)
    case 'in':
      return isIn(value, params, context)
    case 'enum':
      // A backed enum answers `tryFrom`'s own type, unlike `in`, which would cast to a string first.
      return typeof value === 'string' && params.includes(value)
    case 'date_format':
      return params.some((format) => matchesFormat(value, format))
    case 'confirmed':
      return same(value, valueAt(context.data, `${context.field}_confirmation`))
    case 'min':
      return size(value, context) >= Number(params[0])
    case 'max':
      return size(value, context) <= Number(params[0])
    case 'gte':
      return gte(value, params[0], context)
    default:
      // An untaught rule would otherwise pass silently — a box the server refuses and the browser calls fine.
      throw new Error(`No browser check for the validation rule \`${rule.rule}\`.`)
  }
}

function required(value) {
  if (value === null || value === undefined) return false
  if (typeof value === 'string') return !blank(value)
  if (isArray(value)) return count(value) > 0

  return true
}

function isIn(value, params, context) {
  if (isArray(value) && context.array) {
    const members = Array.isArray(value) ? value : Object.values(value)

    return members.every((member) => !isArray(member) && params.includes(text(member)))
  }

  return !isArray(value) && params.includes(text(value))
}

/**
 * DateTime::createFromFormat, for the two formats this app uses: PHP compares
 * a reformatted rolled-over date against what was typed, so checking the
 * calendar outright gives the same answer with none of the parsing.
 */
function matchesFormat(value, format) {
  const typed = typeof value === 'string' || typeof value === 'number' ? String(value) : null

  if (typed === null) return false

  if (format === 'Y-m-d') {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(typed)

    if (!parts) return false

    const [year, month, day] = parts.slice(1).map(Number)

    return month >= 1 && month <= 12 && day >= 1 && day <= daysInMonth(year, month)
  }

  if (format === 'H:i') {
    const parts = /^(\d{2}):(\d{2})$/.exec(typed)

    if (!parts) return false

    const [hours, minutes] = parts.slice(1).map(Number)

    return hours <= 23 && minutes <= 59
  }

  // Refusing to guess: an unmirrored format would be a sentence nobody can trust. Teach the exporter instead.
  throw new Error(`No browser check for the date format \`${format}\`.`)
}

function daysInMonth(year, month) {
  const leap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0

  return month === 2 && leap ? 29 : DAYS_IN_MONTH[month - 1]
}

/** validateSame, which `confirmed` runs through: identical, and of the same type. */
function same(value, other) {
  return value === (other === undefined ? null : other)
}

/** Laravel's getSize: a number for a numeric field, a count for a list, else character length. */
function size(value, context) {
  if (context.numeric && isNumeric(value)) return Number(phpTrim(text(value)))
  if (isArray(value)) return count(value)

  return Array.from(text(value)).length
}

function gte(value, other, context) {
  const path = context.index === null ? other : other.replaceAll('*', String(context.index))
  const compared = valueAt(context.data, path) ?? null

  // The parameter is always a field to look at, never a number, here — Laravel's numeric-parameter branches are dead and left out.
  if (isNumeric(other)) return false

  if (context.numeric && isNumeric(value) && isNumeric(compared)) {
    return Number(phpTrim(text(value))) >= Number(phpTrim(text(compared)))
  }

  if (phpType(value) !== phpType(compared)) return false

  return size(value, context) >= size(compared, context)
}

function sentence(message, found) {
  if (typeof message !== 'string') return null

  const attribute = found.index === null
    ? found.entry.attribute
    : found.entry.attribute.replace(':index', String(found.index))

  return message.replaceAll(':attribute', attribute)
}

export function valueAt(data, path) {
  let current = data

  for (const segment of String(path).split('.')) {
    if (current === null || typeof current !== 'object') return undefined

    current = current[segment]
  }

  return current
}

/** PHP's trim(), for the numbers Laravel trims before it weighs them. */
function phpTrim(value) {
  /* eslint-disable-next-line no-control-regex -- as above: PHP's trim() charlist, spelled out */
  return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')
}

/** Empty to PHP's trim(), which is a narrower claim than JavaScript's. */
function blank(value) {
  return PHP_BLANK.test(value)
}

function isNumeric(value) {
  if (typeof value === 'number') return Number.isFinite(value)

  return typeof value === 'string' && NUMERIC_STRING.test(value)
}

function isInteger(value) {
  if (typeof value === 'number') return Number.isInteger(value)
  // PHP filters through a string first: `true` reads as 1, `false` (an empty string) as nothing.
  if (typeof value === 'boolean') return value

  return typeof value === 'string' && INTEGER_STRING.test(value)
}

function isArray(value) {
  return value !== null && typeof value === 'object'
}

function count(value) {
  return Array.isArray(value) ? value.length : Object.keys(value).length
}

/** PHP's string cast, which is not JavaScript's: a bool is `1` or nothing at all. */
function text(value) {
  if (value === null || value === undefined) return ''
  if (typeof value === 'boolean') return value ? '1' : ''

  return String(value)
}

/** gettype(), which `gte` compares before it compares sizes. */
function phpType(value) {
  if (value === null || value === undefined) return 'NULL'
  if (typeof value === 'boolean') return 'boolean'
  if (typeof value === 'string') return 'string'
  if (typeof value === 'number') return Number.isInteger(value) ? 'integer' : 'double'

  return 'array'
}
