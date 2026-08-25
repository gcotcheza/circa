<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MealItem;
use App\Models\MealMemory;
use Illuminate\Http\Request;
use App\Models\MealMemoryItem;
use Illuminate\Http\JsonResponse;
use App\Services\Meals\MealWriter;
use App\Http\Requests\RelogRequest;
use App\Services\Memory\MemoryRanker;
use Illuminate\Http\RedirectResponse;
use App\Services\Rollup\IntakeCalculator;
use App\Services\Rollup\SummaryRebuilder;

/**
 * The "log it again" picker: search history, tap once, it is on the day.
 *
 * Two verbs, two protocols, for the same reason as the photo path: `index`
 * is JSON under /api since it runs on every search-box keystroke and must
 * not navigate; `store` is an ordinary Inertia form since it ends an
 * interaction and should land on the day. /api here is a path prefix
 * inside the session-authenticated web group, not routes/api.php —
 * bootstrap/app.php renders exceptions as JSON for `api/*`, so a guest
 * gets a 401 rather than a 302 that fetch() would follow into the login
 * page's HTML; the real api group stays session-less for Health Auto
 * Export.
 *
 * NOTHING IS SEEDED: `meal_memory` starts empty and fills from confirmed
 * meals only — a picker pre-populated with plausible dinners nobody ate
 * would be a lie the app told about the user's own history.
 *
 * @phpstan-import-type MealItemRow from MealItem
 */
final class MealMemoryController extends Controller
{
    public function __construct(
        private readonly MemoryRanker $ranker,
        private readonly MealWriter $writer,
        private readonly SummaryRebuilder $rebuilder,
    ) {}

    /**
     * Ranked, searchable, limited.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Long enough for "chicken breast with white rice", short
            // enough that nobody is doing trigram maths on an essay.
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));

        $memories = $term === ''
            ? $this->ranker->frequent()
            : $this->ranker->search($term);

        return response()->json([
            'query'    => $term,
            'memories' => $this->ranker->propsFor($memories),
        ]);
    }

    /**
     * One tap: log this remembered meal onto the selected day.
     *
     * Goes through MealWriter like every other manual entry, making the
     * result an ORDINARY confirmed meal — editable, deletable, counted by
     * the same rollup — and letting this log teach the memory in turn: the
     * writer saves it, the observer records it, `times_logged` goes up.
     * The uuid comes from the client because this POST can be retried and
     * must land on the same meal, not a second copy.
     */
    public function store(RelogRequest $request, MealMemory $memory): RedirectResponse
    {
        $this->writer->create(
            uuid: $request->validated('uuid'),
            eatenAt: $request->eatenAt(),
            mealType: null,
            notes: null,
            items: $this->itemsFor($memory),
        );

        $date = $request->localDate();

        $this->rebuilder->now($date);

        return redirect()
            ->route('day', ['date' => $date])
            ->with('success', 'Logged again.');
    }

    /**
     * Remembered items in `meal_items` shape.
     *
     * Density BANDS copy across unchanged — collapsing to a midpoint would
     * be the lie this endpoint refuses to tell, since re-logging something
     * the app was never sure about doesn't make it sure. The portion is a
     * single number because that's what a habit is: one value.
     *
     * @return list<MealItemRow>
     */
    private function itemsFor(MealMemory $memory): array
    {
        return array_values($memory->items->map(static function (MealMemoryItem $item): array {
            $row = [
                'name'          => $item->name,
                'slug'          => $item->slug,
                'portion_g_min' => (float) $item->typical_portion_g,
                'portion_g_max' => (float) $item->typical_portion_g,
            ];

            foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
                $row[$nutrient.'_per_100g_min'] = (float) $item->{$nutrient.'_per_100g_min'};
                $row[$nutrient.'_per_100g_max'] = (float) $item->{$nutrient.'_per_100g_max'};
            }

            return $row;
        })->all());
    }
}
