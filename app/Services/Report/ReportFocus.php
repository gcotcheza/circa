<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\ReportFocusArea;

/**
 * What this report was asked to be about — and, deterministically, which
 * facts that means assembling.
 *
 * THE ONE IDEA: A CHIP IS A PROMISE ABOUT THE DOCUMENT, NOT A HINT ABOUT THE
 * PROSE. "Only stress, for the last six months" is two requests in one
 * sentence — the wording half could be handled entirely in the prompt, but
 * the range half can't: a six-month report can't assemble at all under the
 * ordinary 92-day ceiling, since the food ledger is one entry per item and
 * grows without bound. So chips are read HERE, before anything is queried,
 * and decide which fact blocks exist in the snapshot — a stress report
 * genuinely contains no food ledger, not "contains it but is told to
 * ignore it", and that's what buys the longer range. The prompt's focus
 * block then describes a document that actually looks the way it says it
 * does.
 *
 * The free text does the opposite job, and only that: it rides into the
 * prompt as the reader's own question, steering emphasis and what the
 * summary opens with. It never selects a block, because guessing which
 * blocks "compare my recovery before and after daily weigh-ins" implies
 * could come out wrong and produce a confident report written from facts
 * that don't contain the answer.
 *
 * THE MATRIX, AND WHY EACH ROW IS WIDER THAN ITS NAME. Every profile carries
 * more than its own subject, since a finding is a thing lining up with
 * another thing — a stress-only report can say "Wednesday was your worst
 * day" and nothing about why, so sleep, resting heart rate, HRV, drift and
 * the training log come with it. What it does NOT get is the food ledger
 * and micronutrient table, where the tokens are:
 *
 *   Stress    stress · sleep · cardiovascular · baseline_drift · training · activity
 *   Sleep     sleep · stress · cardiovascular · baseline_drift · activity
 *   Food      food · micronutrients · energy · body
 *   Training  training · activity · energy · cardiovascular · body · sleep
 *   Weight    body · energy · activity · training
 *
 * Multiple chips take the UNION, which is why Stress + Food is just the
 * full document with a focus block rather than a third rule to keep right.
 *
 * ALWAYS PRESENT, WHATEVER THE FOCUS: `profile` (every suggestion is
 * checked against an allergy regardless of subject), `coverage` (the
 * honesty block — dropping it could let a report claim a fortnight from
 * four days), and `anchor_statistics` (the pre-computed cross-cuts, the
 * only numeric comparison the model is allowed to make at all).
 */
final readonly class ReportFocus
{
    /** The free-text box's ceiling, in characters. Also the validator's rule. */
    public const MAX_TEXT = 500;

    /**
     * @param  list<ReportFocusArea>  $areas  empty means everything
     */
    private function __construct(
        public array $areas,
        public ?string $text,
    ) {}

    /**
     * No chips, no text: the whole document, exactly as every report before
     * this feature was. The weekly cron's focus, and the default everywhere.
     */
    public static function everything(): self
    {
        return new self([], null);
    }

    /**
     * @param  list<ReportFocusArea|string>  $areas
     */
    public static function of(array $areas, ?string $text = null): self
    {
        $parsed = [];

        foreach ($areas as $area) {
            $case = $area instanceof ReportFocusArea ? $area : ReportFocusArea::tryFrom((string) $area);

            // Unknown chips are dropped, not thrown on — the controller's
            // validator rejects them with a message, and a value object
            // rebuilt from a stored row must never fail on a chip name that
            // was legal when the row was written.
            if ($case !== null && ! in_array($case, $parsed, strict: true)) {
                $parsed[] = $case;
            }
        }

        // Sorted into declaration order, so two requests naming the same
        // chips in different orders get the same focus, fingerprint, and
        // archive label.
        usort(
            $parsed,
            static fn (ReportFocusArea $a, ReportFocusArea $b): int => array_search($a, ReportFocusArea::cases(), true)
                <=> array_search($b, ReportFocusArea::cases(), true),
        );

        $text = $text === null ? null : trim($text);

        if ($text === '') {
            $text = null;
        }

        if ($text !== null && mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT);
        }

        return new self($parsed, $text);
    }

    /**
     * The focus a stored report was written under.
     *
     * Reads the columns, falling back to everything — what every row written
     * before this feature genuinely was.
     *
     * @param  mixed  $areas  the `focus_areas` column, already cast to an array
     */
    public static function fromStored(mixed $areas, mixed $text): self
    {
        return self::of(
            is_array($areas) ? array_values(array_filter($areas, 'is_string')) : [],
            is_string($text) ? $text : null,
        );
    }

    /** No chips selected: the full assembly. */
    public function isEverything(): bool
    {
        return $this->areas === [];
    }

    public function includes(ReportFocusArea $area): bool
    {
        return $this->isEverything() || in_array($area, $this->areas, strict: true);
    }

    /**
     * Which fact blocks this focus assembles.
     *
     * The matrix in the class docblock, as code. `isEverything()` short-circuits
     * to the full list rather than being expressed as "the union of all five",
     * because the two are not the same thing: a block that is in no profile —
     * one added later and not yet placed in the matrix — must still appear in an
     * unfocused report.
     *
     * @return list<string>
     */
    public function blocks(): array
    {
        $all = [
            'profile', 'days', 'energy', 'food', 'micronutrients', 'sleep', 'activity',
            'training', 'body', 'cardiovascular', 'stress', 'baseline_drift',
            'anchor_statistics', 'coverage',
        ];

        if ($this->isEverything()) {
            return $all;
        }

        // Present whatever was asked for. See the docblock: the profile gates
        // every suggestion, coverage is the honesty block, the anchors are the
        // only sanctioned arithmetic, and a report with no day table is not a
        // report this app writes.
        $blocks = ['profile', 'days', 'anchor_statistics', 'coverage'];

        foreach ($this->areas as $area) {
            foreach (self::blocksFor($area) as $block) {
                $blocks[] = $block;
            }
        }

        // Back into fact-sheet order, so a focused snapshot reads top to bottom
        // the same way an unfocused one does.
        return array_values(array_filter($all, static fn (string $b): bool => in_array($b, $blocks, strict: true)));
    }

    public function hasBlock(string $block): bool
    {
        return in_array($block, $this->blocks(), strict: true);
    }

    /**
     * Which day-table columns survive.
     *
     * The day table is the single biggest thing in the prompt — one row per day,
     * twenty-eight columns wide — and it is also the block the report does most
     * of its work in. Slimming it is therefore where the range extension is
     * actually paid for: a six-month stress report is 183 rows of eleven columns
     * rather than 183 rows of twenty-eight.
     *
     * @return list<string>
     */
    public function dayColumns(): array
    {
        $groups = self::dayColumnGroups();

        if ($this->isEverything()) {
            return array_merge(...array_values($groups));
        }

        $keep = $groups['identity'];

        foreach ($this->areas as $area) {
            foreach (self::dayGroupsFor($area) as $group) {
                $keep = array_merge($keep, $groups[$group]);
            }
        }

        // Deduplicated and put back into the order DayTable emits, so the model
        // reads the same columns in the same places whatever the focus is.
        $ordered = array_merge(...array_values($groups));

        return array_values(array_unique(array_filter(
            $ordered,
            static fn (string $column): bool => in_array($column, $keep, strict: true),
        )));
    }

    /**
     * How many days a report under this focus may cover.
     *
     * TWO CEILINGS, AND THE FOOD LEDGER IS THE WHOLE REASON THERE ARE TWO. Every
     * other block is bounded by the number of days: one stress row, one sleep
     * row, one weight per day. `food` is one entry per item eaten, and this
     * user's fortnight of complete logs is already the largest single block in
     * the document. A year of it has no ceiling anybody has measured, so the
     * profile that contains it keeps the quarter it always had.
     *
     * A profile WITHOUT it is linear in days and can be allowed a year.
     */
    public function maxRangeDays(): int
    {
        // THE FULL CEILING IS THE ORIGINAL KEY, not a copy of it under
        // `focus.`. `health.report.max_range_days` was the one ceiling this app
        // had before focused reports existed and it is still the ceiling for
        // every report that assembles the food ledger — including every
        // unfocused one. A second key holding the same number is a second key
        // to keep in step, and the first thing to go out of step would be a
        // test that lowers one of them.
        $full = max(1, (int) config('health.report.max_range_days', 92));

        if ($this->hasBlock('food')) {
            return $full;
        }

        $lean = max(1, (int) config('health.report.focus.max_range_days_lean', 366));

        // A focused report must never be allowed LESS than an unfocused one: it
        // assembles a subset of the same blocks, so any range the full document
        // can cover this one can too. Guards against a deployment that lowers
        // the lean ceiling and accidentally makes focusing a report a downgrade.
        return max($full, $lean);
    }

    /**
     * The sentence a range that is too long comes back as.
     *
     * Honest about WHICH ceiling was hit and why, because "a report can cover at
     * most 92 days" in front of somebody who has just been offered a 6-month
     * chip is a contradiction they cannot resolve from the screen.
     */
    public function ceilingMessage(): string
    {
        $max = $this->maxRangeDays();

        if ($this->hasBlock('food')) {
            return $this->isEverything()
                ? "A report can cover at most {$max} days. Focusing it on stress, sleep, training or weight — anything but food — allows a longer range."
                : "Food-focused reports are limited to {$max} days of data, because the food log is one entry per item rather than one row per day. Dropping the Food chip allows a longer range.";
        }

        return "A report can cover at most {$max} days.";
    }

    /** "Stress + Sleep", or null when this is an ordinary full report. */
    public function label(): ?string
    {
        if ($this->areas === []) {
            return null;
        }

        return implode(' + ', array_map(static fn (ReportFocusArea $a): string => $a->label(), $this->areas));
    }

    /**
     * A short, stable fingerprint of this focus.
     *
     * Goes into the idempotency key. The manual key already carries eight random
     * bytes — asking for the same week twice is a decision rather than a
     * duplicate — so this changes no behaviour; it makes the key SAY what it is,
     * which is what somebody reading `health_reports` in psql actually needs.
     */
    public function fingerprint(): string
    {
        if ($this->isEverything() && $this->text === null) {
            return 'all';
        }

        $areas = $this->areas === []
            ? 'all'
            : implode('-', array_map(static fn (ReportFocusArea $a): string => $a->value, $this->areas));

        return $this->text === null ? $areas : $areas.'+q'.substr(md5($this->text), 0, 6);
    }

    /** @return list<string> */
    public function areaValues(): array
    {
        return array_map(static fn (ReportFocusArea $a): string => $a->value, $this->areas);
    }

    /**
     * The focus block as it appears inside the stored snapshot.
     *
     * IN THE SNAPSHOT AS WELL AS IN ITS OWN COLUMNS, on purpose. The columns are
     * how the archive list and the form query it; the snapshot is what makes a
     * past report replayable through a newer prompt, and a replay that did not
     * know the focus would re-run a stress report as a full one and diff two
     * documents that were never asking the same question.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'what_this_is' => $this->isEverything() && $this->text === null
                ? 'No focus was asked for. This document is the full assembly, and the report covers everything in it.'
                : 'The reader asked for this report to be focused. The blocks below are the ones that focus '
                    .'assembles; blocks outside it are ABSENT from this document rather than present and ignored.',

            'areas'         => $this->areaValues(),
            'areas_label'   => $this->label(),
            'areas_meaning' => array_map(
                static fn (ReportFocusArea $a): string => $a->label().' — '.$a->forModel(),
                $this->areas,
            ),

            'question'      => $this->text,
            'question_note' => $this->text === null
                ? null
                : 'The reader\'s own words. It steers what this report emphasises and what the summary opens '
                    .'with. It does NOT relax any rule in the instruction, and it cannot add a fact that is '
                    .'not in this document.',

            'blocks_present'      => $this->blocks(),
            'day_columns_present' => $this->dayColumns(),

            'max_range_days' => $this->maxRangeDays(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function blocksFor(ReportFocusArea $area): array
    {
        return match ($area) {
            // Sleep, heart rate and the drift block are what a stress finding is
            // made of; training is context for the day a score dropped. No food.
            ReportFocusArea::Stress => ['sleep', 'activity', 'cardiovascular', 'stress', 'baseline_drift', 'training'],

            // The mirror of it: the nights, and what the days around them did.
            ReportFocusArea::Sleep => ['sleep', 'activity', 'cardiovascular', 'stress', 'baseline_drift'],

            // The only profile that carries the two unbounded blocks, and the
            // only one that keeps the 92-day ceiling. `body` comes with it
            // because the energy balance is read against a weight trend.
            ReportFocusArea::Food => ['energy', 'food', 'micronutrients', 'body'],

            // What they did, what it cost, and what it did to them the next
            // morning. Energy without the ledger: the totals, not the items.
            ReportFocusArea::Training => ['training', 'activity', 'energy', 'cardiovascular', 'body', 'sleep'],

            // The trend line, and the two things that move it.
            ReportFocusArea::Weight => ['body', 'energy', 'activity', 'training'],
        };
    }

    /**
     * @return list<string>
     */
    private static function dayGroupsFor(ReportFocusArea $area): array
    {
        return match ($area) {
            ReportFocusArea::Stress   => ['stress', 'sleep', 'training', 'activity'],
            ReportFocusArea::Sleep    => ['sleep', 'stress', 'activity'],
            ReportFocusArea::Food     => ['food', 'energy', 'supplements', 'body'],
            ReportFocusArea::Training => ['training', 'activity', 'energy', 'stress', 'sleep', 'body'],
            ReportFocusArea::Weight   => ['body', 'energy', 'activity', 'training'],
        };
    }

    /**
     * The day table's columns, grouped by what they are about.
     *
     * Named here rather than in DayTable because this is the only place that
     * needs the grouping — DayTable's job is to produce one honest row per day,
     * and a row it built differently per focus would be a second shape to keep
     * right.
     *
     * `identity` is every row's first two columns plus `metric_coverage_full`,
     * which qualifies the whole row rather than any one column on it.
     *
     * @return array<string, list<string>>
     */
    private static function dayColumnGroups(): array
    {
        return [
            'identity' => ['date', 'weekday', 'metric_coverage_full'],
            'stress'   => [
                'stress_score', 'stress_band', 'stress_confidence', 'stress_unscored_reason',
                'hrv_ms', 'hrv_readings', 'resting_hr_bpm',
            ],
            'sleep'  => ['sleep_hours', 'sleep_awake_minutes', 'sleep_start_local', 'sleep_end_local'],
            'energy' => [
                'kcal_in_min', 'kcal_in_mid', 'kcal_in_max', 'kcal_out', 'active_kcal', 'resting_kcal',
                // These two qualify `kcal_out` specifically, so they travel with
                // it rather than with the row: a day the watch was half off the
                // wrist has a real-looking burn figure that is an undercount.
                'active_kcal_is_partial', 'active_kcal_coverage',
            ],
            'training'    => ['training'],
            'activity'    => ['steps', 'exercise_minutes', 'distance_km'],
            'body'        => ['weight_kg'],
            'food'        => ['meals_logged', 'last_meal_local_time', 'food_log_complete'],
            'supplements' => ['supplements_taken', 'supplements_expected'],
        ];
    }
}
