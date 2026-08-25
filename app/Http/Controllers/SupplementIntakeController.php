<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supplement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use App\Services\Supplements\SupplementDay;
use App\Http\Requests\SupplementIntakeRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The tap.
 *
 * ONE VERB, ONE BODY, IDEMPOTENT BY CONSTRUCTION: `PUT {date, taken}`
 * rather than POST-to-create and DELETE-to-undo. It's the complete
 * desired state of one (supplement, day) pair, the shape the offline
 * queue already knows how to replay safely — same shape a meal confirm
 * uses, for the same reason: a queued action states how the world
 * should look, so sending it twice arrives in the same place.
 *
 * Two ways this could lose data, and how it doesn't:
 *
 *   A REPLAYED TICK — the unique index on (supplement_id, local_date)
 *   makes the second insert a no-op. `firstOrCreate` plus a catch on
 *   the constraint covers the race too, since two flushes can be in
 *   flight at once and "check then insert" isn't atomic.
 *
 *   A REPLAYED UNDO — deleting an already-gone row isn't an error and
 *   must not answer 404: the queue treats 404 as PERMANENT and would
 *   block the action, leaving a red badge for a tap that succeeded.
 *
 * So both directions answer 200 with the day's state, whatever found.
 *
 * The answer is the whole card for that date, not the one row that
 * changed, so the client redraws from one shape instead of patching
 * its own copy — the same argument VisionState makes for polling.
 */
final class SupplementIntakeController extends Controller
{
    public function __construct(private readonly SupplementDay $day) {}

    public function update(SupplementIntakeRequest $request, Supplement $supplement): JsonResponse
    {
        $date = $request->string('date')->value();

        if ($request->boolean('taken')) {
            $this->record($supplement, $date);
        } else {
            $supplement->intakes()->where('local_date', $date)->delete();
        }

        return response()->json([
            'date'        => $date,
            'supplements' => $this->day->props($date),
        ]);
    }

    /**
     * `taken_at` is NOW, and `local_date` is the day on screen.
     *
     * Usually the same day, allowed not to be — ticking last night's
     * magnesium over this morning's coffee is the most common reason
     * this endpoint is called at all. See the migration.
     */
    private function record(Supplement $supplement, string $date): void
    {
        try {
            $supplement->intakes()->firstOrCreate(
                ['local_date' => $date],
                ['taken_at' => CarbonImmutable::now()],
            );
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent flush of the same tap —
            // the winner's row says exactly what this one would have.
        }
    }
}
