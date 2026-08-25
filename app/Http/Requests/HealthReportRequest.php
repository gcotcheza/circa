<?php

declare(strict_types=1);

namespace App\Http\Requests;

use InvalidArgumentException;
use App\Enums\ReportFocusArea;
use Illuminate\Validation\Rule;
use App\Services\Report\ReportFocus;
use App\Services\Report\ReportRange;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Generate form: a preset, or a pair of dates.
 *
 * Laravel could express the range rules directly (`after_or_equal`,
 * `date_format`), but that would be a SECOND statement of what a legal
 * range is, next to ReportRange's — diverging the first time the ceiling
 * moved, so the form accepts 120 days and the assembler refuses them as a
 * queued job failing for a reason the user never sees. So only the shape is
 * validated here (fields present, look like dates); legality is decided by
 * constructing the actual range in `withValidator`, keeping ReportRange the
 * single authority on clamping, ordering and the ceiling, with its own
 * exception message becoming the field error.
 *
 * A preset is not a special case: `days=7` and an explicit seven-day
 * from/to are the same request by the time they reach the controller — the
 * chips just save typing.
 *
 * `focus[]` is validated before the range because it changes the range's
 * legality: a 180-day window is valid with the Stress chip and illegal with
 * Food, so the range can't be judged until the chips are known, and the two
 * failure shapes need different sentences (`ReportFocus::ceilingMessage()`
 * writes them, not ReportRange). The chip list is a strict whitelist
 * against the enum — not because an unknown chip is dangerous (ReportFocus
 * drops what it doesn't recognise) but because a chip silently ignored is a
 * report quietly not about what was asked for, invisibly. The free text is
 * capped and otherwise untouched: it's the reader's own sentence and ends
 * up inside a prompt, so it goes in as a fact block rather than instruction
 * (see AnthropicReportWriter — untrusted text never becomes part of the
 * question), and nothing here tries to sanitise it into safety.
 */
final class HealthReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Session auth on the route is the whole authorisation story: one
        // user, every report theirs.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Bounded loosely on purpose: the ceiling is ReportRange's, so a
            // number past it comes back as its sentence, not "the days field
            // must not be greater than 92".
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'string', 'date_format:Y-m-d'],

            // Five chips, so five is also the ceiling — a sixth is a
            // duplicate or a client sending what the form can't produce.
            'focus'   => ['nullable', 'array', 'max:'.count(ReportFocusArea::cases())],
            'focus.*' => ['string', Rule::in(ReportFocusArea::values())],

            'focusText' => ['nullable', 'string', 'max:'.ReportFocus::MAX_TEXT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'The start date must look like 2026-08-09.',
            'to.date_format'   => 'The end date must look like 2026-08-09.',
            'focus.*.in'       => 'That is not one of the areas a report can focus on.',
            'focusText.max'    => 'Keep the focus note under '.ReportFocus::MAX_TEXT.' characters.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                // The dates didn't even parse; constructing a range would
                // only produce a second, worse message about the same field.
                return;
            }

            try {
                $this->range();
            } catch (InvalidArgumentException $e) {
                // Attached to `from` rather than the whole form, so the
                // message lands under the control the user can change.
                $validator->errors()->add('from', $e->getMessage());
            }
        });
    }

    /**
     * The range this request asks for.
     *
     * Called twice (validator, then controller) and deliberately not
     * memoised — it's a few date parses, and a cached value on a form
     * request risks being read before the thing that populated it ran.
     *
     * @throws InvalidArgumentException
     */
    public function range(): ReportRange
    {
        $focus = $this->focus();

        $days = $this->input('days');

        if ($days !== null && $days !== '') {
            return ReportRange::lastDays((int) $days, null, $focus);
        }

        $from = $this->string('from')->value();
        $to = $this->string('to')->value();

        if ($from === '') {
            throw new InvalidArgumentException('Pick a range, or one of the presets.');
        }

        // A start with no end means "up to today" — what filling in one box
        // means.
        return ReportRange::between(
            $from,
            $to !== '' ? $to : now((string) config('health.timezone'))->toDateString(),
            null,
            $focus,
        );
    }

    /**
     * What this request asks the report to be about.
     *
     * Like `range()`, called twice and deliberately not memoised — just an
     * array filter and a trim, not worth the risk of a stale cached read.
     */
    public function focus(): ReportFocus
    {
        /** @var array<int, mixed> $areas */
        $areas = (array) ($this->input('focus') ?? []);

        return ReportFocus::of(
            array_values(array_filter($areas, 'is_string')),
            $this->string('focusText')->value(),
        );
    }
}
