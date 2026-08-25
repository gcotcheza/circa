<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use App\Services\Reporting\DailyView;
use Carbon\Exceptions\InvalidFormatException;

/**
 * The daily view — the app's home.
 *
 * The date is a query parameter, not a path segment, so swiping between days
 * is one `router.get('/', { date })` with `preserveState`, and a bookmark of
 * "yesterday" stays pinned to that day. A missing or unparseable date falls
 * back to today rather than 404ing: a single-user PWA opened from the home
 * screen should show the food log, not an error page.
 */
final class DayController extends Controller
{
    public function __invoke(Request $request, DailyView $view): Response
    {
        return Inertia::render('Day', $view->props($this->date($request)));
    }

    private function date(Request $request): string
    {
        $tz = (string) config('health.timezone');

        $requested = $request->query('date');

        if (! is_string($requested) || $requested === '') {
            return CarbonImmutable::now($tz)->toDateString();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $requested, $tz);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        // Carbon throws on a bad format in strict mode and returns null out of
        // it; both mean the same thing to a bookmark, so both land here.
        return ($parsed ?? CarbonImmutable::now($tz))->toDateString();
    }
}
