<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meal;
use Carbon\CarbonImmutable;
use App\Http\Requests\MealRequest;
use App\Services\Meals\MealWriter;
use App\Http\Requests\RelogRequest;
use Illuminate\Http\RedirectResponse;
use App\Services\Rollup\SummaryRebuilder;

/**
 * Manual meal entry.
 *
 * Every write rebuilds the affected day's summary INLINE, then redirects to
 * it. Observers have already queued a delayed, coalesced rebuild for the same
 * date — the durable path, covering writes from outside this controller — but
 * a 30-second coalescing delay is wrong for a redirect someone is watching, so
 * the inline pass runs too. Both use the same idempotent builder: the
 * duplication costs a few queries and cannot differ.
 */
final class MealController extends Controller
{
    public function __construct(
        private readonly MealWriter $writer,
        private readonly SummaryRebuilder $rebuilder,
    ) {}

    public function store(MealRequest $request): RedirectResponse
    {
        ['created' => $created] = $this->writer->create(
            uuid: $request->string('uuid')->value(),
            eatenAt: $request->eatenAt(),
            mealType: $request->input('meal_type'),
            notes: $request->input('notes'),
            items: $request->items(),
        );

        return $this->back(
            $request->localDate(),
            // A duplicate submission is a success, not an error — the meal is
            // logged. "Logged already" is the only honest difference.
            $created ? 'Meal logged.' : 'Meal was already logged.'
        );
    }

    public function update(MealRequest $request, Meal $meal): RedirectResponse
    {
        $original = CarbonImmutable::parse($meal->eaten_at)
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();

        $this->writer->update(
            meal: $meal,
            eatenAt: $request->eatenAt(),
            mealType: $request->input('meal_type'),
            notes: $request->input('notes'),
            items: $request->items(),
        );

        // Moving a meal to another day leaves the old day wrong until rebuilt.
        if ($original !== $request->localDate()) {
            $this->rebuilder->now($original);
        }

        return $this->back($request->localDate(), 'Meal updated.');
    }

    public function destroy(Meal $meal): RedirectResponse
    {
        $date = CarbonImmutable::parse($meal->eaten_at)
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();

        $meal->delete();

        return $this->back($date, 'Meal deleted.');
    }

    /**
     * One-tap re-log of a previous meal onto the selected day.
     */
    public function repeat(RelogRequest $request, Meal $meal): RedirectResponse
    {
        $this->writer->repeat(
            source: $meal->load('items'),
            uuid: $request->validated('uuid'),
            eatenAt: $request->eatenAt(),
            mealType: null,
        );

        return $this->back($request->localDate(), 'Meal logged again.');
    }

    private function back(string $date, string $message): RedirectResponse
    {
        $this->rebuilder->now($date);

        return redirect()
            ->route('day', ['date' => $date])
            ->with('success', $message);
    }
}
