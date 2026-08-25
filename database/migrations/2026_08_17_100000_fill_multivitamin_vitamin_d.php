<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * The multivitamin's vitamin D row stops being empty.
 *
 * SupplementSeeder now seeds that row as 5 mcg — the one self-consistent
 * reading of a panel that prints "400 iu", "5 mcg" and "600% RI" for the
 * same line (the seeder's docblock has the argument). But the seeder
 * matches on name and SKIPS, deliberately, so it never revisits a shelf
 * that already exists — on those databases the row is filled here or
 * not at all.
 *
 * NARROW AND IDEMPOTENT BY THE `NULL` GUARD. Scoped to the multivitamin's
 * own rows, only where the amount is still blank, so a figure anybody
 * has since typed off their own bottle is left alone and a second run
 * writes nothing. A database seeded after this change matches nothing
 * and passes silently — what a fresh install should do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->fill();
    }

    /**
     * Public and separately callable so the rule can be asserted: a data
     * migration runs once and is unfalsifiable afterwards.
     */
    public function fill(): void
    {
        DB::table('supplement_nutrients')
            ->whereIn('supplement_id', DB::table('supplements')->where('name', 'Daily Complete 50')->select('id'))
            ->where('nutrient', 'like', 'Vitamine D%')
            ->whereNull('amount')
            ->update([
                // The form as the label names it, matching the seeder.
                'nutrient' => 'Vitamine D (cholecalciferol/ergocalciferol)',
                'amount'   => 5.0,
                'unit'     => 'mcg',
            ]);
    }

    public function down(): void
    {
        /*
         * Deliberately nothing.
         *
         * The state this replaced was "blank, pending an answer", and the
         * answer is now known — restoring it would throw that away, and
         * isn't distinguishable from blanking what the seeder now writes,
         * since a rollback says nothing about which code is deployed. No
         * read anywhere treats the empty amount as a signal, so leaving it
         * costs nothing.
         */
    }
};
