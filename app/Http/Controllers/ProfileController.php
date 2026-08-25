<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\Profile;
use App\Models\HealthMetric;
use App\Http\Requests\ProfileRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The one screen that is about the person rather than about a day.
 *
 * The link sits in the page header beside Sign out rather than in the bottom
 * nav: four items is what a thumb reaches on a 6.7-inch screen, and Day /
 * Trends / Stress / Report are all opened repeatedly, while a profile is opened
 * once and revisited only when something about the person changes — the same
 * test the supplements screen failed.
 *
 * ONE FORM, ONE SAVE. Per-field saves would turn sixteen boxes filled in over
 * one sitting into sixteen writes and sixteen chances for one to fail silently,
 * and nothing here is urgent enough to need saving the instant a box loses
 * focus. It is deliberately NOT on the offline queue either — that queue exists
 * for things logged in the moment, a meal or a supplement tick, where the radio
 * being down must not cost the record. A queued profile edit would be a
 * replayable full-row overwrite carrying stale values from whenever it was
 * composed.
 */
final class ProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = Profile::current();

        return Inertia::render('Profile', [
            'profile' => [
                'dateOfBirth' => $profile->date_of_birth?->toDateString(),
                'sex'         => $profile->sex,
                'heightCm'    => $profile->heightCm(),
                'ethnicity'   => $profile->ethnicity,
                'country'     => $profile->country,

                'goal'           => $profile->goal,
                'targetWeightKg' => $profile->targetWeightKg(),
                'goalNotes'      => $profile->goal_notes,

                'dietaryPreferences'    => $profile->dietary_preferences,
                'allergiesIntolerances' => $profile->allergies_intolerances,

                'smoking'         => $profile->smoking,
                'alcohol'         => $profile->alcohol,
                'sunExposure'     => $profile->sun_exposure,
                'activityContext' => $profile->activity_context,

                'lifeStage'   => $profile->life_stage,
                'healthNotes' => $profile->health_notes,
            ],

            /*
             * THE HINT THAT IS NOT AN ANSWER. The scale sends a BMI with every
             * weigh-in, and BMI plus weight is height, so the app can work out
             * roughly how tall its user is. It says so next to the empty box
             * and stops there: filling the box in would be the app stating a
             * fact derived from a consumer scale's arithmetic and presenting it
             * as something the user told it. That distinction is the basis of
             * the snapshot the report is written from, and does not relax for a
             * convenience.
             */
            'impliedHeightCm' => $this->heightImpliedByTheScale(),
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        Profile::put($request->profileAttributes());

        return to_route('profile.edit')->with('success', 'Saved.');
    }

    /**
     * Height in centimetres as the scale's own numbers imply it, or null.
     *
     * BMI = kg / m², so m = sqrt(kg / BMI). Both readings come from the SAME
     * local day, since this morning's weight against last month's BMI mixes two
     * bodies; rounded to the nearest centimetre, because a consumer scale
     * publishes BMI to one decimal and that is worth about a centimetre.
     */
    private function heightImpliedByTheScale(): ?int
    {
        $bmi = HealthMetric::query()
            ->where('metric', 'body_mass_index')
            ->orderByDesc('local_date')
            ->orderByDesc('started_at')
            ->first();

        if ($bmi === null) {
            return null;
        }

        $weight = HealthMetric::query()
            ->where('metric', 'weight_body_mass')
            ->where('local_date', $bmi->local_date->toDateString())
            ->orderByDesc('started_at')
            ->first();

        if ($weight === null) {
            return null;
        }

        $index = (float) $bmi->value;
        $kg = (float) $weight->value;

        if ($index <= 0.0 || $kg <= 0.0) {
            return null;
        }

        return (int) round(sqrt($kg / $index) * 100);
    }
}
