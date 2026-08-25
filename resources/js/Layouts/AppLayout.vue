<script setup>
import { computed, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { blockedCount, flush, flushing, online, pendingCount, queueError } from '../lib/queue'
import { persistence, persistenceLabel } from '../lib/storage'
import { reloadForUpdate, updateReady, updateUrgent } from '../lib/sw'

/**
 * The shell: a title bar, the page, and a bottom nav. Navigation lives at the
 * BOTTOM because the only client is a phone and the top of a 6.7" screen is not
 * reachable one-handed; `pb-[env(safe-area-inset-bottom)]` keeps it clear of the
 * home indicator once installed to the Home Screen.
 *
 * Three status lines hang off it, all small, none modal. OFFLINE: a bar while
 * the radio is down, answering "did that save?" before it is asked. QUEUE: a
 * count in the header, tappable to flush, and the only place a blocked action
 * announces itself from any page. UPDATED: a new service worker took over — an
 * offer, not the only route, since lib/sw.js applies it alone once the app is
 * backgrounded and nothing has taken a hold; the wording changes when the page
 * is not merely stale but broken (see updateUrgent).
 */
defineProps({
  title: { type: String, default: null },
})

const page = usePage()

const flash = computed(() => page.props.flash ?? {})

const current = computed(() => page.url.split('?')[0])

const persistenceNote = computed(() =>
  persistence.value === 'persisted' ? null : persistenceLabel(persistence.value)
)

/* Signing in is an Inertia visit, not a page load, so app.js does not re-run and
 * the launch flush never happens again. Without this, a queue stopped on a 401
 * would sit until the app was next foregrounded — not soon enough on a phone
 * just picked up to log lunch. */
watch(
  () => page.props.auth?.user,
  (user, previous) => {
    if (user && !previous) flush()
  }
)

function logout() {
  router.post('/logout')
}
</script>

<template>
  <div class="min-h-full pb-24">
    <header
      class="sticky top-0 z-20 border-b border-stone-200/80 bg-stone-50/85 backdrop-blur
             dark:border-stone-800/80 dark:bg-stone-950/85"
    >
      <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-3">
        <h1 class="text-base font-semibold tracking-tight">
          {{ title ?? 'Health' }}
        </h1>

        <div class="flex items-center gap-2">
          <!-- Tapping flushes now rather than waiting for the next `online`
               event or foreground: whoever taps is watching it go away. -->
          <button
            v-if="pendingCount > 0"
            type="button"
            class="flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium transition active:scale-95"
            :class="blockedCount > 0
              ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
              : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'"
            :disabled="flushing"
            @click="flush()"
          >
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M20 11a8 8 0 1 0-2.3 5.7" stroke-linecap="round" />
              <path d="M20 5v6h-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span class="tnum">{{ flushing ? 'syncing…' : `${pendingCount} queued` }}</span>
          </button>

          <!-- Beside Sign out rather than in the nav below, which is full at
               four items: a profile is filled in once and revisited rarely —
               the test Supplements also failed. -->
          <Link
            href="/profile"
            :class="[
              'rounded-lg px-2 py-1 text-xs font-medium transition hover:bg-stone-200/60 active:scale-95 dark:hover:bg-stone-800/60',
              current === '/profile'
                ? 'text-teal-600 dark:text-teal-400'
                : 'text-stone-500 dark:text-stone-400',
            ]"
          >
            Profile
          </Link>

          <button
            type="button"
            class="rounded-lg px-2 py-1 text-xs font-medium text-stone-500 transition
                   hover:bg-stone-200/60 active:scale-95 dark:text-stone-400 dark:hover:bg-stone-800/60"
            @click="logout"
          >
            Sign out
          </button>
        </div>
      </div>

      <p
        v-if="!online"
        class="bg-stone-200/80 px-4 py-1.5 text-center text-xs font-medium text-stone-700
               dark:bg-stone-800/80 dark:text-stone-300"
      >
        Offline — anything you log is kept here and sent when you're back.
      </p>
    </header>

    <!-- Above the content, not a floating toast: a toast that fades is
         unreadable one-handed while walking, which is when meals get logged. -->
    <div v-if="flash.success || flash.error" class="mx-auto max-w-xl px-4 pt-3">
      <p
        class="rounded-xl px-3 py-2 text-sm"
        :class="flash.error
          ? 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200'
          : 'bg-teal-100 text-teal-900 dark:bg-teal-950 dark:text-teal-200'"
      >
        {{ flash.error ?? flash.success }}
      </p>
    </div>

    <div v-if="queueError" class="mx-auto max-w-xl px-4 pt-3">
      <p class="rounded-xl bg-amber-100 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
        {{ queueError }}
      </p>
    </div>

    <main class="mx-auto max-w-xl px-4 py-4">
      <slot />

      <!-- Only when there is something to act on: a protected store says nothing. -->
      <p v-if="persistenceNote" class="mt-8 px-1 text-[11px] leading-relaxed text-stone-400 dark:text-stone-600">
        {{ persistenceNote }}
      </p>
    </main>

    <!-- Deliberately quiet: nothing is broken, there is simply a newer version. -->
    <div
      v-if="updateReady"
      class="fixed inset-x-0 bottom-[calc(3.75rem+env(safe-area-inset-bottom))] z-30 mx-auto flex max-w-xl
             items-center justify-between gap-3 px-4"
    >
      <div
        class="flex flex-1 items-center justify-between gap-3 rounded-xl bg-stone-800 px-3 py-2 text-xs
               font-medium text-stone-100 shadow-lg dark:bg-stone-200 dark:text-stone-900"
      >
        <!-- Two situations: housekeeping, or a page asking the server for files
             it no longer has — which is how a tap ends up doing nothing, so the
             user has to know the reload is not optional. -->
        <span>{{ updateUrgent ? 'This version is out of date.' : 'Updated in the background.' }}</span>
        <button type="button" class="rounded-lg bg-teal-600 px-2.5 py-1 font-semibold text-white" @click="reloadForUpdate">
          Reload
        </button>
      </div>
    </div>

    <nav
      class="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200/80 bg-stone-50/95 backdrop-blur
             pb-[env(safe-area-inset-bottom)] dark:border-stone-800/80 dark:bg-stone-950/95"
    >
      <div class="mx-auto flex max-w-xl">
        <Link
          href="/"
          class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium transition"
          :class="current === '/'
            ? 'text-teal-600 dark:text-teal-400'
            : 'text-stone-500 dark:text-stone-400'"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="4.5" width="18" height="16" rx="3" />
            <path d="M3 9.5h18M8 2.5v4M16 2.5v4" stroke-linecap="round" />
          </svg>
          Day
        </Link>

        <Link
          href="/trends"
          class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium transition"
          :class="current === '/trends'
            ? 'text-teal-600 dark:text-teal-400'
            : 'text-stone-500 dark:text-stone-400'"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M4 19V5M4 19h16" stroke-linecap="round" />
            <path d="M7.5 15.5l3.5-4 3 2.5 4.5-6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          Trends
        </Link>

        <!-- Supplements is reached from the day card instead — visited yearly,
             not every morning, which is the test for a permanent tap target. -->
        <Link
          href="/stress"
          class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium transition"
          :class="current === '/stress'
            ? 'text-teal-600 dark:text-teal-400'
            : 'text-stone-500 dark:text-stone-400'"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path
              d="M3 12.5h3.2l1.9-4.4 2.7 8.3 2.2-5.4 1.4 2.6h2.1"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <path d="M18.5 12.5a2.6 2.6 0 1 0 0-.2z" stroke-linecap="round" />
          </svg>
          Stress
        </Link>

        <!-- The fourth item, and this nav is now full. It passes the Stress
             test: opened on a Monday morning, and the one screen that reads
             everything else at once. `startsWith` rather than equality because
             a report has its own URL (/report/42) and the tab must stay lit. -->
        <Link
          href="/report"
          class="flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium transition"
          :class="current.startsWith('/report')
            ? 'text-teal-600 dark:text-teal-400'
            : 'text-stone-500 dark:text-stone-400'"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M6 3.5h8l4.5 4.5v12.5H6z" stroke-linejoin="round" />
            <path d="M13.5 3.5V8H18" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M9 12.5h6M9 16h4" stroke-linecap="round" />
          </svg>
          Report
        </Link>
      </div>
    </nav>
  </div>
</template>
