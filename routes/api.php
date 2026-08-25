<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\HealthStatusController;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Registered with the `api` prefix in bootstrap/app.php:
|   POST /api/ingest   Health Auto Export posts here (X-API-Key)
|   GET  /api/health   liveness + last-successful-ingest indicator
|
| /api/health is unauthenticated on purpose: exposes pipeline status and
| timestamps, never health data — what an uptime check must reach.
| Anything revealing a measurement belongs behind the app's own auth
| (see the controller for each field's rule).
|
| BOTH ROUTES ARE THROTTLED, AND NEITHER WAS: bootstrap/app.php defines no
| `throttleApi()`, so the `api` group carries no limiter of its own — this
| is the whole of it.
|
*/

/*
 * `throttle:ingest` — 60/minute per IP (AppServiceProvider).
 *
 * The only unauthenticated WRITE in the app, and it was unmetered —
 * X-API-Key was guessable at line rate (a 401 costs one connection) and
 * the never-pruned `raw_ingest_payloads` table fillable by anyone who
 * guessed it. 60/minute is sixty times the hourly export's need, so a
 * real phone (even catching up a backlog) never meets it.
 */
Route::post('/ingest', IngestController::class)
    ->middleware('throttle:ingest')
    ->name('api.ingest');

/*
 * A plain `throttle:60,1`, not a named limiter — nothing to decide
 * beyond the number. One GET runs eight aggregate queries and an uptime
 * check polls at most once a minute, so cost is bounded without an
 * inline definition to keep in sync.
 */
Route::get('/health', HealthStatusController::class)
    ->middleware('throttle:60,1')
    ->name('api.health');
