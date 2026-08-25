import { reactive } from 'vue'

import catalogue from './rules.generated.json' with { type: 'json' }
import { messageFor } from './index.js'

/**
 * Wiring the rules into a form: the box says what is wrong as you leave it.
 *
 * TOLD ABOUT WHAT YOU TYPED, NEVER WHAT YOU HAVE NOT: `clear()` marks a box
 * touched and drops its sentence; `check()` on blur is silent until then, so
 * an untouched optional field never turns red.
 *
 * The sentence lands in Inertia's own error bag, and submit still replaces it
 * wholesale with the server's answer. TOUCHED IS PER FORM (the meal sheet
 * mounts once, so `reset()` belongs beside every `form.reset()`), and
 * `inlineErrors` is the same for components with no `useForm`.
 *
 * See docs/rationale-frontend.md § "Saying it before the round trip"
 */

/**
 * The state machine both composables are: what differs between them is only
 * where a sentence is kept, so that is the argument. The `sink` writes into an
 * Inertia error bag or a reactive object of its own — it decides nothing.
 *
 * @param request  the exported rule set, e.g. 'MealRequest'
 * @param data  what to judge, read fresh on every check
 * @param sink  holds/says/drops a sentence, and empties itself on reset
 * @param forget  a second bag to drop this field from, called BEFORE the
 *   sentence is worked out: a stale error merged last would hide the fresh one
 */
function wire({ request, data, sink, forget = () => {} }) {
  const touched = new Set()

  function check(field) {
    if (!touched.has(field)) return

    forget(field)

    const said = messageFor(catalogue, request, field, data())

    if (said === null) sink.drop(field)
    else sink.say(field, said)
  }

  // Every keystroke arrives here, so an empty box is left alone rather than
  // written over with the same emptiness.
  function clear(field) {
    touched.add(field)
    forget(field)

    if (sink.holds(field)) sink.drop(field)
  }

  /** Beside every `form.reset()`: a fresh form has been typed into by nobody. */
  function reset() {
    touched.clear()
    sink.emptied()
  }

  /**
   * The pair a wired box carries, as `v-bind="inline.on('height_cm')"`. Two
   * handlers spelt the field name twice and could disagree — and a name that
   * matches no rule is a box that silently never speaks, which is the fault
   * InlineValidationWiringTest exists to catch. Its grep knows this shape too.
   */
  function on(field) {
    return { onInput: () => clear(field), onBlur: () => check(field) }
  }

  return { check, clear, reset, on }
}

/**
 * @param form  an Inertia `useForm` object; its error bag is where sentences land
 * @param request  the exported rule set, e.g. 'MealRequest'
 * @param options.data  what the form will actually SEND, when that differs from
 *   what it holds — a box holding "160,5" is posted as 160.5, and judging the
 *   box would refuse a number the save accepts
 * @param options.forget  a second error bag to drop this field from, for the
 *   two sheets that save outside Inertia and keep the server's refusals of
 *   their own. Without it a stale sentence about a value that has since changed
 *   both persists and HIDES the fresh one, since it is merged last.
 */
export function useInlineValidation(form, request, options = {}) {
  return wire({
    request,
    data: options.data ?? (() => form.data()),
    // `?? `, not a default parameter: a call site that passes `forget: null`
    // explicitly would otherwise throw on the first keystroke.
    forget: options.forget ?? (() => {}),
    sink: {
      holds: (field) => Boolean(form.errors[field]),
      say: (field, said) => form.setError(field, said),
      drop: (field) => form.clearErrors(field),
      // The bag is the SERVER's to empty: a refusal that came back from a save
      // stands until the next one answers, whoever calls reset() in between.
      emptied: () => {},
    },
  })
}

/**
 * The same, for a component with no Inertia form: `errors` is reactive and
 * keyed by field, rendering exactly the way `form.errors` does — and nothing
 * else writes to it, so reset() empties it as well as forgetting the touches.
 */
export function inlineErrors(request, data) {
  const errors = reactive({})

  return {
    errors,
    ...wire({
      request,
      data,
      sink: {
        holds: (field) => Boolean(errors[field]),
        say: (field, said) => { errors[field] = said },
        drop: (field) => { delete errors[field] },
        emptied: () => { for (const field of Object.keys(errors)) delete errors[field] },
      },
    }),
  }
}
