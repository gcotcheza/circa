<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Meal;
use App\Models\Workout;
use App\Models\MealItem;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\SleepSession;
use App\Models\RawIngestPayload;
use Illuminate\Support\Collection;
use App\Services\Memory\MemoryRanker;
use App\Services\Rollup\SourcePriority;
use App\Services\Rollup\IntakeCalculator;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Supplements\SupplementDay;

/**
 * Everything the daily view renders, for one local date.
 *
 * One class rather than a fat controller: the same shape is wanted from three
 * directions (page load, post-save redirect, tests), and "what does a day
 * look like?" is a domain question, not an HTTP one. Ranges stay ranges all
 * the way to the props — nothing collapses a band to its midpoint, since a
 * number that quietly lost its error bars looks exactly like one that never
 * had any, and the front end decides how to draw the uncertainty.
 */
final class DailyView
{
    public function __construct(
        private readonly IntakeCalculator $intake = new IntakeCalculator,
        private readonly SummaryRebuilder $rebuilder = new SummaryRebuilder,
        private readonly SourcePriority $priority = new SourcePriority,
        private readonly MemoryRanker $memory = new MemoryRanker,
        private readonly TdeeCard $tdee = new TdeeCard,
        private readonly SupplementDay $supplements = new SupplementDay,
        private readonly HistorySpan $history = new HistorySpan,
        private readonly DayPace $pace = new DayPace,
        private readonly NightCoverage $night = new NightCoverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(string $date): array
    {
        $tz = (string) config('health.timezone');
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        $today = CarbonImmutable::now($tz)->startOfDay();

        // A day with no summary row hasn't been rolled up yet (fresh deploy,
        // a date the backfill missed) — built on demand, with the same
        // builder the job uses.
        $summary = DailySummary::query()->find($day->toDateString())
            ?? $this->rebuilder->now($day->toDateString());

        // `latestVisionRequest`, not the whole audit history: the screen
        // only asks why the last attempt failed, and loading every
        // re-analysis would make this query grow with user persistence.
        $meals = Meal::query()
            ->onLocalDate($day->toDateString())
            ->with(['items.mealPhoto', 'photos.latestVisionRequest', 'latestVisionRequest', 'memoryMatch'])
            ->orderBy('eaten_at')
            ->get();

        return [
            'date'     => $day->toDateString(),
            'label'    => $day->isoFormat('ddd D MMM'),
            'previous' => $day->subDay()->toDateString(),
            /*
             * Null on today, since there's nowhere forward to go. The arrow
             * has long been `:disabled="isToday"`; this closes the same gap
             * for the left-SWIPE, which has no disabled state and was
             * loading an empty tomorrow, resetting every strip on the page.
             */
            'next'     => $day->equalTo($today) ? null : $day->addDay()->toDateString(),
            'isToday'  => $day->equalTo($today),
            'isFuture' => $day->greaterThan($today),
            'today'    => $today->toDateString(),

            /*
             * The floor of the date picker — the first day this database has
             * anything for. The arrows walk one day at a time, fine for
             * "yesterday" and useless for "in March"; `earliest` and `today`
             * clamp the picker to the history that actually exists. Null on
             * an empty database: see HistorySpan.
             */
            'earliest' => $this->history->day(),

            // `isToday` travels with it since "so far" describes the DAY,
            // not the summary row, on a day whose burn hasn't finished
            // accruing.
            'summary' => $this->summaryProps($summary, $day->equalTo($today)),
            'meals'   => $meals->map(fn (Meal $meal): array => $this->mealProps($meal))->all(),
            'sleep'   => $this->sleepProps($day->toDateString()),

            /*
             * What was TRAINED on this day, one entry per session — a list,
             * not a total, deliberately not folded into `summary`: a
             * session's active energy is already inside the day's active
             * energy (see workoutProps), so this only labels time already
             * measured.
             */
            'workouts' => $this->workoutProps($day->toDateString()),

            /*
             * The supplements card (tier 1): what's on the shelf, what's
             * been ticked FOR THIS DATE, and whether the card wants
             * attention. Built from `$date` like everything else here, so a
             * past day shows what was actually taken and its taps still
             * work — "I forgot to tick last night's" is fixable, not a hole
             * in the record.
             */
            'supplements' => $this->supplements->props($day->toDateString()),

            /*
             * Two quick-add lists; the picker prefers the first (step 6).
             * `frequentMeals` is meal_memory ranked by frecency, the real
             * answer that improves with use. `recentMeals` is step 3's
             * derived-on-the-fly list and stays as the fallback, since
             * memory starts EMPTY on a fresh deploy — seeding it would
             * invent history, and showing nothing for the first week would
             * regress a feature that already worked.
             */
            'frequentMeals' => $this->frequentMeals(),
            'recentMeals'   => $this->recentMeals(),

            // So the review screen can say how long photos are kept without
            // hard-coding a number the config owns.
            'photoRetentionDays' => (int) config('health.vision.photo_retention_days'),

            /*
             * One line of step 7, only once there's something to say. Null
             * below the gate — "collecting data" progress belongs on the
             * trends page, told once properly rather than repeated under
             * every day's balance as nagging.
             */
            'tdee' => $this->tdee->compact(),

            // Step 8's last-sync indicator.
            'ingest' => $this->ingestProps(),
        ];
    }

    /**
     * How long ago the phone last exported anything. A prop, not a fetch
     * to /api/health: the age must be against the SERVER's clock (client
     * side would hide drift in the direction that matters), it's one
     * indexed read versus a second mobile-data request, and /api/health is
     * deliberately unauthenticated for uptime checks, so depending on it
     * ties a feature to an endpoint that promises to reveal nothing.
     * Ordered by `id`, not `received_at`: the primary key, monotonic,
     * needs no index of its own.
     *
     * @return array<string, mixed>
     */
    private function ingestProps(): array
    {
        $lastReceivedAt = RawIngestPayload::query()
            ->orderByDesc('id')
            ->value('received_at');

        $threshold = (int) config('health.ingest.stale_after_hours');

        $ageHours = $lastReceivedAt === null
            ? null
            : round(CarbonImmutable::parse($lastReceivedAt)->diffInSeconds(CarbonImmutable::now()) / 3600, 2);

        return [
            'lastPayloadAt' => $lastReceivedAt === null
                ? null
                : CarbonImmutable::parse($lastReceivedAt)->toIso8601String(),

            'ageHours' => $ageHours,

            // NEVER having received anything is stale by definition — a
            // fresh deploy whose HAE automation was never pointed at it
            // must not look like a healthy app with nothing to show.
            'stale' => $ageHours === null || $ageHours > $threshold,

            'thresholdHours' => $threshold,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryProps(DailySummary $summary, bool $isToday = false): array
    {
        $active = $summary->active_kcal === null ? null : (float) $summary->active_kcal;
        $resting = $summary->resting_kcal === null ? null : (float) $summary->resting_kcal;

        return [
            'kcalIn' => [
                'min' => $this->number($summary->kcal_in_min),
                'mid' => $this->number($summary->kcal_in_mid),
                'max' => $this->number($summary->kcal_in_max),
            ],
            'protein' => $this->macro($summary, 'protein_g'),
            'carbs'   => $this->macro($summary, 'carbs_g'),
            'fat'     => $this->macro($summary, 'fat_g'),

            'activeKcal'  => $active,
            'restingKcal' => $resting,
            'kcalOut'     => $summary->totalKcalOut(),

            /*
             * Burn − intake, reduced to what a screen can render: a
             * direction, two unsigned magnitudes, and the signed band behind
             * them (App\Services\Reporting\EnergyBalance). The card used to
             * subtract and print the signed band itself, coming out as
             * "-143–-49 kcal" — correct and unreadable, and untestable in a
             * Vue component with no JS test runner. Null when either half is
             * missing, since the card has a dedicated sentence for that case
             * and must not get a balance derived from one side alone.
             */
            'balance' => EnergyBalance::from(
                kcalOut: $summary->totalKcalOut(),
                kcalInMin: $this->number($summary->kcal_in_min),
                kcalInMid: $this->number($summary->kcal_in_mid),
                kcalInMax: $this->number($summary->kcal_in_max),
                provisional: $isToday,
            )?->toArray(),

            /*
             * Same subtraction with the rest of today's resting burn added
             * to the burn side — where the day is HEADING, not where it
             * stands (App\Services\Reporting\DayPace). Today only; null
             * without enough measured history, so a finished day's balance
             * is unchanged with this key null beside it.
             */
            'projection' => $isToday ? $this->pace->project($summary) : null,

            'steps'           => $this->number($summary->steps),
            'exerciseMinutes' => $this->number($summary->exercise_minutes),
            'distanceKm'      => $this->number($summary->distance_km),
            'weightKg'        => $this->number($summary->weight_kg),

            'flags' => [
                'isCompleteLog'         => $summary->is_complete_log,
                'hasFullMetricCoverage' => $summary->has_full_metric_coverage,
                'activeKcalIsPartial'   => $summary->active_kcal_is_partial,
                'activeKcalCoverage'    => $this->number($summary->active_kcal_coverage),
            ],

            'rebuiltAt' => $summary->rebuilt_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mealProps(Meal $meal): array
    {
        /** @var Collection<int, MealItem> $items */
        $items = $meal->items;

        /** @var Collection<int, MealPhoto> $photos */
        $photos = $meal->photos;

        /*
         * The card's number is the CONFIRMED number. A confirmed meal can
         * now carry an unconfirmed proposal (dessert photographed after the
         * main course, both on the same meal); banding all items together
         * would put the dessert in the card's total before the user agreed
         * to it, disagreeing with the day's own confirmed-only total.
         * Before anything is confirmed, the proposal is what the card
         * bands — the "review this" state, explicitly a proposal.
         */
        $confirmed = $items->filter(static fn (MealItem $item): bool => $item->confirmed_at !== null);

        $band = $this->intake->kcalBand($confirmed->isEmpty() ? $items : $confirmed);

        return [
            'uuid'    => $meal->uuid,
            'eatenAt' => $meal->eaten_at->toIso8601String(),
            'time'    => $meal->eaten_at
                ->setTimezone((string) config('health.timezone'))
                ->format('H:i'),
            'mealType' => $meal->meal_type?->value,
            'status'   => $meal->status->value,
            'source'   => $meal->source->value,
            // Two speakers, two props: `notes` is the user's, editable
            // everywhere; `modelNotes` is the model's own account,
            // read-only. Merging them once put "Split the single line into
            // its five components…" in the user's Notes box — see
            // ProposalWriter::propose(). `modelNotes` no longer shows on
            // the day's card (it buried the food list), but stays sent
            // since Day.vue also hands this object to MealSheet and
            // ProposalReview, which still show it.
            'notes'      => $meal->notes,
            'modelNotes' => $meal->model_notes,
            'kcal'       => $band->toArray(0),
            'items'      => $items->map(fn (MealItem $item): array => $this->itemProps($item))->values()->all(),

            /*
             * The plates (step 10): which card is waiting, analysing, or
             * failed, without a second request. `photoUrl` is the first
             * plate's THUMBNAIL, not the full 768x1024/~190 KB original —
             * the 192x256/~13 KB thumbnail is written since the first
             * upload and is what retention KEEPS, so the original was
             * fourteen times the bytes and could go blank on an old meal.
             * `first()` is ORDERED (Meal::photos: position, then id) so the
             * picture is stable across UPDATEs, and it's an authenticated
             * route since the disk isn't public. `canReanalyze` is false
             * once retention deletes the original, since the kept 256 px
             * thumbnail would let the model guess a dinner as authoritatively
             * as a real answer.
             */
            'photoUrl' => $photos->isEmpty()
                ? null
                : route('meals.photo.thumb', [
                    'meal'  => $meal->uuid,
                    'photo' => $photos->first()->client_id,
                ]),
            'photos'      => $photos->map(fn (MealPhoto $photo): array => $this->photoProps($meal, $photo))->values()->all(),
            'visionError' => $meal->latestVisionRequest?->error,

            /*
             * `estimateKind` lets ONE review sheet serve both text and
             * photo paths without guessing: no photograph on a proposal
             * means it came from a description, so the sheet says
             * "Estimating…" not "Analysing the photo…". `hasBlankItems`
             * triggers the manual "estimate missing values" affordance — a
             * blank item claims no energy, the honest reading of a NOT NULL
             * column that can't tell a typed 0 from an empty box.
             */
            'estimateKind'  => $photos->isEmpty() ? 'text' : 'photo',
            'hasBlankItems' => $items->contains(
                static fn (MealItem $item): bool => (float) $item->kcal_per_100g_max <= 0.0
            ),

            // "You have had this before" (step 6). Present on a meal whose
            // proposal was matched against history; null on everything else,
            // which is every typed and scanned meal by construction.
            'seenBefore' => $this->seenBeforeProps($meal),
        ];
    }

    /**
     * One plate, as the strip and the review sheet need it.
     *
     * `state` is the per-ENTRY half of the state machine (see
     * MealPhoto::entryState) telling the sheet whether to show a spinner,
     * error, proposal, or nothing — `meals.status` alone can't answer this
     * since a confirmed meal can hold an analysing dessert. `items` is
     * scoped to this plate, since confirming appends and posting the meal's
     * whole list would write the main course a second time.
     *
     * @return array<string, mixed>
     */
    private function photoProps(Meal $meal, MealPhoto $photo): array
    {
        $items = $meal->items->where('meal_photo_id', $photo->id);

        return [
            'clientId' => $photo->client_id,
            'position' => $photo->position,
            'url'      => route('meals.photo.at', ['meal' => $meal->uuid, 'photo' => $photo->client_id]),

            /*
             * The same plate at 256 px. Both sizes are sent because both are
             * wanted on one screen: the review sheet shows the plate under
             * review full width (the original) alongside a strip of 64 px
             * squares for the rest (the thumbnail) — three originals for
             * three postage stamps was 570 KB to draw 12,288 pixels.
             */
            'thumbUrl' => route('meals.photo.thumb', ['meal' => $meal->uuid, 'photo' => $photo->client_id]),
            'state'    => $photo->entryState(),
            // False once retention has taken the original. The 256 px
            // thumbnail is still shown; it just cannot be analysed again.
            'canReanalyze' => $photo->hasOriginal(),
            'modelNotes'   => $photo->model_notes,

            /*
             * What the USER said this plate is — a third subject beside
             * `meals.notes` (the meal) and `modelNotes` (the model's own
             * answer). Sent even when null, since the review sheet must
             * show an EMPTY box as readily as a filled one.
             */
            'hint'  => $photo->hint,
            'error' => $photo->latestVisionRequest?->error,

            // What the plate's "I ate:" chips are set to. The items carry their
            // own copy — an overridden one differs — so this is the chip's
            // state and not the arithmetic.
            'shareFraction'      => (float) $photo->share_fraction,
            'items'              => $items->map(fn (MealItem $item): array => $this->itemProps($item))->values()->all(),
            'confirmedItemCount' => $items->filter(
                static fn (MealItem $item): bool => $item->confirmed_at !== null
            )->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function seenBeforeProps(Meal $meal): ?array
    {
        $memory = $meal->memoryMatch;

        if ($memory === null) {
            return null;
        }

        $score = $meal->memory_match_score === null ? null : (float) $meal->memory_match_score;

        return [
            'label'        => $memory->canonical_name,
            'timesLogged'  => $memory->times_logged,
            'lastLoggedAt' => $memory->last_logged_at?->toIso8601String(),
            'score'        => $score,
            // An exact match means the item sets are identical, so the
            // remembered portions describe THIS plate. A partial match is
            // "looks like", and the screen is entitled to say so.
            'exact' => $score !== null && $score >= 1.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemProps(MealItem $item): array
    {
        $props = [
            'id'   => $item->id,
            'name' => $item->name,
            // What was EATEN. Every screen that shows a number shows this one.
            'portionGMin' => (float) $item->portion_g_min,
            'portionGMax' => (float) $item->portion_g_max,

            /*
             * The shared plate: `shareFraction` is the chip's setting;
             * `portionFullG*`/`*Full` are the plate BEFORE division. Sent
             * rather than derived, since eaten figures are rounded for
             * display and dividing back out by 0.3333 would drift — the
             * client moves the fraction, the server multiplies once.
             */
            'shareFraction'   => (float) $item->share_fraction,
            'portionFullGMin' => (float) $item->portion_full_g_min,
            'portionFullGMax' => (float) $item->portion_full_g_max,
            // Where the numbers came from. Carried to the front end so
            // editing a scanned item and re-saving keeps the link — the
            // meal form replaces items wholesale, dropping anything not
            // round-tripped through the props.
            'foodProductId' => $item->food_product_id,

            // Vision's own confidence identifying the food (0.3/0.6/0.9 for
            // low/medium/high). Null for typed and scanned items — nobody
            // is 60% sure what they just typed.
            'confidence' => $item->confidence === null ? null : (float) $item->confidence,

            // Null until the user taps. The review screen needs this to tell a
            // proposal from an item somebody has already agreed to.
            'confirmedAt' => $item->confirmed_at?->toIso8601String(),

            /*
             * Which plate this came off, as the client-generated id — null
             * for a typed line, barcode scan, or description estimate. The
             * review sheet needs it to post back exactly the lines for the
             * entry being confirmed, since confirming APPENDS and the
             * meal's whole list would write the main course twice.
             */
            'photoClientId' => $item->mealPhoto?->client_id,

            // True when meal_memory tightened what vision proposed
            // (step 6), so the review screen can mark which numbers came
            // from history rather than the photograph the user is about
            // to agree to.
            'memoryAdjusted' => (bool) $item->memory_adjusted,
        ];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $range = $this->intake->range($item, $nutrient);

            $props[$nutrient] = [
                'min' => round($range['min'], $nutrient === 'kcal' ? 0 : 1),
                'max' => round($range['max'], $nutrient === 'kcal' ? 0 : 1),
            ];

            $props[$nutrient.'Per100g'] = [
                'min' => (float) $item->{$nutrient.'_per_100g_min'},
                'max' => (float) $item->{$nutrient.'_per_100g_max'},
            ];

            // The same band for the WHOLE plate. Identical to the one above on
            // every unshared item, which is almost all of them.
            $full = $item->fullRange($nutrient);

            $props[$nutrient.'Full'] = [
                'min' => round($full['min'], $nutrient === 'kcal' ? 0 : 1),
                'max' => round($full['max'], $nutrient === 'kcal' ? 0 : 1),
            ];
        }

        return $props;
    }

    /**
     * The night that ENDS on this date. `night_date` is HAE's own key,
     * already the morning you woke up on, so no derivation is needed — why
     * sleep isn't shredded into hourly buckets. Two sources can report the
     * same night (Watch and AutoSleep); the same priority rule that
     * governs metrics picks one.
     *
     * @return array<string, mixed>|null
     */
    private function sleepProps(string $date): ?array
    {
        $sessions = SleepSession::query()
            ->with('source')
            ->where('night_date', $date)
            ->get();

        $session = $sessions->sortBy(
            fn (SleepSession $s): int => $this->priority->rankFor('sleep_analysis', $s->source->device_kind)
        )->first();

        // One guard rather than two: no rows and no winner are the same answer,
        // and this is the one static analysis can follow into the props below.
        if ($session === null) {
            return null;
        }

        $tz = (string) config('health.timezone');

        return [
            'totalMinutes' => $this->number($session->total_sleep_minutes),
            'remMinutes'   => $this->number($session->rem_minutes),
            'coreMinutes'  => $this->number($session->core_minutes),
            'deepMinutes'  => $this->number($session->deep_minutes),
            'awakeMinutes' => $this->number($session->awake_minutes),
            'start'        => $session->sleep_start?->setTimezone($tz)->format('H:i'),
            'end'          => $session->sleep_end?->setTimezone($tz)->format('H:i'),
            'source'       => $session->source->name,

            /*
             * How much of the night NOTHING accounts for — neither a watch
             * reading nor the session itself — or null when there's nothing
             * worth saying (App\Services\Reporting\NightCoverage). A
             * session can't report hours that hadn't reached the server
             * yet, so a part-delivered night arrives short and
             * complete-looking; computed per render, so it clears once the
             * rest lands.
             */
            'fragment' => $this->night->fragment($session),
        ];
    }

    /**
     * The sessions that STARTED on this date. These kcal are NOT new kcal
     * — a workout's active energy is already inside `summary.activeKcal`,
     * since the Watch writes `active_energy` continuously and starting a
     * workout just names a stretch of time already recorded; this method
     * returns a list, touching no total, since summing these in too would
     * double-count every run. Started-on, not overlapping: a session
     * belongs to the day it began, matching the generated `local_date`
     * column, so a four-day artifact sits on one day. Implausible sessions
     * are RETURNED, flagged, since the day view is where someone who left
     * a timer running can discover it; aggregates leave them out.
     *
     * @return list<array<string, mixed>>
     */
    private function workoutProps(string $date): array
    {
        $tz = (string) config('health.timezone');

        $sessions = Workout::query()
            ->onLocalDate($date)
            ->orderBy('started_at')
            ->get()
            ->map(fn (Workout $workout): array => [
                'id'              => $workout->id,
                'type'            => $workout->type,
                'start'           => $workout->started_at->setTimezone($tz)->format('H:i'),
                'end'             => $workout->ended_at->setTimezone($tz)->format('H:i'),
                'durationMinutes' => round($workout->durationMinutes(), 1),
                'distanceKm'      => $this->number($workout->distance_km),
                'activeKcal'      => $this->number($workout->active_kcal),
                'avgHr'           => $workout->avg_hr,
                'maxHr'           => $workout->max_hr,
                'stepCount'       => $workout->step_count,
                'isIndoor'        => $workout->is_indoor,
                // The honesty flag the card draws its caveat from.
                'isImplausible' => $workout->is_implausible,
            ])
            ->all();

        return array_values($sessions);
    }

    /**
     * The remembered meals, ranked by frecency (step 6): `times_logged`
     * weighted by recency, so a dinner cooked weekly beats one cooked
     * twenty times last winter. Formula and reasoning in
     * App\Services\Memory\MemoryRanker.
     *
     * @return list<array<string, mixed>>
     */
    private function frequentMeals(): array
    {
        return $this->memory->propsFor($this->memory->frequent());
    }

    /**
     * The last ten distinct meals, for one-tap re-logging. "Distinct" is by
     * the sorted set of item-name slugs — SPEC.md's meal_memory
     * fingerprint, computed on the fly — since the real `meal_memory`
     * tables with times_logged/pg_trgm matching are a later step and this
     * covers the everyday case with no new schema.
     *
     * @return list<array<string, mixed>>
     */
    private function recentMeals(int $limit = 10): array
    {
        $meals = Meal::query()
            ->confirmed()
            ->with('items')
            ->orderByDesc('eaten_at')
            ->limit($limit * 6)
            ->get();

        $seen = [];
        $recent = [];

        foreach ($meals as $meal) {
            if ($meal->items->isEmpty()) {
                continue;
            }

            $names = $meal->items->pluck('name')->map(static fn (string $name): string => mb_strtolower($name))->sort()->values();
            $fingerprint = $names->implode('|');

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;

            $recent[] = [
                'uuid'      => $meal->uuid,
                'label'     => $meal->items->pluck('name')->implode(', '),
                'mealType'  => $meal->meal_type?->value,
                'itemCount' => $meal->items->count(),
                'kcal'      => $this->intake->kcalBand($meal->items)->toArray(0),
            ];

            if (count($recent) >= $limit) {
                break;
            }
        }

        return $recent;
    }

    /**
     * @return array{min: float|null, mid: float|null, max: float|null}
     */
    private function macro(DailySummary $summary, string $prefix): array
    {
        return [
            'min' => $this->number($summary->{$prefix.'_min'}),
            'mid' => $this->number($summary->{$prefix.'_mid'}),
            'max' => $this->number($summary->{$prefix.'_max'}),
        ];
    }

    /** Postgres numerics come back as strings; JSON should carry numbers. */
    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
