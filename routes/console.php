<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Console\Scheduling\Schedule as Scheduling;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Step 5's first clock-driven work — needs `schedule:run` ticking every
| minute.
|
| A COMPOSE SERVICE, NOT A HOST CRON: cron would `docker compose exec` in,
| run as whichever user owns the crontab (how Scribly's cache files went
| root-owned) and live outside the file describing this stack. `scheduler`
| (`php artisan schedule:work`) restarts with the stack, runs as the app uid
| like every container, needs no host state — and self-ticks rather than
| needing cron to call `schedule:run`, the shape compose supervises well.
|
*/

/*
 * TDEE back-calculation (step 7).
 *
 * 03:40 local — the window ends on the current local day, so it only
 * slides once Europe/Amsterdam has rolled over, and the 23:00-00:00
 * hourly export lands after midnight with its summary rebuild another
 * 30s behind. 00:05 would catch a window missing its last hour.
 *
 * By 03:40 yesterday is settled and the number is fresh before
 * breakfast; it follows photos:prune (03:20) rather than competing.
 *
 * Idempotent (a no-op recompute doesn't even touch `computed_at`), so a
 * double fire after restart is free — `withoutOverlapping` is about not
 * doing the work twice, not about protecting anything from it.
 */
Schedule::command('tdee:estimate')
    ->dailyAt('03:40')
    ->timezone(config('health.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Daily stress scores.
 *
 * 03:50 local, ten minutes behind tdee:estimate for the same reason: a
 * day's score is final only once Europe/Amsterdam has ended AND its last
 * hour's export has landed. Midnight would score a day missing its
 * 23:00-00:00 reading, and since the level is a MEDIAN, a missing
 * evening hour biases it upward — exports run latest on the low hours.
 *
 * Follows TDEE, doesn't race it: both read `health_metrics` over long
 * windows on a box with no swap.
 *
 * Recomputes a TRAILING WINDOW, not just yesterday — scored against the
 * 60 days behind it, so a late export shifts every later day (see
 * RebuildStressCommand). Idempotent: a double fire after restart is a
 * no-op.
 */
Schedule::command('stress:rebuild')
    ->dailyAt('03:50')
    ->timezone(config('health.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Build-asset retention (the blank-reload fix).
 *
 * `vite build` no longer empties public/build (vite.config.js) because a
 * deploy that deletes the previous build's chunks breaks every page
 * still open across it; the deploy runs this command right after the
 * asset build to prune instead.
 *
 * Belt to that braces: a deploy step can be forgotten, and with
 * `emptyOutDir: false` a forgotten prune means the directory grows
 * forever, not just "old files hung around". A daily run bounds the
 * worst case at one day of extra chunks.
 *
 * 03:10, ahead of photos:prune (03:20) — far cheaper (a manifest read
 * and a handful of unlinks), no reason to queue behind an hour of image
 * deletion. Idempotent: a rebuild-free day re-reads the same manifest
 * and deletes nothing.
 */
Schedule::command('build:retain')
    ->dailyAt('03:10')
    ->timezone(config('health.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The weekly health report (step 9).
 *
 * MONDAY 04:10 LOCAL — every part load-bearing.
 *
 * MONDAY: covers the previous Monday-Sunday, and a week still being
 * lived isn't reportable — `ReportRange::previousWeek` is the command's
 * no-arg default, so the schedule entry reads as what it does.
 *
 * 04:10, twenty minutes behind stress:rebuild: the report reads what
 * that job writes, the same HRV history through the same calculator.
 * Together they'd hold a year of `health_metrics` twice on a box with no
 * swap, and score Sunday against a baseline still settling.
 *
 * By 04:10 the week is closed (Sunday's export after midnight, rebuild
 * 30s behind, TDEE 03:40, stress 03:50) — the report runs last because
 * it's the only thing reading all of it.
 *
 * `withoutOverlapping` guards against paying twice, not safety: the
 * command is idempotent by construction — a weekly key derives from the
 * week covered, so a rerun finds the row and dispatches nothing
 * (App\Services\Report\ReportLauncher).
 */
Schedule::command('report:generate')
    ->weeklyOn(Scheduling::MONDAY, '04:10')
    ->timezone(config('health.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('photos:prune')
    // 03:20 local: after midnight rollups, before breakfast photos, off
    // the hour so it doesn't compete with every other cron on the box.
    ->dailyAt('03:20')
    ->timezone(config('health.timezone'))
    // Deleting twice is harmless and the command is idempotent, but a
    // second copy mid-chunk would duplicate the work for nothing.
    ->withoutOverlapping()
    ->onOneServer();
