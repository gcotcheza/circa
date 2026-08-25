<script setup>
import { useForm, Head } from '@inertiajs/vue3'
import { useInlineValidation } from '../../lib/validation/inline'

/** The only unauthenticated page: no "create an account", no "forgot password" —
 * neither route exists (see routes/web.php). */
const form = useForm({
  email: '',
  password: '',
})

/**
 * `novalidate` and not one native constraint: the browser's own bubble is
 * unstyled, differently worded in every browser and gone on the next tap, and
 * on iOS it can refuse the submit without drawing anything at all. These boxes
 * say the server's own sentence as you leave them instead.
 */
const inline = useInlineValidation(form, 'Login')

function submit() {
  form.post('/login', {
    onFinish: () => {
      form.reset('password')
      inline.reset()
    },
  })
}
</script>

<template>
  <Head title="Sign in" />

  <div class="flex min-h-dvh flex-col justify-center px-6 py-12">
    <div class="mx-auto w-full max-w-sm">
      <h1 class="text-2xl font-semibold tracking-tight">Health</h1>
      <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">
        Intake, burn and weight — for one person.
      </p>

      <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
        <div>
          <label for="email" class="block text-sm font-medium">Email</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            name="email"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            autofocus
            class="mt-1.5 w-full rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-base
                   outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/30
                   dark:border-stone-700 dark:bg-stone-900"
            v-bind="inline.on('email')"
          >
        </div>

        <div>
          <label for="password" class="block text-sm font-medium">Password</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            name="password"
            autocomplete="current-password"
            class="mt-1.5 w-full rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-base
                   outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/30
                   dark:border-stone-700 dark:bg-stone-900"
            v-bind="inline.on('password')"
          >
          <p v-if="form.errors.password" class="mt-1.5 text-sm text-rose-600 dark:text-rose-400">
            {{ form.errors.password }}
          </p>
        </div>

        <!-- One message for a wrong email and a wrong password alike: with a
             single account, distinguishing them confirms the address. -->
        <p v-if="form.errors.email" class="text-sm text-rose-600 dark:text-rose-400">
          {{ form.errors.email }}
        </p>

        <button
          type="submit"
          :disabled="form.processing"
          class="w-full rounded-xl bg-teal-600 px-4 py-2.5 text-base font-semibold text-white
                 transition active:scale-[0.99] disabled:opacity-60"
        >
          {{ form.processing ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </div>
  </div>
</template>
