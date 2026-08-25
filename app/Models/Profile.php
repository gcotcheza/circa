<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The person, as they have described themselves.
 *
 * A SINGLETON: one user, one row, primary key pinned to `SINGLETON_ID` —
 * making "only one of these exists" a database constraint rather than a
 * convention two concurrent saves could break. Read via `current()`, which
 * hands back an unsaved blank when nothing's filled in, so every caller can
 * read `->sex` without asking first.
 *
 * EVERY FIELD IS OPTIONAL and none is seeded. See the migration for why, and
 * for the one derivation this app deliberately refuses to perform for the
 * user.
 *
 * WHAT IS NOT HERE: numbers aren't cast to float. `height_cm` and
 * `target_weight_kg` are Postgres `decimal`, coming back as strings like
 * `daily_summaries.weight_kg` and `supplement_nutrients.amount` do —
 * conversion happens at point of use (`heightCm()`, `targetWeightKg()`
 * below). One decimal convention across the schema is one fewer thing to
 * remember.
 *
 * @property int $id
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $sex for nutrition reference ranges; free text, not an enum
 * @property numeric-string|null $height_cm
 * @property string|null $ethnicity
 * @property string|null $country latitude by proxy — see the migration
 * @property string|null $goal lose weight | maintain | gain muscle | anything they typed
 * @property numeric-string|null $target_weight_kg
 * @property string|null $goal_notes
 * @property string|null $dietary_preferences choices
 * @property string|null $allergies_intolerances constraints — deliberately a different column
 * @property string|null $smoking
 * @property string|null $alcohol the pattern, not a count
 * @property string|null $sun_exposure
 * @property string|null $activity_context
 * @property string|null $life_stage
 * @property string|null $health_notes awareness only — see the migration and the prompt
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * One row, and the primary key says so.
     *
     * A `firstOrNew()`-then-`save()` pair would leave a window where two
     * requests both see no row and both insert one, and a second profile is
     * nothing this code knows how to read. Pinning the id turns that race
     * into a unique-violation on a key nothing else uses.
     */
    public const SINGLETON_ID = 1;

    /** The columns the profile form may write. `id` is not one of them. */
    public const EDITABLE = [
        'date_of_birth',
        'sex',
        'height_cm',
        'ethnicity',
        'country',
        'goal',
        'target_weight_kg',
        'goal_notes',
        'dietary_preferences',
        'allergies_intolerances',
        'smoking',
        'alcohol',
        'sun_exposure',
        'activity_context',
        'life_stage',
        'health_notes',
    ];

    protected $guarded = [];

    /**
     * The profile, or a blank one that has never been saved.
     *
     * Never null, on purpose: every consumer — report, form, page — wants to
     * read the fields and find them empty, not branch on whether a row
     * exists first.
     */
    public static function current(): self
    {
        return self::query()->find(self::SINGLETON_ID) ?? new self;
    }

    /**
     * Write the profile, creating the one row if this is the first save.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function put(array $attributes): self
    {
        return self::query()->updateOrCreate(['id' => self::SINGLETON_ID], $attributes);
    }

    /**
     * Has the user told the app anything at all?
     *
     * The report's answer to "framed for this person, or for anybody": every
     * field null means the profile exists but is unused, a fact the report
     * says out loud rather than papering over.
     */
    public function isBlank(): bool
    {
        foreach (self::EDITABLE as $field) {
            if ($this->getAttribute($field) !== null && $this->getAttribute($field) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whole years old on a given date.
     *
     * COMPUTED, NEVER STORED — an age written to a column is wrong by the
     * next birthday and nothing would notice. Evaluated at the END of the
     * range a report covers, so a report about last February states the age
     * they were then.
     *
     * The arithmetic is spelled out rather than delegated: "years between two
     * dates" has three plausible answers (rounded, truncated, signed), and
     * this app means completed birthdays.
     */
    public function ageOn(string $date): ?int
    {
        if ($this->date_of_birth === null) {
            return null;
        }

        $born = $this->date_of_birth;
        $on = CarbonImmutable::parse($date);

        $years = $on->year - $born->year;

        if ($on->month < $born->month || ($on->month === $born->month && $on->day < $born->day)) {
            $years--;
        }

        return $years < 0 ? null : $years;
    }

    public function heightCm(): ?float
    {
        return $this->height_cm === null ? null : (float) $this->height_cm;
    }

    public function targetWeightKg(): ?float
    {
        return $this->target_weight_kg === null ? null : (float) $this->target_weight_kg;
    }

    /**
     * Body mass index against a given weight, to one decimal.
     *
     * Null unless BOTH halves exist. A BMI computed from a guessed height
     * would look measured and isn't — and this is the figure in the report a
     * reader is most likely to take at face value.
     *
     * One decimal because that's the input's precision: a height typed to
     * the nearest centimetre moves BMI by about 0.3, so a second decimal
     * would describe the arithmetic, not the body.
     */
    public function bmiFor(?float $weightKg): ?float
    {
        $height = $this->heightCm();

        if ($height === null || $height <= 0.0 || $weightKg === null || $weightKg <= 0.0) {
            return null;
        }

        $metres = $height / 100;

        return round($weightKg / ($metres * $metres), 1);
    }

    /**
     * How far a weight is from the target, to one decimal.
     *
     * POSITIVE MEANS ABOVE THE TARGET. Signed once, here, with the snapshot
     * stating the convention next to the number — the model never subtracts,
     * and a sign it had to infer is one it would eventually infer backwards.
     */
    public function kgFromTarget(?float $weightKg): ?float
    {
        $target = $this->targetWeightKg();

        if ($target === null || $weightKg === null) {
            return null;
        }

        return round($weightKg - $target, 1);
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'created_at'    => 'immutable_datetime',
            'updated_at'    => 'immutable_datetime',
        ];
    }
}
