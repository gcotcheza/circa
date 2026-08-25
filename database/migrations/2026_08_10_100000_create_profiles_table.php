<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The person the rest of this database is about.
 *
 * WHY THIS EXISTS: the written report gave nutritional suggestions to
 * somebody it knew nothing about. Reference intakes are a function of sex
 * and age first; pregnancy/breastfeeding move several by more than diet
 * does; vitamin D synthesis depends on skin tone, latitude and sun exposure
 * (at 52°N, effectively none between October and March); and "eat more oily
 * fish" is useless to a vegetarian and dangerous to someone with a fish
 * allergy. None of that can be inferred from a weigh-in, so it's asked for
 * once and stored here.
 *
 * WHY A TABLE, NOT COLUMNS ON `users`: `users` is the AUTHENTICATION table
 * (email, bcrypt hash, remember token), written only by a terminal seeder.
 * Hanging user-editable columns off it puts a form — mass assignment,
 * validation, a PUT any session can reach — next to the password, the kind
 * of adjacency that turns one careless `$guarded = []` into an account
 * takeover. This table follows the rest of the schema's shape (no user_id
 * anywhere — one user, one row) and is a SINGLETON, found by
 * `Profile::current()`. See the model for how that's enforced.
 *
 * EVERY COLUMN IS NULLABLE, NOTHING IS SEEDED: the user fills in what they
 * want, in whatever order, and a report generated with six fields blank must
 * be as honest as one with all sixteen filled — null is not a zero, it's a
 * thing the app doesn't know. One temptation is refused on purpose: this app
 * already has `body_mass_index` and `weight_body_mass`, so height IS
 * derivable — but a derived height silently becoming a stated fact is
 * exactly the confident invention this codebase avoids. The screen says the
 * number is derivable and leaves the typing to the person it's about.
 *
 * WHY SO MUCH IS FREE TEXT: the alternative is a dropdown wrong for
 * somebody. Ethnicity, life stage, diet and drinking patterns have no
 * canonical enumeration that survives contact with a real person. Columns
 * that read like enums (`sex`, `goal`, `smoking`, `sun_exposure`) are text
 * offered as taps — the same pattern `supplement_nutrients` uses for what
 * the bottle prints. The consumer is a language model reading prose; it
 * doesn't need an integer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();

            // ---------------------------------------------------------------
            // ABOUT YOU — the four that reference intakes are actually keyed on
            // ---------------------------------------------------------------

            /*
             * The date, not the age — an age stored as a number is wrong on
             * the next birthday. The report computes whole years at the END
             * of the range it covers, so a report about last February says
             * the age they were then.
             */
            $table->date('date_of_birth')->nullable();

            /*
             * FOR NUTRITION REFERENCE RANGES, labelled that way on screen.
             * Text, not an enum: the honest options are "female", "male",
             * and "none of the above, here is what to use" — the third is a
             * sentence. The UI offers the first two as taps into this box.
             */
            $table->string('sex', 40)->nullable();

            // decimal(5,1), not float: 160.5 must come back as 160.5 on a
            // screen someone is checking, and BMI is computed from it.
            $table->decimal('height_cm', 5, 1)->nullable();

            /*
             * Relevant to a narrow set of things: vitamin D synthesis at
             * northern latitudes, lactase persistence, and BMI cut-offs
             * derived on European populations reading differently
             * elsewhere. The prompt is told to use it only for those.
             */
            $table->string('ethnicity', 120)->nullable();

            /*
             * Latitude by proxy, and it matters: in the Netherlands the sun
             * never gets high enough between October and March for the skin
             * to make any vitamin D — the difference between "D3 is topping
             * up a summer level" and "D3 IS the supply".
             */
            $table->string('country', 120)->nullable();

            // ---------------------------------------------------------------
            // GOALS — the block that changes what a number MEANS
            // ---------------------------------------------------------------

            /*
             * THE ONE FIELD THAT RE-READS A NUMBER THE APP ALREADY HAD.
             * `energy.balance` has always been reported neutrally (burn
             * minus intake) because the app had no idea whether a deficit
             * was the plan or an accident — 200 kcal under maintenance is
             * progress to someone losing weight, drift to someone
             * maintaining, a stalled week to someone adding muscle. Text,
             * with the three common answers offered as taps.
             */
            $table->string('goal', 60)->nullable();

            // What they're aiming at, if there's a number. The report gets
            // the gap to it rather than being asked to subtract.
            $table->decimal('target_weight_kg', 5, 1)->nullable();

            // The part a number can't hold: how fast, why, and what they've
            // already decided not to do about it.
            $table->text('goal_notes')->nullable();

            // ---------------------------------------------------------------
            // DIET — a preference and a constraint are not the same field
            // ---------------------------------------------------------------

            // Vegetarian, vegan, halal, "no breakfast", "I don't like
            // fish" — choices. A suggestion that ignores one is useless.
            $table->text('dietary_preferences')->nullable();

            /*
             * KEPT SEPARATE ON PURPOSE. Lactose intolerance, coeliac, a nut
             * allergy are not preferences — a report that tidied them into
             * the same box as "prefers oat milk" would be one edit away
             * from equal weight. The prompt reads this one as an absolute.
             */
            $table->text('allergies_intolerances')->nullable();

            // ---------------------------------------------------------------
            // LIFESTYLE — context for patterns the watch can see but not explain
            // ---------------------------------------------------------------

            /*
             * never / former / current, or a sentence. Offered as taps.
             * Here to EXPLAIN, never lecture: the prompt gets one gentle
             * factual note at most, only where the data shows something —
             * a moralising health report is one nobody opens twice.
             */
            $table->string('smoking', 60)->nullable();

            /*
             * The pattern, not a count: "wine at weekends", "a beer most
             * evenings", "none". Alcohol suppresses overnight HRV and
             * fragments sleep, both measured here — so a Saturday recovery
             * dip has an explanation instead of being unexplained.
             */
            $table->string('alcohol', 200)->nullable();

            // low / moderate / high. Half of the vitamin D question; the
            // other halves are `country`, `ethnicity` and the time of year.
            $table->string('sun_exposure', 40)->nullable();

            /*
             * "Desk job", "on my feet all day", "cycle everywhere". The
             * watch counts steps/kcal but can't tell a 4,000-step rest day
             * from a Tuesday at a desk — NEAT is most of the difference
             * between two people with the same workouts.
             */
            $table->text('activity_context')->nullable();

            // ---------------------------------------------------------------
            // HEALTH CONTEXT — the most careful block in the app
            // ---------------------------------------------------------------

            /*
             * Pregnancy, breastfeeding, menopause, recovering from illness.
             * Several reference intakes move substantially on this alone,
             * which is why it's asked separately from `health_notes`.
             */
            $table->string('life_stage', 120)->nullable();

            /*
             * ANYTHING THEY WANT THE REPORT TO BE AWARE OF — conditions,
             * medications, a diagnosis they're managing.
             *
             * THIS COLUMN COMES WITH A RULE, AND THE RULE IS IN THE PROMPT.
             * It exists because context changes what's worth noticing:
             * metformin and long-term PPIs affect B12, several diuretics
             * affect magnesium, and this app tracks exactly those off
             * supplement labels in exact figures.
             *
             * It is NOT permission to practise medicine. The prompt's
             * standing ban on naming a medication, a dose to change, a
             * condition or a test to request is unchanged: what's allowed
             * is gentle awareness ("worth raising with whoever prescribed
             * it"), what's forbidden is dosing, diagnosis, and contradicting
             * a clinician who has examined them. An empty box means no
             * assumptions, not a clean bill of health.
             */
            $table->text('health_notes')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
