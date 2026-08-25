<?php

declare(strict_types=1);

namespace App\Services\Report;

use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\ReportFocusArea;
use App\Enums\HealthReportStatus;

/**
 * Everything the report page renders.
 *
 * One class rather than a fat controller for the same reason DailyView and
 * StressView are: the same shape is wanted from three directions — the tab,
 * a direct link to one report, and the tests — and "what does the report
 * screen look like?" is a question about the domain, not about HTTP.
 *
 * The body is parsed, the list is not. A summary is cheap — dates, a
 * status, a headline, what it cost — so thirty of them is one query and no
 * parsing. The BODY goes through WrittenReport always, even for a report
 * written months ago under an older prompt version: the stored document is
 * whatever the schema said at the time, and a page reading
 * `output['energy_balance']` directly would work until the schema moved,
 * then break every historical report at once.
 */
final class ReportView
{
    /**
     * @return array<string, mixed>
     */
    public function props(?HealthReport $open, ?HealthReport $inFlight): array
    {
        $tz = (string) config('health.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        return [
            'report' => $open === null ? null : $this->full($open),

            'reports' => HealthReport::query()
                ->newest()
                // Thirty is roughly seven months of weeklies plus ad-hoc ones. Past
                // that it stops being browsable and becomes an archive, which this app doesn't have.
                ->limit(30)
                ->get()
                ->map(fn (HealthReport $r): array => $this->summary($r))
                ->all(),

            /*
             * The row already being written, if any. Carried separately from the list
             * (which also contains it) because the form's disabled state and the
             * polling target are one specific question — scanning the list for it
             * would be the same guard written twice.
             */
            'inFlight' => $inFlight === null ? null : $this->summary($inFlight),

            'presets'      => $this->presets($today),
            'maxRangeDays' => (int) config('health.report.max_range_days', 92),
            'today'        => $today->toDateString(),
            'model'        => (string) config('health.report.model'),

            /*
             * Focus options resolved server-side, including their ceilings. The form
             * must grey out a 6-month chip the moment Food is selected, matching what
             * the server would reject — sending the numbers instead of hardcoding 92
             * and 366 in Vue keeps the screen and validator from disagreeing.
             */
            'focus' => [
                'areas' => array_map(
                    static fn (ReportFocusArea $a): array => ['value' => $a->value, 'label' => $a->label()],
                    ReportFocusArea::cases(),
                ),
                'maxTextLength' => ReportFocus::MAX_TEXT,
                'maxRangeDays'  => [
                    'full' => ReportFocus::everything()->maxRangeDays(),
                    'lean' => ReportFocus::of([ReportFocusArea::Stress])->maxRangeDays(),
                ],
                'longPresets' => $this->longPresets($today),
            ],
        ];
    }

    /**
     * The newest report worth opening.
     *
     * READY only — a failed row belongs in the list, where its error is
     * readable and it can be regenerated; opening straight into a failure,
     * with a good report from last Monday one row below, would be
     * technically accurate but useless.
     */
    public function newestReadable(): ?HealthReport
    {
        return HealthReport::query()
            ->where('status', HealthReportStatus::Ready->value)
            ->newest()
            ->first();
    }

    /**
     * One line in the list, and the shape the poll endpoint answers with.
     *
     * @return array<string, mixed>
     */
    public function summary(HealthReport $report): array
    {
        $headline = $report->output['headline'] ?? null;

        $focus = $report->focus();

        return [
            'id'        => $report->id,
            'kind'      => $report->kind->value,
            'kindLabel' => $report->kind->label(),

            /*
             * "Stress + Sleep", or null for an ordinary full report. Read from the
             * two COLUMNS, not the stored snapshot, because this method is
             * deliberately the cheap half of the class — thirty rows, one query, no
             * parsing — and decoding thirty snapshots for two fields would break
             * that. See the migration for why the columns exist.
             */
            'focusLabel'  => $focus->label(),
            'focusAreas'  => $focus->areaValues(),
            'focusText'   => $focus->text,
            'status'      => $report->status->value,
            'pending'     => $report->isPending(),
            'rangeStart'  => $report->range_start->toDateString(),
            'rangeEnd'    => $report->range_end->toDateString(),
            'rangeLabel'  => $report->rangeLabel(),
            'days'        => $report->days(),
            'headline'    => is_string($headline) && $headline !== '' ? $headline : null,
            'error'       => $report->error,
            'createdAt'   => $report->created_at?->toIso8601String(),
            'generatedAt' => $report->generated_at?->toIso8601String(),
            'url'         => route('report.show', ['report' => $report->id]),
        ];
    }

    /**
     * A report, opened.
     *
     * @return array<string, mixed>
     */
    public function full(HealthReport $report): array
    {
        $body = is_array($report->output) && $report->output !== []
            ? WrittenReport::fromDecoded($report->output)->toArray()
            : null;

        return $this->summary($report) + [
            'body' => $body,

            /*
             * The provenance line under the report: model, prompt version, cost and
             * latency, shown rather than hidden — same instinct as `vision_requests`
             * keeping all of these. A generated document that doesn't say what
             * generated it looks like it came from somewhere authoritative.
             */
            'provenance' => [
                'model'         => $report->model,
                'promptVersion' => $report->prompt_version,
                'inputTokens'   => $report->input_tokens,
                'outputTokens'  => $report->output_tokens,
                'costUsd'       => $report->cost_usd === null ? null : round((float) $report->cost_usd, 4),
                'latencyMs'     => $report->latency_ms,
                'snapshotUrl'   => route('report.snapshot', ['report' => $report->id]),
                'hasSnapshot'   => is_array($report->input_snapshot) && $report->input_snapshot !== [],
            ],

            /*
             * A handful of figures lifted straight off the stored snapshot, so the
             * page can show the coverage the report was written under without opening
             * the raw JSON. Read from the SNAPSHOT, not recomputed from the database —
             * these are the numbers the model actually saw; recomputing would quietly
             * disagree with the prose beside it the moment a late export landed.
             */
            'coverage' => $this->coverage($report),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coverage(HealthReport $report): ?array
    {
        $snapshot = $report->input_snapshot;

        if (! is_array($snapshot)) {
            return null;
        }

        $coverage = $snapshot['coverage'] ?? null;

        if (! is_array($coverage)) {
            return null;
        }

        $pick = static function (mixed $block): ?array {
            if (! is_array($block)) {
                return null;
            }

            return ['n' => $block['n'] ?? null, 'pct' => $block['pct'] ?? null];
        };

        return [
            'days'                => $coverage['days_in_range'] ?? $report->days(),
            'foodLoggedDays'      => $pick($coverage['food_logged_days'] ?? null),
            'completeFoodLogDays' => $pick($coverage['complete_food_log_days'] ?? null),
            'stressScoredDays'    => $pick($coverage['stress_scored_days'] ?? null),
            'sleepNights'         => $pick($coverage['sleep_nights'] ?? null),
            'weighIns'            => $pick($coverage['weigh_ins'] ?? null),
        ];
    }

    /**
     * The chips on the form.
     *
     * Each carries the dates it resolves to as well as its length, so the page
     * can show "3–9 Aug" under "7 days" instead of making the user work it out.
     * Resolved here because it depends on the SERVER's clock in the app's
     * reporting timezone — the same clock everything else uses, not the
     * phone's, which can be silently wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function presets(CarbonImmutable $today): array
    {
        /** @var list<int> $presets */
        $presets = (array) config('health.report.presets', [3, 7, 14, 30]);

        $out = [];

        foreach ($presets as $days) {
            $days = max(1, (int) $days);

            $range = ReportRange::lastDays($days, $today);

            $out[] = [
                'days'  => $days,
                'label' => $days.' days',
                'start' => $range->start,
                'end'   => $range->end,
            ];
        }

        return $out;
    }

    /**
     * The chips that only exist once a focus makes them legal.
     *
     * Shown always, disabled until they fit — the alternative (appearing when
     * Stress is tapped, vanishing when Food is) reflows controls under
     * somebody's thumb and hides the one thing worth knowing: a longer report
     * is possible, and what it costs. So the chips stay put, greyed with the
     * reason on them.
     *
     * `null` for `start`/`end` past the FULL ceiling — a range this app would
     * refuse to assemble has no honest dates to print.
     *
     * @return list<array<string, mixed>>
     */
    private function longPresets(CarbonImmutable $today): array
    {
        /** @var list<int> $presets */
        $presets = (array) config('health.report.focus.presets', [90, 182, 365]);

        $lean = ReportFocus::of([ReportFocusArea::Stress]);

        $out = [];

        foreach ($presets as $days) {
            $days = max(1, (int) $days);

            if ($days > $lean->maxRangeDays()) {
                continue;
            }

            $range = ReportRange::lastDays($days, $today, $lean);

            $out[] = [
                'days' => $days,
                // "6 months" not "182 days" — nobody thinks in 182s.
                'label'      => self::monthsLabel($days),
                'start'      => $range->start,
                'end'        => $range->end,
                'needsFocus' => $days > ReportFocus::everything()->maxRangeDays(),
            ];
        }

        return $out;
    }

    private static function monthsLabel(int $days): string
    {
        return match (true) {
            $days >= 360 => '1 year',
            $days >= 175 => '6 months',
            $days >= 85  => '3 months',
            default      => $days.' days',
        };
    }
}
