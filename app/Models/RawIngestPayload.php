<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\RawIngestPayloadFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A single POST body received on /api/ingest, stored untouched.
 *
 * PERMANENT TABLE — no pruning job, no staging-area treatment. It's the
 * replay source for every future schema change, reprocessing run and
 * backfill, and the only copy of what the phone actually said if the
 * parser is wrong.
 *
 * @property int $id
 * @property string $headers raw jsonb text
 * @property string $body raw jsonb text
 * @property string $received_at
 */
final class RawIngestPayload extends Model
{
    /** @use HasFactory<RawIngestPayloadFactory> */
    use HasFactory;

    protected $table = 'raw_ingest_payloads';

    /**
     * `received_at` is set by the database default; there is no created_at /
     * updated_at pair to maintain.
     */
    public $timestamps = false;

    protected $guarded = [];

    /**
     * No `array` casts on purpose: casting would json_encode on write, so
     * the column would hold whatever PHP re-serialised rather than what
     * arrived. Values go to the driver as raw JSON strings and Postgres
     * parses them into jsonb itself — the closest to verbatim jsonb allows
     * (it still normalises whitespace, key order and duplicate keys; the
     * alternative, text, gives up all queryability).
     */
    protected $casts = [];

    /**
     * Persist one received payload.
     *
     * @param  array<string, mixed>  $headers
     * @param  string  $rawBody  the exact request body, already validated as JSON
     */
    public static function store(array $headers, string $rawBody): self
    {
        return self::create([
            'headers' => json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'body'    => $rawBody,
        ]);
    }

    /**
     * The processing attempts made against this payload — append-only, and
     * legitimately may be empty, since banking the bytes happens before
     * and independently of anything trying to parse them.
     *
     * @return HasMany<IngestRun, $this>
     */
    public function ingestRuns(): HasMany
    {
        return $this->hasMany(IngestRun::class);
    }

    /**
     * @return array<mixed>
     */
    public function decodedBody(): array
    {
        return (array) json_decode((string) $this->body, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedHeaders(): array
    {
        return (array) json_decode((string) $this->headers, true);
    }

    /**
     * Total bytes banked so far — handy for eyeballing whether the phone is
     * sending, and how big "big" gets in practice.
     */
    public static function totalBodyBytes(): int
    {
        return (int) DB::table('raw_ingest_payloads')
            ->selectRaw('coalesce(sum(octet_length(body::text)), 0) as bytes')
            ->value('bytes');
    }
}
