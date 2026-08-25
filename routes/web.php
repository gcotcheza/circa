<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DayController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\TrendController;
use App\Http\Controllers\StressController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MealPhotoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MealMemoryController;
use App\Http\Controllers\SupplementController;
use App\Http\Controllers\HealthReportController;
use App\Http\Controllers\MealEstimateController;
use App\Http\Controllers\MealProposalController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ProductLookupController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\SupplementLabelController;
use App\Http\Controllers\SupplementIntakeController;

/*
|--------------------------------------------------------------------------
| Web routes (session-authenticated Inertia app)
|--------------------------------------------------------------------------
|
| Single user: look at a day, log what you ate, look at the trend.
|
| DELIBERATELY ABSENT: registration, password reset, email verification,
| account management — a route that doesn't exist can't be misconfigured
| or left enabled by a refactor (SPEC.md non-goals: "Multi-user/public
| signup"). User is created via `db:seed --class=SingleUserSeeder`.
|
| `/profile` is not an exception — it reaches no email/password, only one
| `profiles` row (DOB, height, diet, goals) so the report stops advising
| somebody it knows nothing about.
|
| `POST /api/ingest` / `GET /api/health` (routes/api.php) are untouched by
| any of this: that group has no session/CSRF/auth middleware and
| authenticates via the X-API-Key shared secret — the phone posts
| unattended and cannot hold a session.
|
*/

/*
 * The PWA shell (/manifest.webmanifest, /sw.js, /offline) lives in
 * routes/pwa.php with no middleware group — public, so a service worker
 * revalidated on every navigation doesn't write a `sessions` row each time.
 */

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');

    // Throttled by email+IP (AppServiceProvider) — one account, one password
    // to guess: this is the whole brute-force surface.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Home / post-login landing page; date rides as ?date=YYYY-MM-DD so day
    // navigation is a partial visit.
    Route::get('/', DayController::class)->name('day');

    Route::get('/trends', TrendController::class)->name('trends');

    /*
     * The person the rest of this database is about. Two routes: read the
     * form, write the row. Reached from the header, not the bottom nav
     * (already full at four items) — a profile is filled in once, not
     * opened on a Monday morning.
     *
     * Ordinary Inertia PUT, no throttle: costs nothing, calls nobody, one
     * row to write.
     */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    /*
     * The one account action this app has: change your own password.
     *
     * AUTHENTICATED and a CHANGE not a reset — `current_password` is the
     * security gate (PasswordUpdateRequest); no unauthenticated reset,
     * email flow or login-page link (SPEC.md unchanged; AuthenticationTest
     * 404s /register, /forgot-password, /reset-password).
     *
     * Plain Inertia PUT, OFF the offline queue (resources/js/lib/queue.js)
     * deliberately — a security action must reach the server directly,
     * never queued/replayed; only submitOrQueue()/enqueue() callers touch
     * that queue, and this form is neither, like logout.
     */
    Route::put('/profile/password', [PasswordController::class, 'update'])->name('password.update');

    /*
     * The written health report (step 9).
     *
     * Two Inertia GETs, three JSON endpoints — same split as everywhere
     * else: opening a report is a navigation worth a URL, starting one and
     * watching it happen must never navigate.
     *
     * `/report/{report}` binds on id, not uuid — unlike a meal (the client
     * generates it offline and must name it before the server sees it), a
     * report is server-created in response to a button; nothing to
     * reconcile.
     *
     * `throttle:report` is the tightest cost limiter in the app: a report
     * is the single most expensive call it makes, the one-at-a-time guard
     * already makes a double-tap free, and this ceilings a stuck retry
     * loop.
     */
    Route::get('/report', [HealthReportController::class, 'index'])->name('report.index');
    Route::get('/report/{report}', [HealthReportController::class, 'show'])->name('report.show');

    Route::post('/api/reports', [HealthReportController::class, 'store'])
        ->middleware('throttle:report')
        ->name('report.store');

    Route::get('/api/reports/{report}', [HealthReportController::class, 'state'])
        ->name('report.state');

    /*
     * The facts a report was written from, verbatim — the evidence behind
     * every claim in the prose. Exposed because a report that can't be
     * checked shouldn't be acted on; see HealthReportController::snapshot.
     */
    Route::get('/api/reports/{report}/snapshot', [HealthReportController::class, 'snapshot'])
        ->name('report.snapshot');

    Route::delete('/report/{report}', [HealthReportController::class, 'destroy'])
        ->name('report.destroy');

    /*
     * The stress monitor. `?week=` is any date inside the week to show —
     * same partial-visit shape as the day view's `?date=` — so a shared or
     * bookmarked link resolves to a fixed week rather than "seven days
     * ago", which would mean something different tomorrow.
     */
    Route::get('/stress', StressController::class)->name('stress');

    // Meals bind on `uuid` (Meal::getRouteKeyName) — the client-generated
    // id, so no internal database id is ever exposed to the PWA.
    Route::post('/meals', [MealController::class, 'store'])->name('meals.store');
    Route::put('/meals/{meal}', [MealController::class, 'update'])->name('meals.update');
    Route::delete('/meals/{meal}', [MealController::class, 'destroy'])->name('meals.destroy');
    Route::post('/meals/{meal}/repeat', [MealController::class, 'repeat'])->name('meals.repeat');

    /*
     * Barcode lookup for the scanner (step 4).
     *
     * Under /api because bootstrap/app.php renders exceptions as JSON for
     * `api/*` — a guest must get 401 JSON, not a 302 that fetch() follows
     * and hands back as a login page. It's in THIS file, not routes/api.php,
     * because the signed-in PWA calls it with its session cookie and it
     * belongs behind the same auth as everything else the user can see;
     * the api group stays session-less for Health Auto Export.
     *
     * The pattern is loose (digits, spaces, hyphens) so a mis-typed code
     * reaches the controller and comes back as a 422 explaining what a
     * barcode is, not a bare 404 from the router.
     */
    Route::get('/api/products/{barcode}', ProductLookupController::class)
        ->where('barcode', '[0-9 \-]{1,32}')
        ->middleware('throttle:products')
        ->name('products.show');

    /*
     * The photo path (step 5).
     *
     * Four of these are JSON under /api and one is an ordinary Inertia
     * form: uploading and polling happen while the user watches a page
     * that must not navigate, whereas confirming ends an edit and should
     * land back on the day. Same /api-vs-session-less reasoning as the
     * barcode lookup above.
     *
     * `throttle:vision` is on the two routes that can cost money — a
     * double-tap is already free (the idempotency key), so this ceilings
     * what a bug can spend before somebody notices.
     */
    Route::post('/api/meals/photo', [MealPhotoController::class, 'store'])
        ->middleware('throttle:vision')
        ->name('meals.photo.store');

    Route::get('/api/meals/{meal}/vision', [MealPhotoController::class, 'show'])
        ->name('meals.vision.show');

    /*
     * Re-analyse ONE plate. The photo is in the path because that's what
     * the request is about: a meal has several, and "try again" means
     * this one again — replacing its proposal and no other plate's items.
     */
    Route::post('/api/meals/{meal}/photos/{photo}/vision', [MealPhotoController::class, 'reanalyze'])
        ->middleware('throttle:vision')
        ->name('meals.vision.reanalyze');

    /*
     * Remove one plate. Unconfirmed items always go with it; CONFIRMED
     * items go only if `remove_items` says so — an item somebody tapped is
     * a record of what they ate, and deleting the picture is a statement
     * about the picture. See MealPhotoController::destroy.
     */
    Route::delete('/api/meals/{meal}/photos/{photo}', [MealPhotoController::class, 'destroy'])
        ->name('meals.photo.destroy');

    /*
     * The photographs themselves, streamed from a non-public disk behind
     * session auth — nothing under /storage reaches these bytes.
     *
     * The meal-level route keeps its original URL and serves the FIRST
     * plate, so an entry already in the PWA's HTTP cache still resolves
     * after this deploy.
     *
     * THREE ROUTES, TWO SIZES. The first two stream the full-size original
     * — 768px, ~190KB — what the review sheet shows and what the model was
     * sent. The third streams the 256px thumbnail written beside every
     * original since the first upload, and it's what the DAY CARD asks
     * for: drawn in a 96px slot, the original would be 14x the bytes for
     * no pixels. It's also the file that outlives the original —
     * retention deletes the full-size image and keeps the thumbnail — so
     * the card never has to care whether a meal is 90 days old.
     *
     * The thumbnail's URL names the plate rather than a position and its
     * bytes are written once, never rewritten — the only one of the three
     * cacheable for a year. See MealPhotoController.
     */
    Route::get('/api/meals/{meal}/photo', [MealPhotoController::class, 'photo'])
        ->name('meals.photo.show');

    Route::get('/api/meals/{meal}/photos/{photo}', [MealPhotoController::class, 'photoAt'])
        ->name('meals.photo.at');

    Route::get('/api/meals/{meal}/photos/{photo}/thumb', [MealPhotoController::class, 'thumb'])
        ->name('meals.photo.thumb');

    /*
     * Text estimation — "describe it" (the third entry mode).
     *
     * Shaped like the two photo routes above, not like POST /meals: it
     * claims a paid analysis, answers 202, and leaves a meal nobody has
     * agreed to yet. `throttle:vision` is the same limiter as the photo
     * path because it's the same bill; the idempotency key already makes
     * a double-tap free, so this ceilings a stuck retry loop.
     *
     * The second route is the manual trigger on an already-saved meal,
     * mirroring `meals.vision.reanalyze` down to the new-key semantics.
     */
    Route::post('/api/meals/estimate', [MealEstimateController::class, 'store'])
        ->middleware('throttle:vision')
        ->name('meals.estimate.store');

    Route::post('/api/meals/{meal}/estimate', [MealEstimateController::class, 'reestimate'])
        ->middleware('throttle:vision')
        ->name('meals.estimate.again');

    // Confirm (and edit) what the model proposed — from a photo or a
    // description. Ranges in, ranges stored: see MealProposalRequest for
    // why this isn't MealRequest.
    Route::put('/meals/{meal}/proposal', [MealProposalController::class, 'update'])
        ->name('meals.proposal.update');

    /*
     * Meal memory (step 6).
     *
     * The search runs on every keystroke and must not navigate, so it's
     * JSON under /api for the same reason as the barcode and vision
     * endpoints: a guest must get 401 JSON, not a 302 into the login
     * page's HTML. Logging one IS a navigation, an ordinary Inertia form.
     *
     * `throttle:memory` is light — nothing here costs money or touches a
     * third party, it's a trigram query over one user's dinners — but a
     * search box wired to `input` with no debounce is one bad commit away,
     * and 60/minute is far more typing than a human does while still
     * being a ceiling.
     */
    Route::get('/api/meal-memory', [MealMemoryController::class, 'index'])
        ->middleware('throttle:memory')
        ->name('memory.index');

    Route::post('/meal-memory/{memory}/log', [MealMemoryController::class, 'store'])
        ->name('memory.log');

    /*
     |--------------------------------------------------------------------------
     | Supplements (tier 1)
     |--------------------------------------------------------------------------
     |
     | Three groups, same split as the meal surface: anything the user
     | watches happen is JSON under /api, anything that ends an edit is an
     | ordinary Inertia form.
     |
     | The settings screen is reached from the day view's supplements card,
     | not the bottom nav — visited a handful of times a year, and a third
     | nav item would cost a tap on every screen forever to save one on the
     | day somebody buys a bottle.
     */

    Route::get('/supplements', [SupplementController::class, 'index'])->name('supplements.index');
    Route::post('/supplements', [SupplementController::class, 'store'])->name('supplements.store');
    Route::put('/supplements/{supplement}', [SupplementController::class, 'update'])->name('supplements.update');
    Route::delete('/supplements/{supplement}', [SupplementController::class, 'destroy'])->name('supplements.destroy');

    /*
     * The label photograph. `throttle:vision` because it's the same bill
     * as a plate — a double-tap is already free (the idempotency key), so
     * this ceilings what a stuck retry loop can spend.
     */
    Route::post('/api/supplements/label', [SupplementLabelController::class, 'store'])
        ->middleware('throttle:vision')
        ->name('supplements.label.store');

    /*
     * Poll one reading, keyed on the CLIENT's id for the photograph —
     * there's no supplement to name it after yet, since the whole point
     * of the review step is that nothing has been created. See
     * SupplementLabelController.
     */
    Route::get('/api/supplements/label/{clientId}', [SupplementLabelController::class, 'show'])
        ->where('clientId', '[0-9a-fA-F-]{36}')
        ->name('supplements.label.show');

    // The label itself, at 256px, streamed from the private disk behind
    // session auth. Nothing under /storage reaches these bytes.
    Route::get('/api/supplements/{supplement}/photo', [SupplementController::class, 'photo'])
        ->name('supplements.photo');

    /*
     * The tap. A PUT of the complete desired state — `{date, taken}` —
     * which is what makes it replay-safe on the offline queue without a
     * per-kind rule about verbs. See SupplementIntakeController.
     */
    Route::put('/api/supplements/{supplement}/intake', [SupplementIntakeController::class, 'update'])
        ->name('supplements.intake.update');

    /*
     * The client telling the server that it broke.
     *
     * This app draws every pixel in JavaScript from an empty `#app`, so a
     * failure during boot or render produces a blank white screen and NO
     * server-side symptom — 200 for the HTML, 200 for the bundle, nothing
     * in any log. The browser is the only witness; this is how it gets a
     * word in.
     *
     * Under `/api/` for the same reason as the routes above: an expired
     * session must come back as 401 JSON, not a 302 the reporter's
     * fetch() would follow into the login page and read as success —
     * which would mean the reporting silently stopping when it mattered.
     *
     * `throttle:client-errors` is 10/minute, the tightest limiter in the
     * app (see AppServiceProvider): defending against a render loop that
     * throws, the realistic shape of the bug it exists to catch.
     */
    Route::post('/api/client-errors', ClientErrorController::class)
        ->middleware('throttle:client-errors')
        ->name('client-errors.store');
});
