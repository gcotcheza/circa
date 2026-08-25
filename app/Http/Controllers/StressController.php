<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Services\Reporting\StressView;

/**
 * The stress page: today's score, one week of daily scores, and the hour x
 * weekday grid.
 *
 * `?week=` is a date inside the week to show — a browsable position like the
 * day view's `?date=`, which keeps stepping back a partial Inertia visit.
 * Validation is lenient and lives in StressView: a malformed or future week
 * resolves to this week rather than 422ing, safely, since the parameter can
 * neither widen the query (the window is always seven days) nor reach the
 * database unparsed.
 */
final class StressController extends Controller
{
    public function __invoke(Request $request, StressView $view): Response
    {
        $week = $request->query('week');

        return Inertia::render('Stress', $view->props(is_string($week) ? $week : null));
    }
}
