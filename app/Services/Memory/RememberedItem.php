<?php

declare(strict_types=1);

namespace App\Services\Memory;

/**
 * One food in a confirmed meal, reduced to what is worth remembering.
 *
 * Densities keep their min/max band — re-logging something uncertain
 * doesn't make it certain — while the portion collapses to ONE number:
 * a habitual portion is a memory, not an uncertainty. The user doesn't
 * eat "140-210 g of rice," they eat their usual amount, and the review
 * screen is where they adjust it if today differs.
 *
 * @phpstan-type Densities array{min: float, max: float}
 */
final readonly class RememberedItem
{
    /**
     * @param  array<string, array{min: float, max: float}>  $densities  nutrient => per-100g band
     */
    public function __construct(
        public string $name,
        public string $slug,
        public float $portionG,
        public array $densities,
    ) {}

    public function density(string $nutrient, string $end): float
    {
        return $this->densities[$nutrient][$end] ?? 0.0;
    }
}
