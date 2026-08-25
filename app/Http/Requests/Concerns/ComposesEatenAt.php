<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

/**
 * A meal's `date` and `time`, posted as two fields and composed into an
 * instant here.
 *
 * Not a timestamp from the client: the phone's clock and the app's
 * reporting timezone aren't necessarily the same, and the day a meal
 * belongs to is decided in the app timezone (`meals.local_date` bakes that
 * in). Composing server-side keeps that single-sourced across every entry
 * path — manual, estimated, photographed, confirmed, re-logged — so an
 * offline queue replaying a six-hour-old payload runs today's arithmetic
 * rather than the version it was queued under.
 */
trait ComposesEatenAt
{
    /** The instant the meal was eaten, in the app's reporting timezone. */
    public function eatenAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->string('date').' '.$this->string('time'),
            (string) config('health.timezone')
        );
    }

    /**
     * The day it counts against. Composed in the reporting timezone and read
     * back in it, so `meals.local_date` and the day the user is looking at
     * cannot disagree.
     */
    public function localDate(): string
    {
        return $this->eatenAt()
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();
    }
}
