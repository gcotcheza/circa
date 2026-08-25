<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import AirDate from '../Components/AirDate.vue'
import ChipInput from '../Components/ChipInput.vue'
import { decimal } from '../lib/format'
import { useInlineValidation } from '../lib/validation/inline'

/**
 * The person, in their own words.
 *
 * NOT AN ACCOUNT SCREEN: no email, no password, nothing `users` holds (see
 * routes/web.php). Every box is reference data about a body, and it exists
 * because the written report had been giving nutritional suggestions to somebody
 * it knew nothing about.
 *
 * EVERY FIELD IS OPTIONAL. One box filled in is better than none — the report
 * uses what is there and says plainly what it could not frame. A form that
 * nagged would get answers that are plausible rather than true, and the report
 * cannot tell those apart.
 *
 * ONE SAVE, AT THE BOTTOM: sixteen per-field saves would be sixteen chances for
 * one to fail quietly, and nothing here is urgent. Deliberately NOT on the
 * offline queue — a replayed profile edit would be a full-row overwrite carrying
 * whatever was on screen when it was composed.
 *
 * NOT ONE NATIVE CONSTRAINT — no `<form>`, submit button, `required`, `min`,
 * `max`, `step`, nor a length ceiling the box enforces. DecimalInputTest documents why: a browser-enforced
 * constraint is one the browser can veto silently, on iOS without even drawing the
 * bubble — "nothing happens, and the page jumps". Numbers go through `decimal()`,
 * which reads the comma this phone's keypad types. Date of birth is a calendar
 * (Components/AirDate.vue) because nobody should type an ISO date on a phone;
 * it is not native either and carries NO `max` deliberately, since a greyed-out
 * day cannot say WHY and ProfileRequest's "that is in the future" can.
 *
 * A ceiling a box no longer enforces is one somebody can cross, so each box now
 * says ProfileRequest's own sentence as it is left (lib/validation/inline.js).
 * The date of birth is the exception with nothing to say: every rule on it is
 * PHP's date parsing and the app's clock, which a browser cannot answer for, so
 * it waits for the save.
 */
const props = defineProps({
  profile: { type: Object, required: true },
  // Height as the scale's own BMI already implies it — a hint, never an answer.
  // See ProfileController::heightImpliedByTheScale.
  impliedHeightCm: { type: Number, default: null },
})

const blank = (value) => (value === null || value === undefined ? '' : String(value))

const form = useForm({
  date_of_birth: blank(props.profile.dateOfBirth),
  sex: blank(props.profile.sex),
  height_cm: blank(props.profile.heightCm),
  ethnicity: blank(props.profile.ethnicity),
  country: blank(props.profile.country),

  goal: blank(props.profile.goal),
  target_weight_kg: blank(props.profile.targetWeightKg),
  goal_notes: blank(props.profile.goalNotes),

  dietary_preferences: blank(props.profile.dietaryPreferences),
  allergies_intolerances: blank(props.profile.allergiesIntolerances),

  smoking: blank(props.profile.smoking),
  alcohol: blank(props.profile.alcohol),
  sun_exposure: blank(props.profile.sunExposure),
  activity_context: blank(props.profile.activityContext),

  life_stage: blank(props.profile.lifeStage),
  health_notes: blank(props.profile.healthNotes),
})

/**
 * "160,5" from a Dutch keypad must arrive as 160.5, not NaN — JSON.stringify
 * turns NaN into null, silently discarding a number the user can see they typed.
 * ProfileRequest normalises the comma again, the endpoint having other callers.
 *
 * ALSO WHAT THE BLUR CHECK IS GIVEN, and that is the point of it being a named
 * function: the box holds "160,5" and the server is sent 160.5, so validating
 * what the box holds would refuse a number the save accepts — a browser
 * refusing what the server allows is the one direction this must never be
 * wrong in.
 */
function posted(data) {
  return {
    ...data,
    height_cm: decimal(data.height_cm),
    target_weight_kg: decimal(data.target_weight_kg),
  }
}

function save() {
  form.transform(posted).put('/profile', { preserveScroll: true })
}

/**
 * Change password — its own form, so a validation error on one never lands under
 * the other's boxes. A PLAIN Inertia PUT is the point: the offline queue
 * (../lib/queue) is opt-in via submitOrQueue()/enqueue() and this form calls
 * neither, because a password change is a security action that must reach the
 * server directly and must never be queued, deduped or replayed. Same shape as
 * logout in AppLayout; fields clear on success so the new password is not left
 * sitting in the page.
 */
const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

function changePassword() {
  passwordForm.put('/profile/password', {
    preserveScroll: true,
    onSuccess: () => {
      passwordForm.reset()
      password.reset()
    },
  })
}

const inline = useInlineValidation(form, 'ProfileRequest', { data: () => posted(form.data()) })

const password = useInlineValidation(passwordForm, 'PasswordUpdateRequest')

const inputClass =
  'w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2.5 py-2 text-base outline-none ' +
  'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25 dark:border-stone-700 dark:bg-stone-950'

const labelClass = 'text-xs font-medium text-stone-500 dark:text-stone-400'

const sectionClass = 'space-y-3 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'

const hintClass = 'mt-1 block text-[11px] leading-relaxed text-stone-500 dark:text-stone-400'
</script>

<template>
  <Head title="Profile" />

  <AppLayout title="Profile">
    <Link href="/" class="mb-3 inline-block text-xs font-medium text-teal-700 dark:text-teal-400">
      ← Back to the day
    </Link>

    <div class="space-y-4">
      <p class="rounded-2xl bg-stone-100 px-4 py-3 text-sm leading-relaxed text-stone-600 dark:bg-stone-900 dark:text-stone-300">
        This is the only screen in the app that is about you rather than about a
        day. The written report reads it so that what it suggests is framed for
        you — reference intakes differ by age and sex, vitamin D depends on where
        you live and what your skin does with the sun, and no suggestion should
        ever name a food you have ruled out.
        <span class="font-medium">Every box is optional.</span> Fill in what you
        want to; the report says plainly what it could not frame, and nothing
        here is ever guessed for you.
      </p>

      <!-- ============================ about you =========================== -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">About you</h2>

        <label class="block">
          <span :class="labelClass">Date of birth</span>
          <AirDate
            v-model="form.date_of_birth"
            format="d MMMM yyyy"
            placeholder="Pick a day"
            :class="[inputClass, 'mt-1 cursor-pointer']"
          />
          <span v-if="form.errors.date_of_birth" class="text-xs text-rose-600">{{ form.errors.date_of_birth }}</span>
          <span :class="hintClass">
            Stored as the date, not as an age, so it stays right after your
            birthday. A report about last February says the age you were then.
          </span>
        </label>

        <div class="block">
          <span :class="labelClass">Sex</span>
          <ChipInput
            v-model="form.sex"
            :options="['Female', 'Male']"
            placeholder="or type it in your own words"
            class="mt-1"
            @update:model-value="inline.clear('sex')"
            @blur="inline.check('sex')"
          />
          <span v-if="form.errors.sex" class="text-xs text-rose-600">{{ form.errors.sex }}</span>
          <span :class="hintClass">
            Asked because nutrition reference intakes differ by it — iron, folate
            and calcium most of all. It is used for that and nothing else.
          </span>
        </div>

        <label class="block">
          <span :class="labelClass">Height in centimetres</span>
          <input
            v-model="form.height_cm"
            type="text"
            inputmode="decimal"
            placeholder="160"
            :class="[inputClass, 'mt-1 tnum']"
            v-bind="inline.on('height_cm')"
          >
          <span v-if="form.errors.height_cm" class="text-xs text-rose-600">{{ form.errors.height_cm }}</span>
          <!-- A hint, never an answer: filling the box in would present the
               app's own arithmetic as something you told it. -->
          <span :class="hintClass">
            <template v-if="impliedHeightCm">
              Your scale sends a BMI with every weigh-in, and BMI plus weight is
              height — which works out at about {{ impliedHeightCm }} cm. It is
              deliberately not filled in: that would be the app stating a fact
              about your body that you never told it.
            </template>
            <template v-else>
              Used with your weigh-ins to work out a BMI. Nothing else needs it.
            </template>
          </span>
        </label>

        <label class="block">
          <span :class="labelClass">Ethnicity</span>
          <input
            v-model="form.ethnicity"
            type="text"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('ethnicity')"
          >
          <span v-if="form.errors.ethnicity" class="text-xs text-rose-600">{{ form.errors.ethnicity }}</span>
          <span :class="hintClass">
            Used only where it genuinely changes a nutritional reading: how much
            vitamin D your skin makes, whether lactose is likely to be a problem,
            and how BMI cut-offs read. The report is told never to mention it
            anywhere else.
          </span>
        </label>

        <label class="block">
          <span :class="labelClass">Country you live in</span>
          <input
            v-model="form.country"
            type="text"
            placeholder="Netherlands"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('country')"
          >
          <span v-if="form.errors.country" class="text-xs text-rose-600">{{ form.errors.country }}</span>
          <span :class="hintClass">
            Latitude, really. This far north the sun does not get high enough
            between October and March for your skin to make any vitamin D at all,
            whatever the weather is doing — which changes what a D3 supplement is
            for in the winter.
          </span>
        </label>
      </section>

      <!-- ============================== goals ============================= -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">What you are aiming at</h2>
        <p class="text-xs leading-relaxed text-stone-500 dark:text-stone-400">
          The report has always shown whether you ate more or less than you
          burned, and has had to leave it at that — the same 200 kcal is progress
          for one person and drift for another. This is what tells it which.
        </p>

        <div class="block">
          <span :class="labelClass">Goal</span>
          <ChipInput
            v-model="form.goal"
            :options="['Lose weight', 'Maintain', 'Gain muscle']"
            placeholder="or something else"
            class="mt-1"
            @update:model-value="inline.clear('goal')"
            @blur="inline.check('goal')"
          />
          <span v-if="form.errors.goal" class="text-xs text-rose-600">{{ form.errors.goal }}</span>
          <span :class="hintClass">
            Left empty, the report reads your energy balance neutrally and assumes
            nothing.
          </span>
        </div>

        <label class="block">
          <span :class="labelClass">Target weight in kilos</span>
          <input
            v-model="form.target_weight_kg"
            type="text"
            inputmode="decimal"
            placeholder="54"
            :class="[inputClass, 'mt-1 tnum']"
            v-bind="inline.on('target_weight_kg')"
          >
          <span v-if="form.errors.target_weight_kg" class="text-xs text-rose-600">{{ form.errors.target_weight_kg }}</span>
        </label>

        <label class="block">
          <span :class="labelClass">Anything about how</span>
          <textarea
            v-model="form.goal_notes"
            rows="2"
            placeholder="Slowly. No crash diets, no cutting out whole meals."
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('goal_notes')"
          />
          <span v-if="form.errors.goal_notes" class="text-xs text-rose-600">{{ form.errors.goal_notes }}</span>
        </label>
      </section>

      <!-- =============================== diet ============================= -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">How you eat</h2>

        <label class="block">
          <span :class="labelClass">Preferences</span>
          <textarea
            v-model="form.dietary_preferences"
            rows="2"
            placeholder="Vegetarian, halal, no breakfast, cannot stand fish…"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('dietary_preferences')"
          />
          <span v-if="form.errors.dietary_preferences" class="text-xs text-rose-600">{{ form.errors.dietary_preferences }}</span>
        </label>

        <label class="block">
          <span :class="labelClass">Allergies and intolerances</span>
          <textarea
            v-model="form.allergies_intolerances"
            rows="2"
            placeholder="Lactose, nuts, coeliac…"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('allergies_intolerances')"
          />
          <span v-if="form.errors.allergies_intolerances" class="text-xs text-rose-600">{{ form.errors.allergies_intolerances }}</span>
          <!-- A separate box on purpose: a preference is a choice and an
               intolerance is not, and the report reads this one as absolute. -->
          <span :class="hintClass">
            Kept separate from preferences, because they are not the same kind of
            thing. The report will never suggest a food that either box rules
            out.
          </span>
        </label>
      </section>

      <!-- ============================ lifestyle =========================== -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">Your days</h2>
        <p class="text-xs leading-relaxed text-stone-500 dark:text-stone-400">
          Context for things the watch can see but cannot explain. The report may
          use these to account for a pattern it found — never to lecture you
          about them.
        </p>

        <div class="block">
          <span :class="labelClass">Smoking</span>
          <ChipInput
            v-model="form.smoking"
            :options="['Never', 'Former', 'Current']"
            placeholder="or in your own words"
            class="mt-1"
            @update:model-value="inline.clear('smoking')"
            @blur="inline.check('smoking')"
          />
          <span v-if="form.errors.smoking" class="text-xs text-rose-600">{{ form.errors.smoking }}</span>
        </div>

        <label class="block">
          <span :class="labelClass">Alcohol</span>
          <input
            v-model="form.alcohol"
            type="text"
            placeholder="A glass of wine at weekends"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('alcohol')"
          >
          <span v-if="form.errors.alcohol" class="text-xs text-rose-600">{{ form.errors.alcohol }}</span>
          <span :class="hintClass">
            The pattern rather than a count. Alcohol flattens overnight recovery
            and breaks up sleep, both of which this app measures — so a dip after
            a Saturday night can be explained instead of left a mystery.
          </span>
        </label>

        <div class="block">
          <span :class="labelClass">Sun you actually get</span>
          <ChipInput
            v-model="form.sun_exposure"
            :options="['Low', 'Moderate', 'High']"
            placeholder="or in your own words"
            class="mt-1"
            @update:model-value="inline.clear('sun_exposure')"
            @blur="inline.check('sun_exposure')"
          />
          <span v-if="form.errors.sun_exposure" class="text-xs text-rose-600">{{ form.errors.sun_exposure }}</span>
        </div>

        <label class="block">
          <span :class="labelClass">What your day is like</span>
          <textarea
            v-model="form.activity_context"
            rows="2"
            placeholder="Desk job, cycle to the shops, on my feet at weekends"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('activity_context')"
          />
          <span v-if="form.errors.activity_context" class="text-xs text-rose-600">{{ form.errors.activity_context }}</span>
          <span :class="hintClass">
            The watch counts steps. It has no idea whether four thousand of them
            was a rest day or a Tuesday at a desk.
          </span>
        </label>
      </section>

      <!-- ========================= health context ========================= -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">Health context</h2>

        <label class="block">
          <span :class="labelClass">Life stage</span>
          <input
            v-model="form.life_stage"
            type="text"
            placeholder="Pregnant, breastfeeding, menopause…"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('life_stage')"
          >
          <span v-if="form.errors.life_stage" class="text-xs text-rose-600">{{ form.errors.life_stage }}</span>
          <span :class="hintClass">
            Several reference intakes move a long way on this and on nothing
            else, which is why it has its own box.
          </span>
        </label>

        <label class="block">
          <span :class="labelClass">Anything you want the report to be aware of</span>
          <textarea
            v-model="form.health_notes"
            rows="3"
            placeholder="Conditions or medications you would rather it knew about"
            :class="[inputClass, 'mt-1']"
            v-bind="inline.on('health_notes')"
          />
          <span v-if="form.errors.health_notes" class="text-xs text-rose-600">{{ form.errors.health_notes }}</span>
        </label>

        <!-- Unconditional, like the report tab's disclaimer: a medication may be
             written here, so the limits belong next to where it is typed. -->
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
          The report may mention that something here interacts with something in
          your data — worth raising with whoever prescribed it — and that is all.
          It will not name a dose, tell you to start or stop anything, diagnose you,
          or argue with a doctor who has actually examined you.
          It is not medical advice and it never was.
          Leaving this empty means it assumes nothing.
        </p>
      </section>

      <button
        type="button"
        class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99] disabled:opacity-60"
        :disabled="form.processing"
        @click="save"
      >
        {{ form.processing ? 'Saving…' : 'Save' }}
      </button>

      <p class="px-1 pb-2 text-[11px] leading-relaxed text-stone-400 dark:text-stone-600">
        This never leaves your server except as part of a report you asked for.
        Clearing a box and saving removes it — the report then goes back to
        framing that part generally.
      </p>

      <!-- ========================= change password ======================= -->
      <!-- The only boxes in the app that touch `users`. Queue-free by design
           (see changePassword); the autocomplete hints are what let iOS offer to
           save the new password to the keychain. -->
      <section :class="sectionClass">
        <h2 class="text-sm font-semibold">Change password</h2>
        <p class="text-xs leading-relaxed text-stone-500 dark:text-stone-400">
          You need your current password to set a new one — there is no reset.
          Changing it signs every other device out.
        </p>

        <label class="block">
          <span :class="labelClass">Current password</span>
          <input
            v-model="passwordForm.current_password"
            type="password"
            autocomplete="current-password"
            :class="[inputClass, 'mt-1']"
            v-bind="password.on('current_password')"
          >
          <span v-if="passwordForm.errors.current_password" class="text-xs text-rose-600">{{ passwordForm.errors.current_password }}</span>
        </label>

        <label class="block">
          <span :class="labelClass">New password</span>
          <input
            v-model="passwordForm.password"
            type="password"
            autocomplete="new-password"
            :class="[inputClass, 'mt-1']"
            v-bind="password.on('password')"
          >
          <span v-if="passwordForm.errors.password" class="text-xs text-rose-600">{{ passwordForm.errors.password }}</span>
          <span :class="hintClass">
            At least twelve characters, and not one that has turned up in a known
            breach. Your phone can generate one and remember it for you.
          </span>
        </label>

        <!-- Filed under `password`, because that is the field PasswordUpdateRequest
             refuses: `confirmed` reads this box but the sentence is about the one
             above, and a refusal after Save lands there too. -->
        <label class="block">
          <span :class="labelClass">Confirm new password</span>
          <input
            v-model="passwordForm.password_confirmation"
            type="password"
            autocomplete="new-password"
            :class="[inputClass, 'mt-1']"
            v-bind="password.on('password')"
          >
        </label>

        <button
          type="button"
          class="w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white active:scale-[0.99] disabled:opacity-60"
          :disabled="passwordForm.processing"
          @click="changePassword"
        >
          {{ passwordForm.processing ? 'Changing…' : 'Change password' }}
        </button>
      </section>
    </div>
  </AppLayout>
</template>
