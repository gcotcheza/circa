<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import DateJump from '../Components/DateJump.vue'
import StressScoreCard from '../Components/StressScoreCard.vue'
import StressTiles from '../Components/StressTiles.vue'
import StressWeekChart from '../Components/StressWeekChart.vue'
import StressDigest from '../Components/StressDigest.vue'
import StressHeatmap from '../Components/StressHeatmap.vue'
import { bandSwatchStyle } from '../lib/stress'

/**
 * The stress monitor. Three views, in the order the questions get asked: how am
 * I today, how has this week gone, what does my week normally look like. No
 * gauge or trend arrow — the score is a measurement with a real error bar, drawn
 * as a dot beside its band with a sentence about the data behind it.
 */
const props = defineProps({
  today: Object,
  yesterday: Object,
  week: Object,
  // The two unit-carrying tiles beside the score. They follow TODAY, not
  // ?week=: one of them is by definition the latest resting heart rate.
  hrv7: Object,
  restingHr: Object,
  // The analysis of the week being viewed. This one does follow ?week=.
  digest: Object,
  // Two grids of 168 hours, either of which can be null on its own gate: the
  // week being viewed hour by hour, and ninety days folded into a typical week.
  weekGrid: Object,
  heatmap: Object,
  bands: Array,
  scale: Object,
  baseline: Object,
  circadian: Object,
  methodVersion: String,
  // Inertia binds page props by the payload's own key, so this must stay spelled
  // as StressView sends it — the only snake_case prop in Pages/. Renaming it is
  // a server-payload change (StressView.php), not a lint fix; waived HERE only.
  // eslint-disable-next-line vue/prop-name-casing -- must match the server payload key; see above
  today_date: String,
})

const scoredThisWeek = computed(() => props.week.days.filter((d) => d.score !== null).length)

const pastDays = computed(() => props.week.days.filter((d) => !d.isFuture).length)

/** Any date inside the week you want: `?week=` has always meant that (see
 * StressController), so the picker can hand the server a Wednesday. */
function goToWeek(date) {
  router.get('/stress', { week: date }, { preserveScroll: true })
}
</script>

<template>
  <Head title="Stress" />

  <AppLayout title="Stress">
    <div class="space-y-4">
      <StressScoreCard :today="today" :yesterday="yesterday" :baseline="baseline" :bands="bands" />

      <!-- The score is unitless by construction; these are the Watch's own units,
           so the card above can be checked against something outside this app. -->
      <StressTiles :hrv7="hrv7" :resting-hr="restingHr" :today-date="today_date" />

      <!-- WEEK NAVIGATION, THE SAME ROW AS THE DAY VIEW'S -------------------
           Chevron, date, shortcut, chevron: both browsable screens work alike.
           The words "Earlier" and "Later" came out when the shortcut and the
           year went in — that row is 378 px of content in a 343 px column on the
           narrowest iPhone, and flexbox answers by wrapping "Earlier" onto two
           lines. Chevrons are 36 px and the row fits with the year on.
           `[1fr_auto_1fr]` as on the day view: the sides are different widths,
           so space-between would put the date off centre. -->
      <div class="grid grid-cols-[1fr_auto_1fr] items-center">
        <Link
          :href="`/stress?week=${week.previous}`"
          preserve-scroll
          class="justify-self-start rounded-full p-2 text-stone-500 active:scale-90 dark:text-stone-400"
          aria-label="Earlier week"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M15 5l-7 7 7 7" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </Link>

        <!-- Opens on the Monday; the server resolves any day to its week. -->
        <DateJump
          :date="week.start"
          :label="week.label"
          :min="week.earliest"
          :max="today_date"
          :hint="week.isCurrent ? 'this week' : ''"
          aria-label="Jump to a week"
          @jump="goToWeek"
        />

        <div class="flex items-center justify-self-end">
          <!-- "This week" rather than "Today", because it lands on a week. -->
          <Link
            v-if="!week.isCurrent"
            href="/stress"
            preserve-scroll
            class="rounded-lg px-2 py-1 text-sm font-medium text-teal-600 transition
                   hover:bg-stone-200/60 active:scale-95 dark:text-teal-400 dark:hover:bg-stone-800/60"
          >
            This week
          </Link>
          <span v-else class="px-2 py-1 text-sm text-transparent select-none" aria-hidden="true">This week</span>

          <Link
            v-if="week.next"
            :href="`/stress?week=${week.next}`"
            preserve-scroll
            class="rounded-full p-2 text-stone-500 active:scale-90 dark:text-stone-400"
            aria-label="Later week"
          >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </Link>
          <!-- Not a disabled button — a control that cannot do anything is one
               somebody keeps pressing. It still holds its width. -->
          <span v-else class="size-9" aria-hidden="true" />
        </div>
      </div>

      <!-- THE TWO PICTURES, BACK TO BACK ---------------------------------
           The week's line and the hour grid are what the page is opened for, so
           both are within one thumb-scroll. Everything that has to be READ sits
           below, in the order somebody digs. -->
      <StressWeekChart :days="week.days" :bands="bands" />

      <!-- Stays with the chart it is the denominator for. -->
      <p class="px-1 text-xs leading-relaxed text-stone-500 dark:text-stone-400">
        {{ scoredThisWeek }} of {{ pastDays }} day{{ pastDays === 1 ? '' : 's' }} in this week could be
        scored. A day needs at least {{ baseline.minDaySamples }} HRV readings of its own and
        {{ baseline.minDays }} days of history behind it.
      </p>

      <StressHeatmap
        v-if="weekGrid || heatmap"
        :week-grid="weekGrid"
        :heatmap="heatmap"
        :week-label="week.label"
      />

      <p v-else class="rounded-2xl border border-stone-200 bg-white p-4 text-xs leading-relaxed text-stone-500 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">
        The hour-of-the-week grid needs {{ baseline.minDays }} days of readings before it can say
        anything. It will appear on its own.
      </p>

      <StressDigest :digest="digest" :week-label="week.label" />

      <!-- Always in words: the Pay-attention/Great pair is close enough for some
           colour-vision types that the label must carry the distinction. -->
      <section class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <h3 class="text-sm font-semibold">What the bands mean</h3>
        <ul class="mt-2 space-y-1.5">
          <li v-for="band in bands" :key="band.band" class="flex items-start gap-2">
            <span
              class="mt-1 size-2.5 shrink-0 rounded-sm"
              :style="bandSwatchStyle(band.band)"
              aria-hidden="true"
            />
            <p class="text-[11px] leading-relaxed text-stone-600 dark:text-stone-300">
              <span class="tnum font-semibold text-stone-800 dark:text-stone-100">
                {{ band.from }}–{{ band.to }} {{ band.label }}
              </span>
              — {{ band.meaning }}
            </p>
          </li>
        </ul>
      </section>

      <!-- A score somebody cannot interrogate is one they stop believing —
           which is what happened to the app this replaces. -->
      <details class="rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <summary class="cursor-pointer text-sm font-semibold">How this score is worked out</summary>

        <div class="mt-2 space-y-2 text-[11px] leading-relaxed text-stone-600 dark:text-stone-300">
          <p v-if="circadian.peakHour !== null">
            Every HRV reading is compared with what your HRV normally is
            <em>at that hour of the day</em>. Over the last {{ circadian.days }} days your own
            rhythm swings about {{ circadian.swingPercent }}% between its highest hour
            (<span class="tnum">{{ String(circadian.peakHour).padStart(2, '0') }}:00</span>) and its
            lowest (<span class="tnum">{{ String(circadian.troughHour).padStart(2, '0') }}:00</span>).
            Removing that is what stops the app telling you that you are calm every night and
            stressed every evening.
          </p>

          <!-- With no rhythm measured yet, no correction is applied — saying so
               beats quietly scoring against an unestablished shape. -->
          <p v-else>
            Every HRV reading is compared with what your HRV normally is
            <em>at that hour of the day</em> — but there are not yet enough readings to establish
            that daily rhythm, so nothing is being subtracted for it. Overnight readings will look
            better than evening ones until there are.
          </p>

          <p>
            What is left is the day's own level. It is measured against the middle of your last
            {{ baseline.days }} days and divided by how much those days usually vary.
          </p>

          <p>
            A day exactly on your baseline scores {{ scale.baselineScore }}. About
            {{ scale.greatZ }} of your own standard deviations above it is where Great starts. The
            scale runs {{ scale.min }}–{{ scale.max }} and higher always means less strain.
          </p>

          <p class="text-stone-500 dark:text-stone-400">
            A day is only scored with at least {{ baseline.minDaySamples }} readings;
            {{ baseline.highSamples }}+ readings is called high confidence and fewer than
            {{ baseline.mediumSamples }} is called low. Nothing is ever interpolated — method
            {{ methodVersion }}.
          </p>
        </div>
      </details>
    </div>
  </AppLayout>
</template>
