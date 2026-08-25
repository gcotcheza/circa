<?php

declare(strict_types=1);

namespace App\Services\Supplements;

use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use Illuminate\Support\Collection;

/**
 * The supplements card, for one local date.
 *
 * One class rather than a lump in DailyView because the same shape is
 * wanted from two directions — the day page, and the check-off endpoint
 * answering with the state the tap produced — and a second serialisation
 * would be a second chance for "taken" to mean something subtly different
 * depending on which one drew it.
 *
 * The evening emphasis is computed here: the card gets a quiet attention
 * state when the day on screen is today, it's evening, and something is
 * still unticked. Not a notification (no push in this app, none planned)
 * — just a card that looks slightly more like it wants something.
 *
 * "Evening" is decided against the SERVER's clock in the app's reporting
 * timezone, the same clock that decides which local day anything else
 * belongs to. The browser's own clock is wrong for the same reason the
 * ingest indicator doesn't use it: a phone whose clock has drifted would
 * move the boundary, silently.
 *
 * The cost is that a page left open across 18:00 doesn't light up until
 * something reloads it — an acceptable trade for a subtle emphasis on a
 * page the user opens to log dinner anyway, versus a timer ticking in the
 * page as a background task in exchange for a shade of amber.
 */
final class SupplementDay
{
    /**
     * @return array<string, mixed>
     */
    public function props(string $date): array
    {
        $tz = (string) config('health.timezone');
        $now = CarbonImmutable::now($tz);

        /** @var Collection<int, Supplement> $supplements */
        $supplements = Supplement::query()->active()->get();

        $taken = SupplementIntake::query()
            ->where('local_date', $date)
            ->whereIn('supplement_id', $supplements->pluck('id'))
            ->get()
            ->keyBy('supplement_id');

        $items = $supplements->map(function (Supplement $supplement) use ($taken): array {
            $intake = $taken->get($supplement->id);

            return [
                'id'          => $supplement->id,
                'name'        => $supplement->name,
                'brand'       => $supplement->brand,
                'servingText' => $supplement->serving_text,

                /*
                 * What one tap means. The card draws "2 x per capsule" under
                 * the name so a tick is unambiguous about what was taken —
                 * the alternative is a checkbox meaning two capsules Tuesday
                 * and one Wednesday because somebody edited the bottle in
                 * between.
                 */
                'unitsPerDay' => $supplement->units_per_day,
                // The 256 px label thumbnail, streamed from the private disk
                // behind session auth — same arrangement as a meal plate.
                // Null when typed in rather than photographed, a perfectly
                // ordinary way to add one.
                'photoUrl' => $supplement->photo_path === null
                    ? null
                    : route('supplements.photo', ['supplement' => $supplement->id]),
                'taken'   => $intake !== null,
                'takenAt' => $intake?->taken_at->toIso8601String(),
            ];
        })->values()->all();

        $takenCount = count(array_filter($items, static fn (array $item): bool => $item['taken']));

        $isToday = $date === $now->toDateString();
        $eveningHour = (int) config('health.supplements.evening_hour');
        $isEvening = $isToday && $now->hour >= $eveningHour;

        return [
            'items'       => $items,
            'takenCount'  => $takenCount,
            'total'       => count($items),
            'eveningHour' => $eveningHour,

            /*
             * The one flag the card actually branches on. Deliberately
             * false on a past day: yesterday's unticked supplement is a
             * fact to look at, not something to be nagged about — the tap
             * is still there to fix it.
             */
            'needsAttention' => $isEvening && count($items) > 0 && $takenCount < count($items),
        ];
    }
}
