<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use Tests\TestCase;
use App\Http\Requests\MealRequest;
use App\Http\Requests\RelogRequest;
use App\Http\Requests\MealPhotoRequest;
use App\Http\Requests\MealEstimateRequest;
use App\Http\Requests\MealProposalRequest;

/**
 * Every entry path answers "which day was this?" identically.
 *
 * The five requests used to carry their own copy of the arithmetic, so this
 * pins the thing the copies could disagree about rather than the trait's
 * internals: the same `date` + `time` must produce the same instant, in the
 * reporting timezone, whichever door the meal came through.
 */
final class ComposesEatenAtTest extends TestCase
{
    public function test_every_entry_path_composes_the_same_instant(): void
    {
        config(['health.timezone' => 'Europe/Amsterdam']);

        $payload = ['date' => '2026-08-20', 'time' => '07:30'];

        $requests = [
            MealRequest::create('/', 'POST', $payload),
            MealEstimateRequest::create('/', 'POST', $payload),
            MealProposalRequest::create('/', 'POST', $payload),
            MealPhotoRequest::create('/', 'POST', $payload),
            RelogRequest::create('/', 'POST', $payload),
        ];

        foreach ($requests as $request) {
            $eatenAt = $request->eatenAt();

            self::assertSame('2026-08-20 07:30:00', $eatenAt->format('Y-m-d H:i:s'), $request::class);
            self::assertSame('Europe/Amsterdam', $eatenAt->timezoneName, $request::class);
            self::assertSame('2026-08-20', $request->localDate(), $request::class);
        }
    }

    /**
     * The reason the client does not send a timestamp: at 00:30 in Auckland
     * it is still yesterday in UTC, and the meal belongs to the day the user
     * is looking at.
     */
    public function test_the_day_is_the_reporting_timezones_day(): void
    {
        config(['health.timezone' => 'Pacific/Auckland']);

        $request = MealRequest::create('/', 'POST', ['date' => '2026-08-20', 'time' => '00:30']);

        self::assertSame('2026-08-19', $request->eatenAt()->utc()->toDateString());
        self::assertSame('2026-08-20', $request->localDate());
    }

    /**
     * A re-log posts where the copy lands and nothing else — the food comes
     * from the row being copied. Anything more here would be a second place
     * for a meal's contents to be described.
     */
    public function test_a_relog_asks_only_where_the_copy_lands(): void
    {
        self::assertSame(['uuid', 'date', 'time'], array_keys((new RelogRequest)->rules()));
    }
}
