<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\HealthReport;
use Illuminate\Http\Request;
use InvalidArgumentException;
use App\Enums\HealthReportKind;
use Illuminate\Http\JsonResponse;
use App\Services\Report\ReportView;
use Illuminate\Http\RedirectResponse;
use App\Services\Report\ReportLauncher;
use App\Http\Requests\HealthReportRequest;

/**
 * The report tab: the list of past reports, one report open, and the button.
 *
 * Two GETs are Inertia pages, because opening a report IS a navigation — a URL
 * worth bookmarking, a back button worth pressing. Two are JSON under /api for
 * the reason every JSON route here is: the page must not navigate while a
 * generation is in flight, and a guest needs 401 with a body rather than a 302
 * that fetch() follows into the login page's HTML and reads as success.
 * `POST /api/reports` starts one and answers 202 with an id;
 * `GET /api/reports/{report}` is polled until the status stops moving.
 *
 * The generation route carries `throttle:report`, the tightest limiter here
 * after client-errors: a report is the most expensive single call the app
 * makes, the one-at-a-time guard already makes a double-tap free, and this caps
 * what a stuck retry loop can spend before anybody notices. See
 * AppServiceProvider.
 */
final class HealthReportController extends Controller
{
    public function __construct(
        private readonly ReportLauncher $launcher,
        private readonly ReportView $view,
    ) {}

    /**
     * The tab. Shows the newest readable report, or nothing but the form.
     */
    public function index(): Response
    {
        return Inertia::render('Report', $this->view->props(
            $this->view->newestReadable(),
            $this->launcher->inFlight(),
        ));
    }

    /**
     * One specific report, by id.
     *
     * A separate URL rather than `?report=`: it is the thing being looked at,
     * not a position in a browsable series like the day view's `?date=` or the
     * stress page's `?week=`.
     */
    public function show(HealthReport $report): Response
    {
        return Inertia::render('Report', $this->view->props(
            $report,
            $this->launcher->inFlight(),
        ));
    }

    /**
     * Start one.
     *
     * Answers 202 with the row's id and status — accepted, not done, which is
     * what 202 means and what the page needs to start polling.
     */
    public function store(HealthReportRequest $request): JsonResponse
    {
        /*
         * ONE AT A TIME, ENFORCED AT THE SERVER. The button disabling itself
         * is a courtesy — a lost response, a second tab or the phone in the
         * other pocket all defeat it. 409 rather than 422 because nothing is
         * wrong with the request; it conflicts with the resource's state, and
         * the body carries the id of the run already going so the page can
         * follow that instead.
         */
        $inFlight = $this->launcher->inFlight();

        if ($inFlight !== null) {
            return response()->json([
                'status'  => 'already_generating',
                'message' => 'A report is already being written. It will appear here when it is done.',
                'report'  => $this->view->summary($inFlight),
            ], 409);
        }

        try {
            $range = $request->range();
        } catch (InvalidArgumentException $e) {
            // Unreachable: HealthReportRequest already built the range in its
            // validator. Handled anyway — "unreachable" is a claim about
            // today's code, and a 500 here is a blank screen.
            return response()->json(['status' => 'invalid_range', 'message' => $e->getMessage()], 422);
        }

        ['report' => $report] = $this->launcher->launch($range, HealthReportKind::Manual, $request->focus());

        return response()->json([
            'status' => 'queued',
            'report' => $this->view->summary($report),
        ], 202);
    }

    /**
     * Poll one report's state.
     *
     * Deliberately thin — a status and the summary line the list needs. The
     * page reloads through Inertia once the status settles, so a rendered
     * report and a directly-visited URL share one code path.
     */
    public function state(HealthReport $report): JsonResponse
    {
        return response()->json([
            'report'  => $this->view->summary($report),
            'pending' => $report->isPending(),
        ]);
    }

    /**
     * The facts a report was written from.
     *
     * A report makes claims, and a claim nobody can check is a claim nobody
     * should act on. The stored snapshot IS the evidence — every number in the
     * prose came out of it, so "the report said my HRV fell 9%" becomes
     * verifiable rather than merely believable.
     *
     * JSON rather than a rendered screen: a debugging and auditing surface used
     * a handful of times a year, where a Vue component pretty-printing a deeply
     * nested document would be a screen to maintain in exchange for what the
     * browser's own JSON viewer already does well.
     */
    public function snapshot(HealthReport $report): JsonResponse
    {
        return response()->json($report->input_snapshot ?? []);
    }

    /**
     * Delete one.
     *
     * A report is a derived document, not a record of anything that happened to
     * the user's body: nothing downstream reads it and nothing is lost that
     * could not be generated again, so it deletes outright rather than archives.
     */
    public function destroy(Request $request, HealthReport $report): RedirectResponse
    {
        $report->delete();

        return redirect()->route('report.index')->with('success', 'Report deleted.');
    }
}
