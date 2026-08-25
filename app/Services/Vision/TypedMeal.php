<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Models\MealItem;
use App\Services\Rollup\IntakeCalculator;

/**
 * The meal as the user typed it: the input to a text estimate, and the last
 * word on the numbers that come back.
 *
 * Three jobs: describe() renders it into the evidence half of the prompt,
 * complete() puts back any item the model dropped, preserve() restores every
 * value the user supplied over everything else. The prompt asks the model
 * not to "correct" a figure the user typed; this class is why that's a
 * guarantee rather than a request — if the user read 210 kcal off a packet,
 * the app must store 210, not 208 or "about 210".
 *
 * Runs last, after memory too: MemoryPrefill is the app's own claim ("you've
 * had this before, and last time the rice was 180 g"), and it loses to this
 * one because memory summarizes past confirmations while the user typed this
 * thirty seconds ago. Order in the job is model -> mapper -> memory ->
 * preserve, and `memory_adjusted` is cleared on any item this class touched,
 * since the numbers are no longer history's. (MemoryPrefill wouldn't fight
 * anyway — it only replaces a density when its band is strictly narrower
 * than the proposal's, and a preserved value is zero-width — but running
 * last makes that a guarantee of this code, not a coincidence of the other.)
 *
 * @phpstan-import-type MealItemRow from MealItem
 */
final readonly class TypedMeal
{
    /**
     * @param  list<TypedItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $mealType = null,
        public ?string $time = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  validated `items.*` entries
     */
    public static function fromValidated(array $items, ?string $mealType, ?string $time, ?string $notes): self
    {
        return new self(
            items: array_values(array_map(TypedItem::fromValidated(...), $items)),
            mealType: $mealType,
            time: $time,
            notes: $notes,
        );
    }

    /**
     * The meal as the REVIEW SHEET currently has it — names, plus whatever the
     * user has stated by hand.
     *
     * What "Estimate this again" and "Analyse this photo again" send. A
     * TypedMeal like any other, so describe(), complete() and preserve()
     * treat a value corrected on the review sheet exactly as they treat one
     * typed on the meal sheet — the user doesn't distinguish the two, and
     * neither should this.
     *
     * @param  array<int, array<string, mixed>>  $items  validated `items.*` entries
     */
    public static function fromSheet(array $items, ?string $mealType, ?string $time, ?string $notes): self
    {
        return new self(
            items: array_values(array_map(TypedItem::fromSheet(...), $items)),
            mealType: $mealType,
            time: $time,
            notes: $notes,
        );
    }

    /**
     * @param  array<string, mixed>  $raw  as stored in `vision_requests.input_payload`
     */
    public static function fromArray(array $raw): self
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = array_filter((array) ($raw['items'] ?? []), is_array(...));

        return new self(
            items: array_values(array_map(TypedItem::fromArray(...), $items)),
            mealType: isset($raw['meal_type']) ? (string) $raw['meal_type'] : null,
            time: isset($raw['time']) ? (string) $raw['time'] : null,
            notes: isset($raw['notes']) ? (string) $raw['notes'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'     => array_map(static fn (TypedItem $item): array => $item->toArray(), $this->items),
            'meal_type' => $this->mealType,
            'time'      => $this->time,
            'notes'     => $this->notes,
        ];
    }

    /** True when at least one item has no calorie figure — i.e. there is something to estimate. */
    public function hasBlanks(): bool
    {
        foreach ($this->items as $item) {
            if ($item->needsEstimate()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `meal_items` rows for this meal, exactly as an ordinary save writes them.
     *
     * @return list<MealItemRow>
     */
    public function toRows(): array
    {
        return array_map(static fn (TypedItem $item): array => $item->toRow(), $this->items);
    }

    /**
     * The evidence half of the prompt: the meal in the user's own words.
     *
     * States what was given and what was not, because "no figures given" and
     * "0 kcal" are the difference between a question and an answer, and the
     * model must be told which one it's looking at in a form it can't
     * misread as data.
     */
    public function describe(): string
    {
        $lines = ['MEAL AS THE USER TYPED IT', ''];

        $context = [];

        if ($this->mealType !== null && $this->mealType !== '') {
            $context[] = "meal type: {$this->mealType}";
        }

        if ($this->time !== null && $this->time !== '') {
            $context[] = "eaten at {$this->time}";
        }

        $lines[] = $context === [] ? 'No meal type or time given.' : ucfirst(implode(', ', $context)).'.';

        if ($this->notes !== null && trim($this->notes) !== '') {
            $lines[] = 'Notes from the user: '.trim($this->notes);
        }

        $lines[] = '';
        $lines[] = 'Items:';

        foreach ($this->items as $index => $item) {
            $lines[] = sprintf('%d. %s', $index + 1, $item->name === '' ? '(unnamed)' : $item->name);

            foreach ($this->describeItem($item) as $detail) {
                $lines[] = '   - '.$detail;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Put back anything the model did not return — and only what the model
     * was not entitled to replace.
     *
     * The rule: a typed item (one the user put a NUMBER on) is data, and is
     * restored verbatim if the answer didn't mention it — ProposalWriter
     * replaces a meal's items wholesale, so a forgotten item would otherwise
     * be SILENTLY DELETED, with nothing on screen for confirmation to catch.
     * A blank item (a name and nothing else) is a DESCRIPTION whose answer
     * is the proposal — it may be renamed, corrected or split into
     * components, and must not survive alongside its own decomposition.
     *
     * The test for "decomposed" is whether the answer contains anything NOT
     * already one of the user's own lines — naively never restoring a blank
     * item would throw food away, e.g. user types "rice, 150 g at 130
     * kcal/100 g" and "sandwich"; the model answers about the rice only, so
     * nothing replaced the sandwich and dropping it would delete a typed
     * line. Example: typed "rice 150g@130" (typed) + "sandwich" (blank) — if
     * the answer is rice plus two slices of bread and butter, those two are
     * introduced and ARE the sandwich, so it's dropped; if the answer is
     * rice alone, nothing is introduced, so the sandwich is restored. A
     * typed item is restored in every case: no number the user entered is
     * ever the model's to replace.
     *
     * @param  list<ProposedItem>  $proposed
     * @return list<ProposedItem>
     */
    public function complete(array $proposed): array
    {
        /** @var array<string, list<int>> $available slug -> indexes of typed items not yet matched */
        $available = [];

        foreach ($this->items as $index => $item) {
            $available[$item->slug()][] = $index;
        }

        /** @var array<int, true> $matched */
        $matched = [];

        // Foods not matching a typed line — the only ones that can be a decomposition.
        $introduced = 0;

        foreach ($proposed as $item) {
            $slug = str($item->name)->slug()->value();

            if (($available[$slug] ?? []) !== []) {
                // Consumed as matched, so "rice" listed twice gets both lines back.
                $matched[array_shift($available[$slug])] = true;

                continue;
            }

            $introduced++;
        }

        $completed = $proposed;

        foreach ($this->items as $index => $item) {
            if (isset($matched[$index])) {
                continue;
            }

            if (! $item->hasTypedValues() && $introduced > 0) {
                // A description, and the answer contains its replacement.
                continue;
            }

            $completed[] = $item->toProposedItem();
        }

        return $completed;
    }

    /**
     * Restore every value the user supplied, over the model and over memory.
     *
     * Works on `meal_items` rows, not proposals, because it must be the LAST
     * thing that touches the numbers. The arithmetic is IntakeCalculator's
     * inverse, so the absolute the user typed reads back at BOTH ends of
     * whatever portion range survived: density_min = 100 * absolute /
     * portion_min, density_max = 100 * absolute / portion_max. A typed
     * density (per-100 g mode) is written straight in, portion-independently
     * — a claim about the food that holds however much ends up on the plate.
     *
     * Only what the user genuinely entered counts. `meal_items` density
     * columns are NOT NULL, so a blank item and a genuinely typed 0 are both
     * 0.000 in the row; preserving from rows would pin every blank at zero
     * and show it back as a "Confirm 6 items" sheet reading 0-0 kcal. That's
     * why this class is built from `vision_requests.input_payload` (blanks
     * still null, captured before it became rows) rather than the rows — a
     * null here means the user said nothing. See TypedItem::hasTypedValues()
     * and MealEstimateController::typedItemFrom().
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function preserve(array $rows): array
    {
        $typed = [];

        foreach ($this->items as $item) {
            // A blank line has nothing to preserve, and would only compete with a real claim of the same name.
            if ($item->hasTypedValues() || $item->foodProductId !== null) {
                $typed[$item->slug()][] = $item;
            }
        }

        $preserved = [];

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');

            // Consumed as matched, so "rice" listed twice preserves each line against its own proposal.
            $item = ($typed[$slug] ?? []) === [] ? null : array_shift($typed[$slug]);

            $preserved[] = $item === null ? $row : $this->applyTo($row, $item);
        }

        return $preserved;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyTo(array $row, TypedItem $item): array
    {
        $changed = false;

        /*
         * The user's share beats the model's — it estimated the whole bar, and "I had half
         * of it" is a fact about the sitting no estimate can overturn. Not counted as a
         * change: a share isn't one of the NUMBERS being preserved, and flagging it would
         * mark every shared item memory-adjusted.
         */
        $row['share_fraction'] = $item->shareFraction;

        $portion = $item->claimedPortionG();

        if ($portion !== null && $portion > 0) {
            $row['portion_g_min'] = $portion;
            $row['portion_g_max'] = $portion;
            $changed = true;
        }

        $portionMin = max(1.0, (float) $row['portion_g_min']);
        $portionMax = max(1.0, (float) $row['portion_g_max']);

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $density = $item->claimedDensity($nutrient);

            if ($density !== null) {
                $row[$nutrient.'_per_100g_min'] = $density;
                $row[$nutrient.'_per_100g_max'] = $density;
                $changed = true;

                continue;
            }

            $absolute = $item->claimedAbsolute($nutrient);

            if ($absolute === null) {
                continue;
            }

            $row[$nutrient.'_per_100g_min'] = round(100 * $absolute / $portionMin, 3);
            $row[$nutrient.'_per_100g_max'] = round(100 * $absolute / $portionMax, 3);
            $changed = true;
        }

        if ($changed) {
            // The user's own numbers now — the "from history" badge would lie even if memory touched this row earlier.
            $row['memory_adjusted'] = false;
        }

        // Provenance survives an estimate: a scanned yoghurt whose weight the model guessed is still that yoghurt.
        if ($item->foodProductId !== null) {
            $row['food_product_id'] = $item->foodProductId;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function describeItem(TypedItem $item): array
    {
        $details = [];

        if ($item->basis === 'per_100g') {
            $details[] = $item->grams === null
                ? 'Portion: NOT GIVEN — estimate it.'
                : sprintf('Portion: %s g (GIVEN — use exactly this).', $this->format($item->grams));
        } else {
            $details[] = 'Portion: NOT GIVEN — estimate it in grams.';
        }

        $unit = $item->basis === 'per_100g' ? ' per 100 g' : ' for the whole item';

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $value = $item->values[$nutrient] ?? null;

            $label = $nutrient === 'kcal' ? 'Energy' : ucfirst($nutrient);
            $suffix = $nutrient === 'kcal' ? ' kcal' : ' g';

            $details[] = $value === null
                ? sprintf('%s: NOT GIVEN — estimate it.', $label)
                : sprintf('%s: %s%s%s (GIVEN — echo it back exactly).', $label, $this->format($value), $suffix, $unit);
        }

        return $details;
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
