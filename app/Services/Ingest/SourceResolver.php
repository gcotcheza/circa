<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

/**
 * Raw HAE source string -> a stable `sources` row.
 *
 * Identity is the normalised slug, not the raw string (see Source::slugFor
 * for why: curly apostrophes, no-break spaces, composite ordering). The
 * first spelling observed wins `raw_name`; later spellings resolve to the
 * same row and add nothing.
 *
 * Cached per instance/payload: a single hourly payload has ~250 datapoints
 * from a handful of devices, turning ~250 SELECTs into ~5. Not cached
 * across payloads — the job is short-lived and a process-lifetime cache
 * would go stale the moment a new device appeared.
 *
 * A device name is a string somebody typed, reaching us over HTTP. Neither
 * `slug` (varchar 128) nor `raw_name` (text, unique) was bounded on the way
 * in — a long `source` string once produced a slug Postgres refused
 * (22001), failing the INSERT and the whole payload's ~250 readings. Both
 * are bounded here now, and a source that still can't be written degrades
 * to `unknown` rather than taking the payload down with it: a reading
 * attributed to "unknown" beats no reading at all.
 */
final class SourceResolver
{
    /** `sources.slug` is varchar(128). */
    private const MAX_SLUG = 128;

    /**
     * `sources.raw_name` is `text`, unbounded in Postgres — not a reason to
     * accept an unbounded string, since it's uniquely indexed (btree tops
     * out ~2704 bytes) and rendered in the UI. 191 is generous for "Demo's
     * Apple Watch" and every composite of them.
     */
    private const MAX_RAW_NAME = 191;

    /** What Source::slugFor() folds the empty string to. */
    private const UNKNOWN_SLUG = 'unknown';

    /** @var array<string, Source> slug => source */
    private array $cache = [];

    public function resolve(?string $rawName): Source
    {
        // A missing `source` key is treated like HAE's empty-string source
        // (every apple_stand_hour datapoint carries one): unknown, but a
        // real row, so `health_metrics.source_id` stays NOT NULL.
        $rawName ??= '';

        // Bounded BEFORE slugging, so the slug derives from the string
        // actually stored and the two keep agreeing.
        $rawName = mb_substr($rawName, 0, self::MAX_RAW_NAME);

        $slug = mb_substr(Source::slugFor($rawName), 0, self::MAX_SLUG);

        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        return $this->cache[$slug] = $this->findOrCreate($slug, $rawName);
    }

    private function findOrCreate(string $slug, string $rawName): Source
    {
        $existing = Source::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $attributes = [
            'raw_name'    => $rawName,
            'name'        => mb_substr(Source::displayNameFor($rawName), 0, self::MAX_RAW_NAME),
            'slug'        => $slug,
            'device_kind' => Source::kindFor($rawName),
        ];

        try {
            // Wrapped so the INSERT gets its own SAVEPOINT. The parse runs
            // inside one transaction, and Postgres aborts the WHOLE
            // transaction on a failed statement — so without this, the
            // recovery below would itself fail with 25P02.
            /** @var Source $created */
            $created = DB::transaction(fn (): Source => Source::query()->create($attributes));

            return $created;
        } catch (QueryException $e) {
            // Two workers can meet on a device's first datapoint. The unique
            // index is the arbiter; the loser just re-reads.
            $winner = Source::query()->where('slug', $slug)->first()
                ?? Source::query()->where('raw_name', $rawName)->first();

            if ($winner !== null) {
                return $winner;
            }

            // Not a lost race, then. Fall back to `unknown` — unless it IS
            // the `unknown` row that can't be written, a broken database
            // that has to surface.
            if ($slug === self::UNKNOWN_SLUG) {
                throw $e;
            }

            Log::warning('ingest.source_unresolved', [
                'slug'      => $slug,
                'exception' => $e::class,
                'reason'    => IngestErrorText::describe($e),
            ]);

            return $this->resolve('');
        }
    }

    /**
     * Sources touched by this resolver, for logging and tests.
     *
     * @return array<string, Source> slug => source
     */
    public function resolved(): array
    {
        return $this->cache;
    }
}
