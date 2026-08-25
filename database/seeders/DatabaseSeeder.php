<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * There is still no demo data to seed.
 *
 * Every measurement is either real, from the phone, or typed by the
 * owner; inventing either puts fiction into the intake band and TDEE
 * window.
 *
 * `SupplementSeeder`: seeds no measurements, not one intake or tick —
 * only the CATALOGUE, five real products with manufacturers' published
 * figures and source URLs. Reference data, like `food_products` caches
 * from Open Food Facts, all editable; `supplement_intakes` is never
 * seeded, since that table records days that happened.
 *
 * `ProfileSeeder`: `profiles`' own migration says nothing seeds it, yet
 * this writes two of sixteen columns (goal, its note) — a transcription
 * of a sentence the owner said, not anything derived. Every other
 * column, including height the scale's BMI could supply, stays null.
 * Fills blanks only, never overwrites, so a deploy can't argue with the
 * profile screen.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(SingleUserSeeder::class);
        $this->call(SupplementSeeder::class);
        $this->call(ProfileSeeder::class);
    }
}
