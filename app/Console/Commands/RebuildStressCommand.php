<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StressBand;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Stress\DailyStress;
use App\Services\Support\LocalDates;
use App\Services\Stress\StressRecorder;
use App\Services\Stress\StressCalculator;

/**
 * Recompute `stress_daily`.
 *
 *   php artisan stress:rebuild                 # the trailing `rebuild_days`
 *   php artisan stress:rebuild --all           # every day there is HRV for
 *   php artisan stress:rebuild --days=365      # a longer trailing window
 *   php artisan stress:rebuild --date=2026-07-28   # one day
 *   php artisan stress:rebuild --dry-run       # compute, print, write nothing
 *
 * Runs nightly (routes/console.php) so the table keeps up on a day nobody
 * opens the app, and by hand after any change to config('health.stress') —
 * fully derived, so `--all` is the entire migration procedure for a
 * threshold edit.
 *
 * DEFAULT IS A TRAILING WINDOW, NOT JUST YESTERDAY: a day's score is measured
 * against the 60 days behind it, so a late export shifts the baseline of
 * every day after it, not just its own — a phone offline for a fortnight, or
 * an `ingest:replay`, invalidates a stretch. `rebuild_days` (90) covers the
 * realistic worst case for one query's cost.
 *
 * IDEMPOTENT: score is a pure function of readings and config, and
 * StressRecorder only writes when the answer moved — ten runs leave one row
 * per day with one `computed_at`.
 */
final class RebuildStressCommand extends Command
{
    protected $signature = 'stress:rebuild
                            {--all : Every local date that has HRV readings}
                            {--days= : How many trailing days to recompute (default: config)}
                            {--date= : One local date only}
                            {--dry-run : Compute and print, write nothing}';

    protected $description = 'Recompute daily stress scores from HRV against the personal baseline';

    public function handle(StressCalculator $calculator, StressRecorder $recorder): int
    {
        $tz = (string) config('health.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        [$from, $to] = $this->window($today, $tz);

        if ($from === null) {
            $this->warn('No HRV readings in health_metrics — nothing to rebuild.');

            return self::SUCCESS;
        }

        $this->line(sprintf('<comment>Rebuilding</comment> %s → %s', $from, $to));

        $analysis = $calculator->analyse($from, $to, $today);

        $days = $analysis->days(LocalDates::inclusive($from, $to, $tz));

        $scored = array_filter($days, static fn (DailyStress $d): bool => $d->hasScore());

        if (! $this->option('dry-run')) {
            $result = $recorder->record($days);

            $this->line(sprintf(
                '%d written, %d unchanged, %d removed.',
                $result['written'],
                $result['unchanged'],
                $result['removed'],
            ));
        }

        $this->summarise($days, $scored);

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function window(CarbonImmutable $today, string $tz): array
    {
        if (is_string($date = $this->option('date'))) {
            $day = CarbonImmutable::parse($date, $tz)->toDateString();

            return [$day, $day];
        }

        $to = $today->toDateString();

        if ($this->option('all')) {
            $metric = (string) config('health.stress.metric', 'heart_rate_variability');

            /** @var string|null $first */
            $first = DB::table('health_metrics')->where('metric', $metric)->min('local_date');

            return [$first === null ? null : (string) $first, $to];
        }

        $days = max(1, (int) ($this->option('days') ?? config('health.stress.rebuild_days', 90)));

        return [$today->subDays($days - 1)->toDateString(), $to];
    }

    /**
     * @param  array<string, DailyStress>  $days
     * @param  array<string, DailyStress>  $scored
     */
    private function summarise(array $days, array $scored): void
    {
        if ($scored === []) {
            $this->line(sprintf(
                '%d days examined, none scored — not enough history behind them yet.',
                count($days),
            ));

            return;
        }

        $scores = array_map(static fn (DailyStress $d): int => (int) $d->score, $scored);

        $this->line(sprintf(
            '%d of %d days scored · mean %.1f · range %d–%d',
            count($scored),
            count($days),
            array_sum($scores) / count($scores),
            min($scores),
            max($scores),
        ));

        $rows = [];

        foreach (StressBand::cases() as $band) {
            $n = count(array_filter(
                $scored,
                static fn (DailyStress $d): bool => $d->band === $band
            ));

            $rows[] = [
                $band->label(),
                sprintf('%d–%d', $band->floor(), $band->ceiling()),
                $n,
                sprintf('%.1f%%', 100 * $n / count($scored)),
            ];
        }

        $this->table(['Band', 'Score', 'Days', 'Share'], $rows);
    }
}
