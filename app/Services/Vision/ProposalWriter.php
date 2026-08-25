<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Models\Meal;
use App\Enums\MealType;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The two writes that move a photo meal along: propose, then confirm.
 *
 * Both are scoped to one entry — the whole design. Item replacement used to
 * be wholesale, fine while a meal had one photograph, but a meal is now a
 * series of plates: confirming the dessert must APPEND to a dinner logged
 * forty minutes ago, and re-analysing it must discard only its own previous
 * answer. So every write names its scope (`meal_photo_id` for a plate, NULL
 * for the text path); items outside it — other plates, typed lines, barcode
 * scans — are never read, deleted or touched. Within the scope, replacement
 * stays wholesale: proposed items have no client-held identity, so matching
 * a re-analysis onto rows by name would merge two portions into one.
 *
 * `confirmed_at` is per item, and now it has to be: it already let a later
 * step accept three items while arguing about a fourth, and that step
 * arrived — a CONFIRMED meal can hold an unconfirmed proposal, so
 * "confirmed" is a fact about an item, `meals.status` only about the meal's
 * first commitment.
 */
final class ProposalWriter
{
    /**
     * Vision answered: write what it proposed for this entry.
     *
     * An EMPTY answer is still a proposal: a photo of a chair produces zero
     * items and an explanatory note, so the entry reaches `proposed` rather
     * than `failed` — it worked, and the answer was no.
     *
     * @param  int|null  $photoId  the plate this answer is about; null = the text path
     * @param  list<array<string, mixed>>  $rows  already in meal_items shape
     */
    public function propose(Meal $meal, ?int $photoId, array $rows, string $notes): Meal
    {
        return DB::transaction(function () use ($meal, $photoId, $rows, $notes): Meal {
            $this->replaceItems($meal, $photoId, $rows, confirmedAt: null);

            /*
             * A meal never goes backwards out of `confirmed`: rollup selects
             * on `Meal::scopeConfirmed()`, so moving a confirmed meal to
             * `proposed` because a dessert arrived would drop the main
             * course from the day's totals. The pending dessert isn't lost
             * by leaving status alone — it lives in `meal_photos` and its
             * own `vision_requests` row. See MealPhoto::entryState().
             */
            if ($meal->status !== MealStatus::Confirmed) {
                $meal->status = MealStatus::Proposed;
            }

            /*
             * The model's note is NOT the user's notes. This used to write
             * into `meals.notes`, which worked while that column was empty
             * (the photo path), but on the text path the user types both
             * description and notes, so it silently overwrote them with the
             * model's own reasoning — which Confirm then stored as if the
             * user had written it. Two speakers can't share one column:
             * `notes` is user-authored and only a user request may change
             * it; `model_notes` is the model's own commentary, never
             * editable, replaced by each new proposal. Written
             * unconditionally, empty string as null, so a silent
             * re-analysis doesn't leave a stale note beside numbers it no
             * longer describes. `meals.model_notes` is the last thing the
             * model said about the meal (what the day card shows); the
             * entry's own copy is what its review sheet reads, so three
             * plates analysed in sequence don't all quote the third one's
             * note.
             */
            $meal->model_notes = $notes === '' ? null : $notes;

            $meal->save();

            if ($photoId !== null) {
                MealPhoto::query()
                    ->whereKey($photoId)
                    ->update(['model_notes' => $notes === '' ? null : $notes]);
            }

            return $meal;
        });
    }

    /**
     * The user tapped Confirm on one entry.
     *
     * Everything else on the meal survives — earlier confirmed plates, other
     * plates awaiting review, anything typed or scanned by hand — which is
     * what makes a dinner a series of courses rather than one answer being
     * overwritten.
     *
     * @param  int|null  $photoId  the plate being confirmed; null = the text path
     * @param  list<array<string, mixed>>  $rows  already in meal_items shape
     */
    public function confirm(
        Meal $meal,
        ?int $photoId,
        array $rows,
        CarbonImmutable $eatenAt,
        ?string $mealType,
        ?string $notes,
    ): Meal {
        return DB::transaction(function () use ($meal, $photoId, $rows, $eatenAt, $mealType, $notes): Meal {
            $this->replaceItems($meal, $photoId, $rows, confirmedAt: CarbonImmutable::now());

            $meal->status = MealStatus::Confirmed;
            // UTC — Eloquent hands Postgres an offset-less string, and
            // `local_date` is generated from this column. See MealWriter.
            $meal->eaten_at = $eatenAt->utc();
            // Validated against the enum before it got here, and the cast
            // would call from() anyway — naming it keeps the column's type
            // visible at the assignment.
            $meal->meal_type = $mealType === null ? null : MealType::from($mealType);
            $meal->notes = $notes;
            $meal->save();

            return $meal->refresh();
        });
    }

    /**
     * Replace THIS ENTRY's items, leaving every other entry's alone.
     *
     * Scoped to the entry, not "the unconfirmed items": an entry has only
     * one answer at a time, and a new proposal supersedes the plate's
     * previous one whether or not the user had agreed to it — keeping old
     * rows beside new ones would put the same food on screen twice. What
     * protects the user is the SCOPE, not `confirmed_at`: other plates,
     * typed lines and barcode scans are outside it and untouched. A
     * proposal is only ever written when an answer arrives, so a failed
     * analysis leaves whatever was confirmed exactly where it was.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(Meal $meal, ?int $photoId, array $rows, ?CarbonImmutable $confirmedAt): void
    {
        $meal->items()
            ->when(
                $photoId === null,
                fn ($query) => $query->whereNull('meal_photo_id'),
                fn ($query) => $query->where('meal_photo_id', $photoId),
            )
            ->delete();

        foreach ($rows as $row) {
            $item = $meal->items()->make($row);

            // The two columns this writer decides, set on the model rather
            // than mixed into the answer's own attributes.
            $item->meal_photo_id = $photoId;
            $item->confirmed_at = $confirmedAt;

            $item->save();
        }
    }
}
