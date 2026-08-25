<?php

declare(strict_types=1);

namespace App\Services\Supplements;

use App\Models\Supplement;
use App\Models\VisionRequest;
use Illuminate\Support\Facades\DB;
use App\Services\Vision\PhotoStore;

/**
 * Creating and editing a supplement from a reviewed label.
 *
 * `ReadSupplementLabel` stores the transcription in `vision_requests` and
 * stops — nothing reaches `supplements` until the user confirms, and what
 * gets written is what was ON SCREEN, not what was read. This matters more
 * here than for a meal proposal: a meal estimate visibly shows the model's
 * uncertainty as a range, while a label transcription looks like a fact, so
 * a wrong figure written straight in would be indistinguishable from a
 * right one forever. The transcription is a draft the user proof-reads
 * against the bottle in hand; this class only ever writes the proof-read
 * version.
 *
 * NUTRIENTS ARE REPLACED WHOLE ON AN EDIT, not diffed — a label line has no
 * identity beyond its position, so renaming one leaves nothing to match a
 * diff against. Replacing is right for a table whose rows are a copy of a
 * printed panel: an edit is a new copy of the panel.
 */
final class SupplementWriter
{
    public function __construct(private readonly PhotoStore $photos = new PhotoStore) {}

    /**
     * @param  list<array{nutrient: string, amount: float|string|null, unit: string|null}>  $nutrients
     * @param  bool  $active  false for the spare bottle in the cupboard — see the migration
     */
    public function create(
        string $name,
        ?string $brand,
        ?string $servingText,
        int $unitsPerDay,
        ?string $photoPath,
        array $nutrients,
        bool $active = true,
        ?VisionRequest $from = null,
    ): Supplement {
        return DB::transaction(function () use ($name, $brand, $servingText, $unitsPerDay, $photoPath, $nutrients, $active, $from): Supplement {
            $supplement = Supplement::query()->create([
                'name'          => $name,
                'brand'         => $brand,
                'serving_text'  => $servingText,
                'units_per_day' => $unitsPerDay,
                'photo_path'    => $photoPath,
                // Where these figures came from — a photographed label and a
                // typed one are different levels of evidence, and the row
                // records which. See the migration.
                'data_source' => $photoPath === null ? 'hand_entered' : 'label_photo',
                // Append: new bottles go to the bottom of the card, where
                // someone adding one expects to find it.
                'position' => (int) Supplement::query()->max('position') + 1,
                'active'   => $active,
            ]);

            $this->writeNutrients($supplement, $nutrients);

            /*
             * The audit row learns what its reading became. Written now
             * rather than at claim time, since the supplement didn't exist
             * then — the review IS the gap between the two. Rows that keep
             * a null are labels photographed and then abandoned, not
             * waste: "how often is a reading good enough to accept?" is
             * answerable only if the rejected ones are still there.
             */
            $from?->forceFill(['supplement_id' => $supplement->id])->save();

            return $supplement;
        });
    }

    /**
     * @param  list<array{nutrient: string, amount: float|string|null, unit: string|null}>  $nutrients
     */
    public function update(
        Supplement $supplement,
        string $name,
        ?string $brand,
        ?string $servingText,
        int $unitsPerDay,
        bool $active,
        array $nutrients,
        ?string $notes = null,
    ): Supplement {
        return DB::transaction(function () use ($supplement, $name, $brand, $servingText, $unitsPerDay, $active, $nutrients, $notes): Supplement {
            $supplement->forceFill([
                'name'          => $name,
                'brand'         => $brand,
                'serving_text'  => $servingText,
                'units_per_day' => $unitsPerDay,
                'active'        => $active,
                'notes'         => $notes,
                /*
                 * Saving the panel makes it yours: five rows arrived from a
                 * seeder claiming `manufacturer_published`, a claim about
                 * who's answerable for the figures, which stops being true
                 * once a human has been through the form and pressed Save
                 * — changed or merely accepted, the transcription is now
                 * theirs. `source_url` is left alone, so the source page
                 * stays one tap away.
                 */
                'data_source' => 'hand_entered',
            ])->save();

            $supplement->nutrients()->delete();

            $this->writeNutrients($supplement, $nutrients);

            return $supplement->refresh();
        });
    }

    /**
     * Throw the bottle away for good.
     *
     * Deliberately NOT what "stopped taking it" does (which sets `active =
     * false` and leaves every logged evening alone) — deleting cascades the
     * intakes away, rewriting honestly-logged adherence history, so this is
     * only reached from the settings screen where that question can be asked.
     */
    public function delete(Supplement $supplement): void
    {
        $path = $supplement->photo_path;

        DB::transaction(static function () use ($supplement): void {
            $supplement->delete();
        });

        // After the row, outside the transaction — a rolled-back delete that
        // had already unlinked the file would leave the supplement pointing
        // at nothing.
        $this->photos->forgetLabel($path);
    }

    /**
     * @param  list<array{nutrient: string, amount: float|string|null, unit: string|null}>  $nutrients
     */
    private function writeNutrients(Supplement $supplement, array $nutrients): void
    {
        $position = 0;

        foreach ($nutrients as $line) {
            $nutrient = trim($line['nutrient']);

            // A nameless line isn't a label line — it's what an empty
            // review-screen row posts when the user added one and thought
            // better of it.
            if ($nutrient === '') {
                continue;
            }

            $unit = trim((string) ($line['unit'] ?? ''));

            $supplement->nutrients()->create([
                'nutrient' => $nutrient,
                'amount'   => is_numeric($line['amount'] ?? null) ? $line['amount'] : null,
                'unit'     => $unit === '' ? null : $unit,
                'position' => $position++,
            ]);
        }
    }
}
