<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Meal;
use App\Models\MealItem;
use Illuminate\Http\Request;
use App\Observers\MealObserver;
use App\Services\Pwa\BuildAssets;
use App\Observers\MealItemObserver;
use Illuminate\Support\Facades\View;
use App\Observers\MealMemoryObserver;
use App\Services\Report\ReportWriter;
use GuzzleHttp\Client as GuzzleClient;
use App\Services\Memory\MemoryRecorder;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Support\ServiceProvider;
use Anthropic\Client as AnthropicClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\Report\AnthropicReportWriter;
use App\Services\Vision\AnthropicVisionAnalyzer;
use Illuminate\Contracts\View\View as ViewContract;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The meal-memory recorder is a SINGLETON, not an optimisation:
         * it coalesces (saving a meal twice in one transaction must
         * record once) via an "already queued" set that lives on the
         * instance. An observer is resolved from the container on every
         * event, so a non-singleton recorder would hand each event a
         * fresh, empty set and the coalescing would silently do
         * nothing.
         */
        $this->app->singleton(MemoryRecorder::class);

        /*
         * The vision analyzer (step 5).
         *
         * Bound to the interface so the job under test never constructs
         * an HTTP client, and the SDK's exceptions, retries and
         * response shapes stop at one class instead of leaking into a
         * queue worker.
         *
         * The transporter is EXPLICIT rather than left to
         * php-http/discovery: the SDK's own `timeout` option is
         * advisory (never read), so only the PSR-18 client's timeout
         * actually stops a hung request — a queue worker blocked
         * forever on a socket is a meal stuck in `analyzing` forever.
         *
         * `maxRetries: 1` is the single retry for 429/5xx/connection
         * errors; the job's `tries = 1` on top means a refused photo
         * isn't paid for four times before anybody is told.
         */
        /*
         * The report writer (step 9).
         *
         * Bound to its own interface for the same reason the vision
         * analyzer is: the job, command and controller become testable
         * with no HTTP client anywhere, and the SDK's exceptions,
         * retries and response shapes stop at one class.
         *
         * A SEPARATE CLIENT FROM VISION'S — the whole reason it's
         * separate: this call carries a 300-second read timeout against
         * vision's 120, since sixteen thousand tokens of thinking and
         * answer takes minutes, and sharing vision's client would abort
         * an already-paid-for report. The transporter is explicit for
         * the same reason as vision's: the SDK's `timeout` option is
         * advisory and never read, so only the PSR-18 client's timeout
         * actually stops a hung request.
         */
        $this->app->singleton(ReportWriter::class, function (): ReportWriter {
            /** @var array<string, mixed> $report */
            $report = config('health.report');

            return new AnthropicReportWriter(
                client: new AnthropicClient(
                    apiKey: (string) config('services.anthropic.api_key'),
                    requestOptions: [
                        'transporter' => new GuzzleClient([
                            'connect_timeout' => (float) $report['connect_timeout'],
                            'timeout'         => (float) $report['timeout'],
                            'http_errors'     => false,
                        ]),
                        'maxRetries' => (int) $report['max_retries'],
                    ],
                ),
                logger: $this->app->make('log'),
                model: (string) $report['model'],
                maxTokens: (int) $report['max_tokens'],
                effort: (string) $report['effort'],
            );
        });

        $this->app->singleton(VisionAnalyzer::class, function (): VisionAnalyzer {
            /** @var array<string, mixed> $vision */
            $vision = config('health.vision');

            return new AnthropicVisionAnalyzer(
                client: new AnthropicClient(
                    apiKey: (string) config('services.anthropic.api_key'),
                    requestOptions: [
                        'transporter' => new GuzzleClient([
                            'connect_timeout' => (float) $vision['connect_timeout'],
                            'timeout'         => (float) $vision['timeout'],
                            'http_errors'     => false,
                        ]),
                        'maxRetries' => (int) $vision['max_retries'],
                    ],
                ),
                logger: $this->app->make('log'),
                model: (string) $vision['model'],
                maxTokens: (int) $vision['max_tokens'],
            );
        });
    }

    public function boot(): void
    {
        /*
         * Any change to a meal or its items invalidates that day's
         * summary. Wired here rather than with #[ObservedBy] on the
         * models so the whole rebuild-trigger surface is readable in
         * one place: this, plus the dispatch in ParseRawPayload, is
         * every way a `daily_summaries` row goes stale.
         */
        Meal::observe(MealObserver::class);
        MealItem::observe(MealItemObserver::class);

        /*
         * The build hash, for the root view only. It becomes
         * `<meta name="app-build">`, sent by resources/js/lib/report.js
         * with every client-side crash report. A composer rather than
         * `View::share` because it's one string wanted by exactly one
         * template, and md5-ing the manifest on a JSON-rendering
         * request would be work done for nobody.
         *
         * It's the field that makes a crash report answerable: an
         * error tagged with a since-redeployed build is a stale tab,
         * the same error tagged with the current one is a live bug —
         * without it the two are the same row.
         */
        View::composer('app', function (ViewContract $view): void {
            $view->with('buildVersion', app(BuildAssets::class)->version());
        });

        /*
         * Meal memory (step 6): ONE hook for four write paths — typed,
         * scanned, photographed-and-confirmed, re-logged from the
         * picker — because a recorder called from each writer would
         * work exactly until somebody added a fifth.
         *
         * Its own observer, not more methods on MealObserver: that
         * class answers "which day is now wrong?", this one "have I
         * eaten this before?" — they share nothing but the event.
         */
        Meal::observe(MealMemoryObserver::class);

        /*
         * Login throttle. One account exists, so this is the entire
         * password-guessing surface; five attempts a minute makes an online
         * attack pointless without locking the owner out for long.
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * Barcode lookups. Light, since it's one authenticated user in
         * a kitchen — but not absent, since a miss reaches Open Food
         * Facts (15 requests/minute/IP) and a stuck scan loop could
         * rate-limit the whole app. Cache hits are the overwhelming
         * majority; 30/minute is roughly twice human scan speed and
         * still under OFF's ceiling even if every one missed.
         */
        RateLimiter::for('products', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * Photo uploads and re-analyses (step 5). Tighter than the
         * barcode limiter for a different reason: a barcode miss costs
         * a free API call, a photo costs an Anthropic call. Ten a
         * minute is more meals than anybody eats, and the ceiling on
         * what a stuck retry loop can spend before somebody notices.
         * Idempotency already stops a double-tap from paying twice;
         * this stops a bug from paying sixty times.
         */
        RateLimiter::for('vision', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * The meal-memory picker's search (step 6). Loosest of the
         * three, since it's cheapest: a trigram query against one
         * user's own dinners, no third party, no spend. Limited at all
         * because the box searches as you type, so an accidentally
         * removed debounce shows up as a 429 rather than a query per
         * keystroke nobody notices.
         */
        RateLimiter::for('memory', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * Health report generation (POST /api/reports). Three a
         * minute — tighter than the vision limiter, since a report is
         * a far bigger call than a plate: sixteen thousand tokens of
         * ceiling against four, over a fact sheet itself thousands of
         * tokens.
         *
         * The one-at-a-time guard in HealthReportController already
         * makes a double-tap free, but it's a read followed by a
         * write, and two interleaving requests would both pass it —
         * this is the belt to that brace, and a hard ceiling on what
         * the race can cost.
         */
        RateLimiter::for('report', fn (Request $request): Limit => Limit::perMinute(3)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * Client-side crash reports (POST /api/client-errors). The
         * TIGHTEST limiter in the app, and its reasoning is the
         * opposite of the others: they protect a cost (a paid model
         * call, a third party's quota); this protects against the
         * reporter's own worst case — a component throwing on every
         * render, in a loop, unwatched. Ten a minute is plenty to
         * capture a fault (the client also caps itself per page load
         * and de-duplicates) and a hard ceiling on how fast a broken
         * build fills a table.
         *
         * A 429 here isn't worth surfacing: the client discards the
         * response either way, and the eleventh copy of a stack trace
         * says nothing the first ten didn't.
         */
        RateLimiter::for('client-errors', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * Health Auto Export's ingest (POST /api/ingest). The ONLY
         * unauthenticated write in the app, and the only limiter that
         * can't key on a user — the caller is a phone holding a shared
         * secret, no session. Per IP, therefore.
         *
         * This is against THE KEY GUESS: `hash_equals` makes the
         * comparison constant-time, but nothing made the attempt
         * expensive — an unmetered endpoint lets an attacker try the
         * secret as fast as the network allows, writing into a table
         * documented as permanent and never pruned. Sixty a minute
         * turns that into a rate at which no key is reachable.
         *
         * Sixty rather than tight, because the legitimate caller isn't
         * a person: the automation exports hourly, retries failures,
         * and can burst a backlog after a signal-free day — the
         * ceiling is about the attack, not this traffic. The
         * body-size cap in IngestController bounds any single request
         * that gets through.
         */
        RateLimiter::for('ingest', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->ip()));
    }
}
