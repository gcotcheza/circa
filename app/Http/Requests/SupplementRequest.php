<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A supplement as the user confirmed it — from a label reading they have
 * proof-read, or typed in from scratch.
 *
 * `amount` is `nullable|numeric`, not `required|numeric`: a line the model
 * could read the name of but not the figure of comes back with an empty
 * box, a one-tap fix right in front of the user — refusing the whole form
 * over it would force deleting a real line or giving up on the flow, and a
 * supplement with one blank figure is more useful than none.
 *
 * `nutrients` may be absent entirely: "magnesium, 2 capsules, and I can't
 * be bothered to type the panel" is a legitimate supplement to check off
 * every evening, since the tier-1 promise is adherence and the label
 * figures only enable the future weekly estimate.
 */
final class SupplementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:120'],
            'brand'        => ['nullable', 'string', 'max:120'],
            'serving_text' => ['nullable', 'string', 'max:120'],

            /*
             * How many of `serving_text` are taken per day; figures below
             * are per THAT serving, and the daily total is the product —
             * see the migration for the one arithmetic rule this feature
             * has. Ceiling of 20 because the field exists for "two
             * capsules," not for a typo that turns 375 mg into 375 g.
             */
            'units_per_day' => ['sometimes', 'integer', 'min:1', 'max:20'],

            /*
             * On the card, or not — a choice at CREATION too: a bottle
             * bought today and started when the current one runs out is
             * added inactive, since the label is in hand now but won't be
             * in three months. Absent means the sensible default (active
             * on create, unchanged on edit), applied by the controller,
             * which is why this is `sometimes`.
             */
            'active' => ['sometimes', 'boolean'],

            // Which label reading this came from, so the audit row can
            // learn what it became and the photo can attach to the
            // supplement. Nullable: adding one by hand has no reading
            // behind it.
            'client_id' => ['nullable', 'uuid'],

            // The standing note on the row — e.g. "vitamin D is blank
            // because the panel contradicts itself." Editable so the user
            // who resolves it can delete it rather than being told about a
            // fixed problem forever.
            'notes' => ['nullable', 'string', 'max:500'],

            'nutrients' => ['nullable', 'array', 'max:60'],

            /*
             * NULLABLE, NOT REQUIRED, deliberately: "add a line" puts an
             * empty row at the bottom, and a user who taps it and thinks
             * better of it leaves one behind. `required` would reject the
             * WHOLE FORM over that blank row, pointing at an index nothing
             * on screen is labelled with, leaving the user to hunt for it
             * or abandon the entry. So a nameless line simply isn't a
             * label line: it validates, and SupplementWriter drops it,
             * renumbering the survivors from zero to keep proof-read order.
             */
            'nutrients.*.nutrient' => ['nullable', 'string', 'max:120'],
            'nutrients.*.amount'   => ['nullable', 'numeric'],
            'nutrients.*.unit'     => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * The label lines, in the order the form posted them.
     *
     * @return list<array{nutrient: string, amount: float|string|null, unit: string|null}>
     */
    public function nutrients(): array
    {
        /** @var array<array-key, array<string, mixed>>|null $rows */
        $rows = $this->validated('nutrients');

        $rows ??= [];

        // Reindexed, not assumed: `validated()` hands back the keys the form
        // posted, so a client that submits `nutrients[5]` alone produces
        // `[5 => …]` — and the caller is promised, and renumbers from, a list.
        return array_values(array_map(static fn (array $row): array => [
            'nutrient' => (string) ($row['nutrient'] ?? ''),
            'amount'   => $row['amount'] ?? null,
            'unit'     => isset($row['unit']) ? (string) $row['unit'] : null,
        ], $rows));
    }

    /** Trimmed, or null when the box was left empty. */
    public function optionalText(string $field): ?string
    {
        $value = trim((string) $this->string($field)->value());

        return $value === '' ? null : $value;
    }
}
