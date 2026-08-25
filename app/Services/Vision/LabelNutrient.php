<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * One line of a supplement-facts panel, as the model read it.
 *
 * `amount` is a float here, a decimal in the database — the conversion
 * happens once, on the way in. JSON has no decimal type, so the wire value
 * is a float regardless; the column is where that stops, not a value
 * object pretending the transport was something else.
 */
final readonly class LabelNutrient
{
    public function __construct(
        public string $nutrient,
        public ?float $amount,
        public ?string $unit,
    ) {}

    /**
     * @param  array<string, mixed>  $line
     */
    public static function fromArray(array $line): self
    {
        $unit = trim((string) ($line['unit'] ?? ''));

        return new self(
            nutrient: trim((string) ($line['nutrient'] ?? '')),
            amount: is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : null,
            unit: $unit === '' ? null : $unit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nutrient' => $this->nutrient,
            'amount'   => $this->amount,
            'unit'     => $this->unit,
        ];
    }
}
