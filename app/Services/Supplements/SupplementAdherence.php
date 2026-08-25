<?php

declare(strict_types=1);

namespace App\Services\Supplements;

use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use Illuminate\Support\Collection;
use App\Services\Support\LocalDates;

/**
 * "supplements: 6/7 evenings" — the one line of adherence on the trends page.
 *
 * Three rules, each existing to stop a number lying:
 *
 * 1. A day only counts once there was something to take — scored from each
 *    supplement's own `created_at`, so a bottle bought Thursday can't make
 *    the prior weeks read as missed doses.
 *
 * 2. The future is not a miss — a 7- or 28-day window ends today and never
 *    stretches into next week, or tonight would read "missed" by 9 a.m.
 *
 * 3. All or nothing per day: an evening counts when every supplement that
 *    existed was ticked. A partial score needs a weighting nobody asked
 *    for (three of four better than one of two?), and "most of them, most
 *    nights" is the honest failing answer, not a 75%.
 *
 * Only ACTIVE supplements are scored, including retrospectively: something
 * stopped a fortnight ago shouldn't keep dragging the number down, and
 * tracking each deactivation is a `stopped_at` column for a line of text.
 */
final class SupplementAdherence
{
    /**
     * Null when there's nothing to say: no supplements, or none of them
     * existed during the window. A card that says "0/0" is worse than none.
     *
     * @return array{days: int, complete: int, counted: int, start: string, end: string}|null
     */
    public function forRange(int $days, ?string $endDate = null): ?array
    {
        $tz = (string) config('health.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        $end = $endDate === null ? $today : CarbonImmutable::parse($endDate, $tz)->startOfDay();

        // Rule 2. A window that ends in the future ends today instead.
        if ($end->greaterThan($today)) {
            $end = $today;
        }

        $start = $end->subDays($days - 1);

        /** @var Collection<int, Supplement> $supplements */
        $supplements = Supplement::query()->active()->get();

        if ($supplements->isEmpty()) {
            return null;
        }

        /** @var Collection<string, Collection<int, SupplementIntake>> $intakes */
        $intakes = SupplementIntake::query()
            ->forSupplementsInRange($supplements, $start->toDateString(), $end->toDateString())
            ->get()
            ->groupBy(fn (SupplementIntake $intake): string => $intake->local_date->toDateString());

        $counted = 0;
        $complete = 0;

        foreach (LocalDates::inclusive($start->toDateString(), $end->toDateString(), $tz) as $date) {
            // Rule 1. Only the bottles that existed on that day.
            $expected = $supplements->filter(
                static fn (Supplement $supplement): bool => $supplement->startedOn() <= $date
            );

            if ($expected->isEmpty()) {
                continue;
            }

            $counted++;

            $takenIds = ($intakes->get($date) ?? collect())
                ->pluck('supplement_id')
                ->all();

            // Rule 3.
            $missing = $expected->reject(
                static fn (Supplement $supplement): bool => in_array($supplement->id, $takenIds, strict: true)
            );

            if ($missing->isEmpty()) {
                $complete++;
            }
        }

        if ($counted === 0) {
            return null;
        }

        return [
            'days'     => $days,
            'complete' => $complete,
            'counted'  => $counted,
            'start'    => $start->toDateString(),
            'end'      => $end->toDateString(),
        ];
    }
}
