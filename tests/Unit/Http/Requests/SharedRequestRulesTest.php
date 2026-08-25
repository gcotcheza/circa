<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use Tests\TestCase;
use App\Http\Requests\MealRequest;
use App\Http\Requests\MealPhotoRequest;
use App\Http\Requests\MealEstimateRequest;
use App\Http\Requests\MealProposalRequest;
use App\Http\Requests\MealReanalyzeRequest;
use App\Http\Requests\MealReestimateRequest;
use App\Http\Requests\SupplementLabelRequest;

/**
 * The rules several requests are supposed to share, pinned where they can
 * only be shared once.
 *
 * These were literals in four files. A copy that drifts is invisible in any
 * one of them — the failure is a figure one entry path accepts and another
 * refuses, which the user experiences as the app contradicting itself — so
 * the assertions are that the ceiling MOVES WITH THE CONFIG, not that it is
 * any particular number today.
 */
final class SharedRequestRulesTest extends TestCase
{
    public function test_every_item_ceiling_comes_from_config(): void
    {
        config([
            'health.food.max_kcal_absolute'  => 12345,
            'health.food.max_macro_absolute' => 678,
            'health.food.max_portion_g'      => 9012,
        ]);

        $manual = MealRequest::create('/', 'POST')->rules();
        $estimate = MealEstimateRequest::create('/', 'POST')->rules();
        $proposal = MealProposalRequest::create('/', 'POST')->rules();
        $reanalyze = MealReanalyzeRequest::create('/', 'POST')->rules();
        $restimate = MealReestimateRequest::create('/', 'POST')->rules();

        // Saved shape: absolutes in quick mode, a weight in per-100g mode.
        self::assertSame('max:12345', $this->ceiling($manual, 'items.*.kcal'));
        self::assertSame('max:678', $this->ceiling($manual, 'items.*.protein'));
        self::assertSame('max:678', $this->ceiling($manual, 'items.*.carbs'));
        self::assertSame('max:678', $this->ceiling($manual, 'items.*.fat'));
        self::assertSame('max:9012', $this->ceiling($manual, 'items.*.grams'));
        self::assertSame('max:12345', $this->ceiling($estimate, 'items.*.kcal'));

        // Proposed shape: the same ceilings on both ends of every band.
        foreach (['kcal', 'protein_g', 'carbs_g', 'fat_g', 'portion_g'] as $field) {
            $expected = match ($field) {
                'kcal'      => 'max:12345',
                'portion_g' => 'max:9012',
                default     => 'max:678',
            };

            self::assertSame($expected, $this->ceiling($proposal, "items.*.{$field}_min"), $field);
            self::assertSame($expected, $this->ceiling($proposal, "items.*.{$field}_max"), $field);
        }

        // Sheet-givens shape: one value per claim, same ceilings again.
        foreach ([$reanalyze, $restimate] as $rules) {
            self::assertSame('max:12345', $this->ceiling($rules, 'items.*.kcal'));
            self::assertSame('max:678', $this->ceiling($rules, 'items.*.protein_g'));
            self::assertSame('max:678', $this->ceiling($rules, 'items.*.carbs_g'));
            self::assertSame('max:678', $this->ceiling($rules, 'items.*.fat_g'));
            self::assertSame('max:9012', $this->ceiling($rules, 'items.*.portion_g'));
        }
    }

    /** A plate and a bottle label are the same upload, refused in the same words. */
    public function test_a_plate_and_a_label_accept_the_same_photograph(): void
    {
        $plate = MealPhotoRequest::create('/', 'POST');
        $label = SupplementLabelRequest::create('/', 'POST');

        self::assertSame($this->asArray($plate->rules()['photo']), $this->asArray($label->rules()['photo']));
        self::assertSame($plate->messages(), $label->messages());
        self::assertSame('max:'.config('health.vision.max_upload_kb'), $this->ceiling($plate->rules(), 'photo'));
    }

    /** One shape for the key that claims one paid analysis. */
    public function test_every_analysis_key_is_asked_for_identically(): void
    {
        $rules = [
            MealPhotoRequest::create('/', 'POST')->rules(),
            MealEstimateRequest::create('/', 'POST')->rules(),
            MealReanalyzeRequest::create('/', 'POST')->rules(),
            MealReestimateRequest::create('/', 'POST')->rules(),
            SupplementLabelRequest::create('/', 'POST')->rules(),
        ];

        foreach ($rules as $rule) {
            self::assertSame(['required', 'string', 'min:8', 'max:128'], $this->asArray($rule['idempotency_key']));
        }
    }

    /**
     * The single `max:` rule on a field, as the string the validator sees —
     * comparing that rather than the number proves the config actually
     * reaches the rule.
     *
     * @param  array<string, mixed>  $rules
     */
    private function ceiling(array $rules, string $field): string
    {
        self::assertArrayHasKey($field, $rules, $field);

        $ceilings = array_values(array_filter(
            $this->asArray($rules[$field]),
            static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'max:'),
        ));

        self::assertCount(1, $ceilings, $field);

        return $ceilings[0];
    }
}
