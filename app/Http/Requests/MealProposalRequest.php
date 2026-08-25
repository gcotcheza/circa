<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Meal;
use App\Enums\MealType;
use App\Models\MealPhoto;
use Illuminate\Validation\Rule;
use App\Services\Vision\ProposedItem;
use App\Services\Meals\ConsumptionShare;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\NamesMealItems;
use App\Http\Requests\Concerns\ComposesEatenAt;

/**
 * Confirming (and editing) what vision proposed.
 *
 * Not MealRequest: that produces ZERO-WIDTH items (min == max) because a
 * human typed the number and stands behind it. Posting a vision proposal
 * through it would flatten every range to a point at the moment the user
 * agreed the range was right — laundering "180-260 kcal" into "180 kcal"
 * with a tap. So this request speaks the model's shape: absolute min/max
 * per nutrient plus a portion range, matching the review screen (nobody
 * looks at a photo and thinks in kcal per 100 g). Conversion to stored
 * densities is ProposedItemMapper — the SAME class the job uses, so
 * confirming an untouched proposal is a no-op on the numbers.
 *
 * Every `_max` carries `gte` its `_min`: a minimum dragged above its
 * maximum is not a range, and would put a negative-width band into the
 * day's quadrature.
 *
 * The numbers are optional here too, and that was once a production 422:
 * step 9 made every figure optional on the manual path (see
 * ValidatesMealItems) — a name-only item is legal and contributes 0. This
 * request predates that and kept `required` on all eight numbers, so the
 * same gesture had two different answers depending on which sheet was
 * open. On a real meal, the model proposed "iced dark soft drink
 * (partially in frame)" at low confidence, the user renamed it to the beer
 * it actually was and cleared the numbers they did not know — Confirm
 * answered "The server would not accept this." An empty
 * `<input type="number">` posts `""`, which fails `required`, and the
 * whole meal 422'd. "+ Add an item vision missed" was unusable too: it
 * starts every box null.
 *
 * The blanks cannot have come from the model — `PromptV1::schema()` marks
 * every numeric required, `ProposedItem::fromArray()` reads an absent one
 * as 0, `DailyView::itemProps()` emits floats — so a blank box is always
 * the user saying "I do not know this number," the claim step 9 exists to
 * let them make. A blank pairs with its other end in
 * `prepareForValidation()` below.
 */
final class MealProposalRequest extends FormRequest
{
    use ComposesEatenAt;
    use NamesMealItems;

    /**
     * The eight numeric pairs, and what an entirely blank one means.
     *
     * A blank NUTRIENT is 0 — the same reading `TypedItem::toRow()` gives it:
     * an item nobody can put a figure on contributes nothing and isn't
     * guessed at.
     *
     * A blank PORTION is 100 g, not a claim it weighed 100 g — it's the
     * BASIS an absolute-mode manual item is stored at (density and absolute
     * are the same number at 100 g), so a name-only item here is
     * byte-identical to the same name typed manually, and reopening it
     * shows an empty Quick box rather than a mysterious 1 g.
     */
    private const PAIRS = [
        'portion_g' => 100.0,
        'kcal'      => 0.0,
        'protein_g' => 0.0,
        'carbs_g'   => 0.0,
        'fat_g'     => 0.0,
    ];

    /**
     * Blanks resolved BEFORE the rules run, pair by pair.
     *
     * Pairwise rather than field by field, because the two ends are one claim:
     *
     *   both blank        the default above. The user has no figure at all.
     *   one end blank     mirror the other. "It is 350 g" is a zero-width
     *                     range, and it is what a user who fills one box means.
     *
     * Mirroring also keeps `gte` honest: resolving each end independently
     * would turn "I know the max is 350" into 100–350 — a range nobody
     * stated — or into 150–100 with the defaults reversed, failing
     * validation with a message about a range the user never typed.
     *
     * `''` and `null` are the same thing here: an empty `<input type="number">`
     * posts the first, `blankItem()` in the review sheet posts the second, and
     * both mean "left empty".
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (self::PAIRS as $field => $default) {
                $min = self::number($item[$field.'_min'] ?? null);
                $max = self::number($item[$field.'_max'] ?? null);

                $items[$index][$field.'_min'] = $min ?? $max ?? $default;
                $items[$index][$field.'_max'] = $max ?? $min ?? $default;
            }
        }

        $this->merge(['items' => $items]);
    }

    /** A posted figure, or null when the box was left empty. */
    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'date'      => ['required', 'date_format:Y-m-d'],
            'time'      => ['required', 'date_format:H:i'],
            'meal_type' => ['nullable', Rule::enum(MealType::class)],
            'notes'     => ['nullable', 'string', 'max:2000'],

            /*
             * Which plate's proposal this is. Absent = the text/manual entry
             * (items with no photograph). Nullable rather than required so
             * the text path, and any confirm queued by the previous app
             * version, still mean what they meant before: one proposal, and
             * this is it.
             */
            'client_id' => ['nullable', 'uuid'],

            /*
             * "I ate half of this plate" (the shared plate) — the ENTRY's
             * share, as the chip row on the review sheet is set. Stored on
             * `meal_photos` so a re-analysis re-applies it and the chip is
             * still on ½ tomorrow.
             *
             * Does NOT scale anything by itself: each item carries its own
             * `share_fraction` below, already set from this one, which is
             * what makes a per-item override possible ("we shared the rolls
             * but the pho was all mine") without entry and items disagreeing.
             */
            'share_fraction' => ['nullable', 'numeric', 'min:'.ConsumptionShare::MIN, 'max:1'],

            /*
             * The plate's note, as the sheet currently has it. Rides the
             * confirm as well as the re-analysis for the reason
             * `share_fraction` does: the two taps are independent. A user
             * who writes "that was 120 g of drained tuna" and taps Confirm
             * without re-running the numbers has still said something true
             * about the plate — losing it would mean typing it again next
             * time.
             *
             * Does NOT trigger an analysis: confirming is agreeing to the
             * numbers on screen, and re-running the model on the way past
             * would replace exactly those numbers.
             */
            'hint' => ['nullable', 'string', 'max:500'],

            // At least one: confirming an empty meal would create a confirmed
            // 0 kcal entry that says nothing. "No food in this photo" is a
            // Discard, which the review screen says.
            'items'              => ['required', 'array', 'min:1', 'max:40'],
            'items.*.name'       => ['required', 'string', 'max:191'],
            'items.*.confidence' => ['nullable', Rule::in(['low', 'medium', 'high'])],

            // How much of THIS line was eaten. Absent means all of it, which is
            // what every confirm queued before this feature existed says.
            'items.*.share_fraction' => ['nullable', 'numeric', 'min:'.ConsumptionShare::MIN, 'max:1'],

            /*
             * Still `required`, and still meaningful: `prepareForValidation()`
             * already filled every blank pair, so this now asserts the
             * normalisation ran — if it's ever removed, confirm fails loudly
             * here rather than quietly storing zeros the user never saw.
             */
            'items.*.portion_g_min' => ['required', 'numeric', 'min:0', 'max:'.config('health.food.max_portion_g')],
            'items.*.portion_g_max' => ['required', 'numeric', 'min:0', 'max:'.config('health.food.max_portion_g'), 'gte:items.*.portion_g_min'],
        ];

        $maxKcal = config('health.food.max_kcal_absolute');
        $maxMacro = config('health.food.max_macro_absolute');

        foreach ([
            'kcal'      => $maxKcal,
            'protein_g' => $maxMacro,
            'carbs_g'   => $maxMacro,
            'fat_g'     => $maxMacro,
        ] as $field => $ceiling) {
            $rules["items.*.{$field}_min"] = ['required', 'numeric', 'min:0', 'max:'.$ceiling];
            $rules["items.*.{$field}_max"] = ['required', 'numeric', 'min:0', 'max:'.$ceiling, "gte:items.*.{$field}_min"];
        }

        return $rules;
    }

    /**
     * An inverted range, said as a sentence.
     *
     * `gte` has always guarded these pairs: a band whose minimum is above
     * its maximum is not a range, and the day's quadrature would take its
     * negative width as real uncertainty. What the user got for it was
     *
     *     "The items.0.portion_g_max field must be greater than or equal to 100."
     *
     * at the bottom of a sheet they were scrolled into the middle of — a
     * field name, an index, no way to tell which of six items it meant.
     *
     * The review sheet now drags the other end along as the number is
     * typed (`dragOtherEnd` in ProposalReview.vue), so reaching here means
     * an old client, a replayed offline confirm, or something hand-rolled
     * — exactly when a message has to stand on its own.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.portion_g_max.gte' => 'One item has a portion that ends below where it starts. '
                .'The smaller number goes in the first box.',
            'items.*.kcal_max.gte' => 'One item has a calorie range that ends below where it starts. '
                .'The smaller number goes in the first box.',
            'items.*.protein_g_max.gte' => 'One item has a protein range that ends below where it starts. '
                .'The smaller number goes in the first box.',
            'items.*.carbs_g_max.gte' => 'One item has a carbs range that ends below where it starts. '
                .'The smaller number goes in the first box.',
            'items.*.fat_g_max.gte' => 'One item has a fat range that ends below where it starts. '
                .'The smaller number goes in the first box.',
        ];
    }

    /**
     * The plate this confirm is about.
     *
     * Three answers, all meaningful:
     *   MealPhoto  confirm this entry, appending to the meal.
     *   null       no `client_id`: the text/manual entry (items with no photo).
     *   false      a `client_id` not on this meal — refuse, rather than
     *              quietly writing the user's edits onto a different scope.
     *
     * Legacy shape handled too: a confirm queued by the previous app
     * version carries no `client_id`, and a photo meal with exactly one
     * plate has exactly one proposal, so that's the one it meant.
     */
    public function photoOn(Meal $meal): MealPhoto|false|null
    {
        $clientId = $this->string('client_id')->value();

        if ($clientId === '') {
            $photos = $meal->photos()->get();

            return $photos->count() === 1 ? $photos->first() : null;
        }

        return $meal->photos()->where('client_id', $clientId)->first() ?? false;
    }

    /**
     * @return list<ProposedItem>
     */
    public function proposedItems(): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = $this->validated()['items'];

        return array_values(array_map(
            static fn (array $item): ProposedItem => ProposedItem::fromArray($item),
            $raw
        ));
    }

    /**
     * The share the ENTRY's chip row is set to.
     *
     * Recorded on the plate rather than inferred from the items, because the
     * two genuinely differ: a plate set to ½ with the pho overridden back to
     * All has items at both fractions, and the chip has to come back on ½.
     */
    public function entryShare(): ConsumptionShare
    {
        return ConsumptionShare::of($this->input('share_fraction'));
    }

    /**
     * The note the sheet is holding, or false when it did not mention one.
     *
     * Three answers, and the middle one is why this isn't just a nullable
     * string: a confirm queued by the previous app version carries no
     * `hint` key at all, and replaying it must not wipe a note written
     * since. `''` IS a mention — the user clearing the box — and reaches
     * the column as NULL.
     */
    public function hint(): string|false|null
    {
        if (! $this->has('hint')) {
            return false;
        }

        // Not `?:` — a note of "0" is falsy in PHP, and only an EMPTY string
        // means the user cleared the box.
        $hint = trim($this->string('hint')->value());

        return $hint === '' ? null : $hint;
    }
}
