<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Services\Reporting\TdeeCard;
use App\Services\Reporting\BodyTrend;
use App\Services\Reporting\TrendSeries;
use App\Services\Supplements\SupplementAdherence;

/**
 * The trends page: intake band against expenditure, the body card, steps.
 *
 * Range restricted to the configured set, not a free integer — `?range=3650`
 * would be a slow query and an unreadable chart, with no user-facing reason
 * for anything but the two buttons.
 *
 * Two range controls, deliberately: the top buttons are the DAY window (bars
 * the energy/steps charts draw, what "3 of 7 days" counts over — each extra
 * day is another server-built row); the body card's chips are the WEIGH-IN
 * window, out to fifteen months at no cost since BodyTrend ships every
 * weigh-in once and the card slices it client-side. Folding them together
 * would mean either a 470-bar steps chart or a weight trend stuck four weeks back.
 */
final class TrendController extends Controller
{
    public function __invoke(
        Request $request,
        TrendSeries $series,
        TdeeCard $tdee,
        SupplementAdherence $adherence,
        BodyTrend $body,
    ): Response {
        /** @var list<int> $allowed */
        $allowed = array_values((array) config('health.trends.ranges', [7, 28]));

        $range = (int) $request->query('range', (string) $allowed[0]);

        if (! in_array($range, $allowed, strict: true)) {
            $range = $allowed[0];
        }

        /*
         * The TDEE card is NOT scoped to the selected range — its window is the
         * estimator's own (28 days, or as much as has data). Switching the chart to
         * 7 days must not silently re-fit expenditure over a week: a slope from
         * seven days of a ±1 kg scale is noise, presented in the same card as the real one.
         */
        /*
         * `body` is the one prop that does NOT take $range — see the class
         * docblock. Merged, not listed, so the day series and weigh-in series stay visibly separate.
         */
        return Inertia::render('Trends', $series->props($range) + $body->props() + [
            'tdee' => $tdee->props(),

            /*
             * The supplements line — unlike the TDEE card, IS scoped to the selected
             * range: "6 of the last 7 evenings" is a question about a window the user
             * just chose, and means something different over four weeks.
             *
             * Null when there's nothing to say (no supplements on, or none existed
             * during the window) — a line reading "0/0" is worse than no line.
             */
            'supplements' => $adherence->forRange($range),
        ]);
    }
}
