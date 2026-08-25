<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Enums\MealStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use App\Services\Vision\ProposalWriter;
use App\Services\Rollup\SummaryRebuilder;
use App\Http\Requests\MealProposalRequest;
use App\Services\Vision\ProposedItemMapper;

/**
 * "Confirm" on ONE vision proposal — the tap the whole pipeline exists to
 * require. Until it happens items have a null `confirmed_at` and never
 * reach `daily_summaries`, since the rollup counts confirmed items of
 * confirmed meals.
 *
 * It confirms an ENTRY, not the meal: one plate's proposal, appended to
 * whatever the meal already holds, so confirming the main course and the
 * dessert twenty minutes later gives the dinner both, without the second
 * tap overwriting the first.
 *
 * An Inertia form, not JSON, unlike the upload beside it: by now the user
 * is finishing an edit and landing back on the day is the right end,
 * including the inline rebuild — queue coalescing is right for an
 * unattended export and wrong for a page somebody is watching.
 */
final class MealProposalController extends Controller
{
    public function __construct(
        private readonly ProposalWriter $writer,
        private readonly ProposedItemMapper $mapper,
        private readonly SummaryRebuilder $rebuilder,
    ) {}

    public function update(MealProposalRequest $request, Meal $meal): RedirectResponse
    {
        /*
         * Which plate is being confirmed: `client_id` names it, absent
         * means the text/manual entry. A meal can hold several proposals
         * at once, so a confirm that didn't say which one would append the
         * dessert's numbers over the main course's. An id not on this meal
         * is refused, not ignored — silently confirming the wrong scope
         * would write the user's edits onto items they weren't looking at.
         */
        $photo = $request->photoOn($meal);

        if ($photo === false) {
            return back()->with('error', 'That photo is not on this meal.');
        }

        // Confirming twice is not an error, it is an edit — but confirming a
        // plate that is still being analysed would race the job into
        // overwriting the items the user just agreed to.
        $stillAnalyzing = $photo !== null
            ? $photo->entryState() === 'analyzing'
            : $meal->status === MealStatus::Analyzing;

        if ($stillAnalyzing) {
            return back()->with('error', 'That photo is still being analysed.');
        }

        /*
         * The plate's share is recorded before the items are written, even
         * though the items already carry their own fractions (set from
         * this chip, or overridden individually) — nothing about today's
         * numbers depends on this line. What depends on it is everything
         * AFTERWARDS: re-analysing the plate needs to know it was shared,
         * and reopening the sheet needs the chip back on ½, not on All
         * with mysteriously small portions underneath.
         *
         * The note travels with it, same "absent means leave it alone"
         * rule: a confirm that didn't mention the note (an older queued
         * action, or the text path) must not erase one written since.
         * Confirming is agreeing to what's on screen; the note only
         * changes what a FUTURE analysis is told.
         */
        $hint = $request->hint();

        $photo?->forceFill([
            'share_fraction' => $request->entryShare()->fraction,
            ...($hint === false ? [] : ['hint' => $hint]),
        ])->saveQuietly();

        $original = CarbonImmutable::parse($meal->eaten_at)
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();

        $this->writer->confirm(
            meal: $meal,
            photoId: $photo?->id,
            // The same mapper the job used, so confirming an untouched
            // proposal is a no-op on the numbers: what the user saw is
            // what gets stored, to the gram.
            rows: $this->mapper->toRows($request->proposedItems()),
            eatenAt: $request->eatenAt(),
            mealType: $request->input('meal_type'),
            notes: $request->input('notes'),
        );

        if ($original !== $request->localDate()) {
            $this->rebuilder->now($original);
        }

        $this->rebuilder->now($request->localDate());

        return redirect()
            ->route('day', ['date' => $request->localDate()])
            ->with('success', 'Meal confirmed.');
    }
}
