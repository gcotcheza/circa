import assert from 'node:assert/strict'
import test from 'node:test'

import catalogue from '../../resources/js/lib/validation/rules.generated.json' with { type: 'json' }
import { messageFor } from '../../resources/js/lib/validation/index.js'
import { inlineErrors, useInlineValidation } from '../../resources/js/lib/validation/inline.js'

/**
 * The wiring in resources/js/lib/validation/inline.js — the state machine, not
 * the sentences (validation-agreement.test.js has those).
 *
 * Both composables run one shared machine now, so what is worth pinning is the
 * three things that are NOT the same between them, each of which is a real bug
 * the day it flips:
 *
 *   RESET IS ASYMMETRIC. `useInlineValidation` writes into Inertia's bag, which
 *   also carries what the last save came back with — so its reset() forgets the
 *   touches and leaves the bag alone. `inlineErrors` owns its bag outright and
 *   empties it. A reset that cleared Inertia's would swallow a server refusal.
 *
 *   `forget` RUNS BEFORE THE SENTENCE IS WORKED OUT, because the second bag it
 *   drops from is merged LAST by the sheets that keep one: a stale error left
 *   in it hides the fresh sentence rather than being replaced by it.
 *
 *   SILENCE UNTIL TYPED IN: blur alone says nothing, so tabbing through an
 *   empty optional box never turns it red.
 */

/** Inertia's `useForm` error bag, as much of it as the wiring touches. */
function fakeForm(data, log = []) {
  return {
    errors: {},
    log,
    data: () => ({ ...data }),
    setError(field, message) {
      this.log.push(`set:${field}`)
      this.errors[field] = message
    },
    clearErrors(...only) {
      this.log.push(`clear:${only.join(',')}`)

      // Inertia's own semantics, and the reason reset() must not call this
      // with nothing: no field named means EVERY sentence goes, the server's
      // included.
      if (only.length === 0) this.errors = {}

      for (const field of only) delete this.errors[field]
    },
  }
}

const requiredEmail = messageFor(catalogue, 'Login', 'email', { email: '' })

test('the fixture this file leans on still refuses an empty email', () => {
  assert.ok(requiredEmail, 'Login.email stopped refusing a blank, so every assertion below passes vacuously')
})

test('a box nobody has typed in stays silent when it is left', () => {
  const form = fakeForm({ email: '' })

  useInlineValidation(form, 'Login').check('email')

  assert.deepEqual(form.errors, {})
})

test('a box that has been typed in says what the server would, and stops as soon as it is typed in again', () => {
  const form = fakeForm({ email: '' })
  const inline = useInlineValidation(form, 'Login')

  inline.clear('email')
  inline.check('email')

  assert.equal(form.errors.email, requiredEmail)

  inline.clear('email')

  assert.deepEqual(form.errors, {})
})

test('what is judged is what the form will POST, when the two differ', () => {
  const form = fakeForm({ email: '' })
  const inline = useInlineValidation(form, 'Login', { data: () => ({ email: 'someone@example.test' }) })

  inline.clear('email')
  inline.check('email')

  assert.deepEqual(form.errors, {}, 'the raw box was judged instead of what the save would send')
})

test('the second bag is dropped from before the sentence lands, never after', () => {
  const log = []
  const form = fakeForm({ email: '' }, log)
  const inline = useInlineValidation(form, 'Login', { forget: (field) => log.push(`forget:${field}`) })

  inline.clear('email')
  inline.check('email')

  assert.deepEqual(log, ['forget:email', 'forget:email', 'set:email'])
})

test('the pair a box binds is the input handler clearing and the blur handler checking', () => {
  const wired = fakeForm({ email: '' })
  const called = fakeForm({ email: '' })
  const byPair = useInlineValidation(wired, 'Login')
  const byHand = useInlineValidation(called, 'Login')

  const pair = byPair.on('email')

  assert.deepEqual(Object.keys(pair), ['onInput', 'onBlur'], 'a box binds these two and nothing else')

  // Left without being typed in: silent, and the same silence either way.
  pair.onBlur()
  byHand.check('email')

  assert.deepEqual(wired.errors, {})

  // Typed in: touched, and STILL silent. Nothing is refused as you type, so an
  // input handler that judged would be a box turning red under the thumb.
  pair.onInput()
  byHand.clear('email')

  assert.deepEqual(wired.errors, {}, 'the input handler judged the box; it is the blur that judges')

  // And then left: the sentence.
  pair.onBlur()
  byHand.check('email')

  assert.equal(wired.errors.email, requiredEmail)
  assert.deepEqual(wired.errors, called.errors)
  assert.deepEqual(wired.log, called.log, 'the pair did not do the same things in the same order')
})

test('a forget explicitly passed as nothing is still nothing to do', () => {
  const form = fakeForm({ email: '' })
  const inline = useInlineValidation(form, 'Login', { forget: null })

  inline.clear('email')
  inline.check('email')

  assert.equal(form.errors.email, requiredEmail)
})

test('reset forgets the touches but leaves Inertia the bag it shares with the server', () => {
  const form = fakeForm({ email: '' })
  const inline = useInlineValidation(form, 'Login')

  inline.clear('email')
  inline.check('email')

  inline.reset()

  assert.equal(form.errors.email, requiredEmail, 'reset() emptied a bag the save also writes to')

  inline.check('email')

  assert.equal(form.errors.email, requiredEmail, 'the box is judged again although nobody has typed in it since')
})

test('the componentless bag is its own, so reset empties that one', () => {
  const inline = inlineErrors('Login', () => ({ email: '' }))

  inline.clear('email')
  inline.check('email')

  assert.equal(inline.errors.email, requiredEmail)

  inline.reset()

  assert.deepEqual({ ...inline.errors }, {})

  inline.check('email')

  assert.deepEqual({ ...inline.errors }, {}, 'blur speaks again although the box has not been typed in since the reset')
})
