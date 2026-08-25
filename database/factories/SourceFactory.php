<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Real device strings, copied byte-for-byte out of the captured payloads —
 * curly apostrophes, no-break spaces, pipe-joined composites and all. Tests
 * that use anything tidier are not testing the data this app actually receives.
 *
 * @extends Factory<Source>
 */
final class SourceFactory extends Factory
{
    protected $model = Source::class;

    /** The observed watch string: U+2019 apostrophe, U+00A0 before "Watch". */
    public const WATCH = "Demo\u{2019}s Apple\u{00A0}Watch";

    public const PHONE = "Demo\u{2019}s iphone";

    public const COMPOSITE = "Demo\u{2019}s Apple\u{00A0}Watch|Demo\u{2019}s iphone";

    public function definition(): array
    {
        $raw = $this->faker->unique()->randomElement([
            self::WATCH,
            self::PHONE,
            'FITAGE',
            'Fitdays',
            'AutoSleep',
            'Blood Oxygen',
            self::COMPOSITE,
            '',
        ]);

        return [
            'raw_name'    => $raw,
            'name'        => $raw === '' ? 'Unknown source' : $raw,
            'slug'        => Source::slugFor($raw),
            'device_kind' => Source::kindFor($raw),
        ];
    }

    public function watch(): static
    {
        return $this->forRawName(self::WATCH);
    }

    public function phone(): static
    {
        return $this->forRawName(self::PHONE);
    }

    /** Both scale apps observed; either is a `scale`. */
    public function scale(string $rawName = 'FITAGE'): static
    {
        return $this->forRawName($rawName);
    }

    public function composite(): static
    {
        return $this->forRawName(self::COMPOSITE);
    }

    /** HAE emits an empty source for every apple_stand_hour datapoint. */
    public function empty(): static
    {
        return $this->forRawName('');
    }

    private function forRawName(string $rawName): static
    {
        return $this->state(fn (): array => [
            'raw_name'    => $rawName,
            'name'        => $rawName === '' ? 'Unknown source' : $rawName,
            'slug'        => Source::slugFor($rawName),
            'device_kind' => Source::kindFor($rawName),
        ]);
    }
}
