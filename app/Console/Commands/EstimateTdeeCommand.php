<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Tdee\TdeeWindow;
use App\Services\Tdee\TdeeRecorder;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use App\Services\Tdee\CollectingData;

/**
 * Nightly (routes/console.php), and on demand when a rebuild touches the
 * window. Idempotent — TdeeRecorder writes only when the answer moved,
 * safe on a scheduler that may fire twice after a restart — and refuses
 * below the gate: a six-day estimate is wrong, not small.
 */
final class EstimateTdeeCommand extends Command
{
    protected $signature = 'tdee:estimate
                            {--date= : Treat this local date as "today" (default: today)}
                            {--dry-run : Compute and print, write nothing}';

    protected $description = 'Back-calculate TDEE from logged intake and the smoothed weight trend';

    public function handle(TdeeRecorder $recorder): int
    {
        $tz = (string) config('health.timezone');

        $today = $this->option('date') === null
            ? CarbonImmutable::now($tz)
            : CarbonImmutable::parse((string) $this->option('date'), $tz);

        $window = TdeeWindow::current($today);

        $outcome = $this->option('dry-run')
            ? app(TdeeEstimator::class)->estimate($window)
            : $recorder->record($window);

        $this->line(sprintf(
            '<comment>Window</comment> %s → %s (%d days)',
            $window->startDate(),
            $window->endDate(),
            $window->days(),
        ));

        if ($outcome instanceof CollectingData) {
            $this->line(sprintf(
                'Collecting data: %d/%d complete-log days, %d/%d weigh-ins. Nothing written.',
                $outcome->completeLogDays,
                $outcome->daysNeeded,
                $outcome->weighIns,
                $outcome->weighInsNeeded,
            ));

            return self::SUCCESS;
        }

        /** @var EstimatedTdee $outcome */
        $this->line(sprintf(
            'TDEE <info>%s–%s kcal</info> (mid %s) · intake mean %s · trend %+.3f kg/week · %d days, %d weigh-ins · %s',
            number_format($outcome->min()),
            number_format($outcome->max()),
            number_format($outcome->mid()),
            number_format($outcome->intakeMean),
            $outcome->slopeKgPerWeek(),
            $outcome->completeLogDays,
            $outcome->weighIns,
            $outcome->methodVersion,
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }
}
