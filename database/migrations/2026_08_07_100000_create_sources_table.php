<?php

declare(strict_types=1);

use App\Enums\DeviceKind;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Stable identity for "the thing that reported this datapoint".
 *
 * Every distinct shape of `source` string seen in the captured payloads,
 * verbatim — a long tail of rare ones behind a few very common ones:
 *
 *   "Demo\u{2019}s Apple\u{00A0}Watch"
 *   "Demo\u{2019}s Apple\u{00A0}Watch|Demo\u{2019}s iphone"
 *   "Demo\u{2019}s iphone"
 *   ""                                          (apple_stand_hour)
 *   "Demo\u{2019}s Apple\u{00A0}Watch|Blood Oxygen"
 *   "Demo\u{2019}s Apple\u{00A0}Watch|FITAGE"
 *   "Fitdays"
 *   "FITAGE"
 *   "AutoSleep"
 *   "Blood Oxygen | Demo\u{2019}s Apple\u{00A0}Watch"
 *
 * Three things string matching would get wrong, which this table absorbs:
 *  1. U+2019 RIGHT SINGLE QUOTATION MARK, not an ASCII apostrophe, and
 *     U+00A0 NO-BREAK SPACE between "Apple" and "Watch" — Apple writes
 *     device names this way; nobody types them that way.
 *  2. Health Auto Export pipe-joins contributing devices when summarising
 *     ("A|B"), inconsistently, sometimes with surrounding spaces — those
 *     composites are their own sources here, not split, so a row still
 *     round-trips to the raw payload.
 *  3. The empty string is a real, frequent source (every
 *     apple_stand_hour datapoint). `raw_name` is NOT NULL and '' is
 *     legitimate, keeping `health_metrics.source_id` NOT NULL and the FK
 *     simple.
 *
 * `raw_name` is UNIQUE and holds the first spelling observed, so every row
 * round-trips to a real payload. `slug` is the normalised form
 * (NBSP->space, U+2019->', pipe-separated parts trimmed and SORTED,
 * lowercased, non-alphanumerics folded to '-').
 *
 * STEP 2 CORRECTION: the slug is the IDENTITY, and is not 1:1 with
 * raw_name — the same device reaches us spelled several ways, and each
 * spelling becoming its own source would fork the history this table
 * exists to keep whole. SourceResolver looks up by slug; the 10 raw
 * strings above resolve to 9 sources (both orderings of "Blood Oxygen"
 * + the Watch are one composite). `device_kind` is the *grouping*
 * key: "FITAGE" and "Fitdays" are two apps in front of the same scale,
 * both `scale`, which is what config('health.source_priority') targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();

            // Exactly what arrived, byte for byte. text, not varchar:
            // device names are user-editable and have no sane length cap.
            $table->text('raw_name')->unique();

            // Human-facing label. Free to change when a device is
            // renamed — history stays attached to the id.
            $table->text('name');

            // Derived from raw_name, so 1:1 with it and therefore unique.
            $table->string('slug', 128)->unique();

            $table->enum('device_kind', DeviceKind::values())
                ->default(DeviceKind::Unknown->value);

            $table->timestampsTz();

            $table->index('device_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
