<?php

declare(strict_types=1);

namespace App\Services\Stress;

use Carbon\CarbonImmutable;

/**
 * The one place that turns "which dates do you care about?" into a loaded,
 * ready-to-question StressAnalysis.
 *
 * Three windows overlap almost completely in the ordinary case — the dates
 * being scored plus `baseline_days` (60) before them, the circadian profile
 * window (last `profile_days`/365 days), and the heatmap window (last
 * `heatmap_days`/90 days) — so their union loads in one indexed read
 * rather than three. Production's widest case (browsing to the first HRV
 * reading) is 13,210 rows in one query and a few megabytes; the ordinary
 * case (this week) is a year, ~7,300 rows. Splitting that into three
 * queries would buy nothing and turn "which samples did this score see?"
 * into a question with three answers.
 *
 * The circadian profile is built ONCE PER RUN, not once per day: it's a
 * physiological constant of the person, not what moves in a bad week
 * (which is the premise of subtracting it), so a strictly as-of-that-day
 * profile would cost three orders of magnitude more to chase a
 * third-decimal difference. Rebuilding history in six months does apply
 * six months' more evidence to old days, moving a score by a point —
 * `stress_daily` is a derived table with `method_version` stamped on
 * every row, the same contract `tdee_estimates` makes.
 */
final class StressCalculator
{
    public function __construct(
        private readonly HrvSamples $samples = new HrvSamples,
    ) {}

    /**
     * Load everything needed to score [$from, $to] and to draw the heatmap and
     * profile as of $today.
     */
    public function analyse(string $from, string $to, ?CarbonImmutable $today = null): StressAnalysis
    {
        $tz = (string) config('health.timezone');
        $today ??= CarbonImmutable::now($tz);

        $todayDate = $today->setTimezone($tz)->startOfDay();

        $baselineDays = (int) config('health.stress.baseline_days', 60);
        $profileDays = (int) config('health.stress.profile_days', 365);
        $heatmapDays = (int) config('health.stress.heatmap_days', 90);

        $loadFrom = min(
            CarbonImmutable::parse($from, $tz)->subDays($baselineDays)->toDateString(),
            $todayDate->subDays(max($profileDays, $heatmapDays))->toDateString(),
        );

        $loadTo = max($to, $todayDate->toDateString());

        $byDate = $this->samples->between($loadFrom, $loadTo);

        $profileFrom = $todayDate->subDays($profileDays)->toDateString();

        $profile = CircadianProfile::from(array_filter(
            $byDate,
            static fn (string $date): bool => $date >= $profileFrom,
            ARRAY_FILTER_USE_KEY,
        ));

        return new StressAnalysis($byDate, $profile, new StressScale);
    }

    /**
     * The heatmap window as of a given day: the last `heatmap_days` days,
     * inclusive of today.
     *
     * @return array{0: string, 1: string}
     */
    public static function heatmapWindow(CarbonImmutable $today): array
    {
        $days = max(1, (int) config('health.stress.heatmap_days', 90));

        return [
            $today->subDays($days - 1)->toDateString(),
            $today->toDateString(),
        ];
    }
}
