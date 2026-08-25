<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Supplement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The five bottles the app ships with, ready to tick on first launch.
 *
 * WHY THE APP SHIPS WITH DATA IN IT. The label-photograph flow works and
 * stays — but for this app's one user it means five photographs, five
 * review screens and ~10 minutes of proof-reading to arrive at figures
 * the manufacturers already publish in full: a chore with a camera in
 * it, not a setup flow. So the five are seeded, and photographing
 * becomes what it should have been from the start — the way you add the
 * SIXTH.
 *
 * IDEMPOTENT ON `name`: invoked once at deploy and again by anyone who
 * re-runs the seeders, so a second run must not double up. Matches on
 * name and skips rather than updates — by the second run the user may
 * have corrected a line against the physical bottle, and overwriting
 * that would be the app arguing with the person holding the product.
 *
 * THE FIGURES FOLLOW ONE RULE: every amount is copied from a published
 * panel WITHOUT ARITHMETIC, for exactly the serving that panel states.
 * `serving_text` records that serving verbatim, `units_per_day` counts
 * those servings, so the daily total is amount x units_per_day and
 * nothing here is ever divided. That produces one deliberate asymmetry:
 * Helixa Magnesium is seeded PER CAPSULE (120 mg, units_per_day 2)
 * because a per-capsule figure is itself published (product name, retail
 * listings, and Helixa's own "twee capsules bevatten 240 mg" — both
 * readings give 240 mg/day). Helixa Omega-3 375 is seeded PER TWO
 * SOFTGELS (units_per_day 1) because no per-softgel figure is published
 * anywhere; halving 1250 mg of fish oil would be arithmetic on data
 * presented as transcription — what PromptV1Label forbids the model
 * from doing, no more acceptable done here. Both give the right daily
 * total, which is what `serving_text` is for.
 *
 * THE ONE ROW THAT HAD TO BE ARGUED: Nordvita's vitamin D line states
 * "400 iu", "5 mcg" and "600% RI", and no two agree (400 IU is 10 µg;
 * 5 µg is 100% RI). Seeded as 5 mcg, the only self-consistent reading —
 * 5 µg is exactly 200 IE, the unit EU labelling requires, and listings
 * naming the form print "ergocalciferol 200IU" beside it; the other two
 * cross-check against nothing. The printed bottle and the retail
 * listings repeat the misprint either way, so the product's note records
 * the contradiction rather than leaving "400 iu" to look like an
 * uncorrected error.
 */
final class SupplementSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::products() as $position => $product) {
            if (Supplement::query()->where('name', $product['name'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($product, $position): void {
                $supplement = Supplement::query()->create([
                    'name'          => $product['name'],
                    'brand'         => $product['brand'],
                    'serving_text'  => $product['serving_text'],
                    'units_per_day' => $product['units_per_day'],
                    'active'        => $product['active'],
                    'data_source'   => $product['data_source'],
                    'source_url'    => $product['source_url'],
                    'notes'         => $product['notes'],
                    'photo_path'    => null,
                    'position'      => $position,
                ]);

                foreach ($product['nutrients'] as $index => [$nutrient, $amount, $unit]) {
                    $supplement->nutrients()->create([
                        'nutrient' => $nutrient,
                        'amount'   => $amount,
                        'unit'     => $unit,
                        'position' => $index,
                    ]);
                }
            });
        }
    }

    /**
     * @return list<array{
     *     name: string, brand: string, serving_text: string, units_per_day: int,
     *     active: bool, data_source: string, source_url: string, notes: string|null,
     *     nutrients: list<array{0: string, 1: float|null, 2: string|null}>
     * }>
     */
    private static function products(): array
    {
        return [
            /*
             * NORDVITA DAILY COMPLETE 50 — one tablet a day. Panel published per
             * tablet, dose stated as "Dagelijks 1 tablet met een glas water
             * innemen": the simple case, units_per_day 1, figures as
             * printed. The %RI column cross-checks against EU reference
             * intakes on every line except vitamin D — see the class
             * docblock and the note below.
             */
            [
                'name'          => 'Daily Complete 50',
                'brand'         => 'Nordvita',
                'serving_text'  => 'per tablet',
                'units_per_day' => 1,
                'active'        => true,
                'data_source'   => 'retailer_published',
                'source_url'    => 'https://www.example.com/supplements/nordvita-daily-complete-50',
                'notes'         => 'The panel gives its Vitamine D row as "400 iu", as "5 mcg" and as "600% RI", and no two of those agree. It is entered as 5 mcg, the one self-consistent figure: 5 µg is exactly 200 IE, and µg is the unit EU labelling requires. Do not "correct" it to 400 iu from the label — that reading agrees with nothing on the panel. Everything else cross-checks against the EU reference intakes.',
                'nutrients'     => [
                    ['Vitamine B1 (thiamine mononitraat)', 25.0, 'mg'],
                    ['Vitamine B2 (riboflavine)', 25.0, 'mg'],
                    ['Vitamine B3 (als niacinamide)', 30.0, 'mg'],
                    ['Vitamine B6 (als pyridoxine HCl)', 10.0, 'mg'],
                    ['Vitamine B12 (als cyanocobalamine)', 250.0, 'mcg'],
                    ['Vitamine C (calciumascorbaat)', 120.0, 'mg'],
                    // The contradictory row, read self-consistently — see
                    // the class docblock and the note.
                    ['Vitamine D (cholecalciferol/ergocalciferol)', 5.0, 'mcg'],
                    ['Vitamine E (als d-alfa-tocoferolsuccinaat)', 20.0, 'mg'],
                    ['Biotine', 50.0, 'mcg'],
                    ['Foliumzuur', 200.0, 'mcg'],
                    ['Jodium (kaliumjodide)', 75.0, 'mcg'],
                    ['Magnesium (oxide)', 16.0, 'mg'],
                    ['Selenium (natriumselenaat)', 25.0, 'mcg'],
                    ['IJzer (fumaraat)', 5.0, 'mg'],
                    ['Zink (oxide)', 7.5, 'mg'],
                ],
            ],

            /*
             * NORDVITA VITAMIN D3 75 MCG — one softgel a day. Published per
             * softgel; the two dose statements agree exactly (75 µg IS
             * 3000 IE), so both are seeded as printed, matching the bottle.
             */
            [
                'name'          => 'Vitamin D3 75 mcg (3000 IU)',
                'brand'         => 'Nordvita',
                'serving_text'  => 'per softgel',
                'units_per_day' => 1,
                'active'        => true,
                'data_source'   => 'retailer_published',
                'source_url'    => 'https://www.example.com/supplements/nordvita-vitamin-d3-75mcg',
                'notes'         => null,
                'nutrients'     => [
                    ['Vitamine D3 (cholecalciferol, uit wolvet)', 75.0, 'mcg'],
                    ['Vitamine D3 (cholecalciferol, uit wolvet)', 3000.0, 'IE'],
                    ['Olijfolie', 100.0, 'mg'],
                ],
            ],

            /*
             * HELIXA MAGNESIUM GLYCINATE-120 — two capsules a day,
             * seeded PER CAPSULE per the class docblock's asymmetry note:
             * "-120" is in the product name and on the retail listings, and
             * 120 x 2 matches Helixa's own "twee capsules bevatten 240 mg".
             */
            [
                'name'          => 'Magnesium Glycinate-120',
                'brand'         => 'Helixa',
                'serving_text'  => 'per capsule',
                'units_per_day' => 2,
                'active'        => true,
                'data_source'   => 'manufacturer_published',
                'source_url'    => 'https://www.example.com/supplements/magnesium-glycinate-120',
                'notes'         => 'Helixa publishes the panel as "twee capsules bevatten 240 mg". Seeded per capsule (120 mg x 2 a day), which is the same daily total and matches the figure on the retail listings and in the product name.',
                'nutrients'     => [
                    ['Magnesium (glycinate)', 120.0, 'mg'],
                ],
            ],

            /*
             * HELIXA OMEGA-3 375 — two mini softgels a day, seeded PER TWO
             * SOFTGELS (units_per_day 1: one serving of two) because that's
             * the only way the panel is published — see the class docblock;
             * halving it would be arithmetic dressed up as transcription.
             */
            [
                'name'          => 'Omega-3 375',
                'brand'         => 'Helixa',
                'serving_text'  => 'per 2 mini softgels',
                'units_per_day' => 1,
                'active'        => true,
                'data_source'   => 'manufacturer_published',
                'source_url'    => 'https://www.example.com/supplements/omega-3-375',
                'notes'         => 'The figures are per TWO mini softgels, which is how Helixa publishes them and how they are taken — so "1 x per 2 mini softgels" a day. No per-softgel figure is published, and halving these would be arithmetic rather than transcription.',
                'nutrients'     => [
                    ['Visolie', 1250.0, 'mg'],
                    ['Omega-3-vetzuren', 750.0, 'mg'],
                    ['EPA (eicosapentaeenzuur)', 375.0, 'mg'],
                    ['DHA (docosahexaeenzuur)', 250.0, 'mg'],
                    ['Natuurlijke tocoferolenmix', 5.0, 'mg'],
                ],
            ],

            /*
             * VERDANT MAGNESIUM GLYCINATE 100 MG WITH TAURINE — the spare.
             *
             * INACTIVE by design, not just as a later toggle: this bottle
             * waits in the cupboard for the Helixa one to run out, transcribed
             * now while somebody's transcribing things, switched on with one
             * tap the day it opens. Until then it's off the day card and out
             * of the adherence denominator, so it can't make a good week
             * read as a failed one.
             */
            [
                'name'          => 'Magnesium Glycinate 100 mg with taurine',
                'brand'         => 'Verdant',
                'serving_text'  => 'per tablet',
                'units_per_day' => 2,
                'active'        => false,
                'data_source'   => 'retailer_published',
                'source_url'    => 'https://www.example.com/supplements/verdant-magnesium-glycinate-100-taurine',
                'notes'         => 'Not started yet — switch it on when the Helixa magnesium runs out. 2 tablets a day is an ASSUMPTION, carried over from the magnesium it replaces; Verdant state "1-2 keer per dag 1 tablet", so two is within their range but is not what the label prescribes. Change it if you take one.',
                'nutrients'     => [
                    ['Magnesium (glycinate)', 100.0, 'mg'],
                    ['Taurine', 200.0, 'mg'],
                ],
            ],
        ];
    }
}
