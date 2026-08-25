<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceKind;
use Illuminate\Support\Str;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The device or app that reported a datapoint.
 *
 * @property int $id
 * @property string $raw_name
 * @property string $name
 * @property string $slug
 * @property DeviceKind $device_kind
 */
final class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * Nothing is guarded anywhere in this app: single-user, and no request
     * payload is ever handed straight to a model. The exceptions are
     * database-generated columns, guarded because writing them is an error,
     * not a permission question.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'device_kind' => DeviceKind::class,
        ];
    }

    /** @return HasMany<HealthMetric, $this> */
    public function healthMetrics(): HasMany
    {
        return $this->hasMany(HealthMetric::class);
    }

    /** @return HasMany<SleepSession, $this> */
    public function sleepSessions(): HasMany
    {
        return $this->hasMany(SleepSession::class);
    }

    /**
     * Fold one of Health Auto Export's raw source strings into a stable
     * slug — the identity key, not `raw_name`, since the same physical
     * device reaches us spelled several ways (U+2019 curly apostrophe,
     * U+00A0 no-break space — Apple's punctuation) and each spelling would
     * otherwise fork the history `sources` exists to keep whole. Composite
     * ORDER also normalises ("A|B" = "B|A"): parts are trimmed and sorted
     * before slugging, since both orderings appear in captured payloads.
     * Pipe-joined composites keep both halves ("Watch|iphone" stays
     * distinct from "Watch"); the empty string folds to a placeholder so
     * the unique index has something to hold. `raw_name` itself stays
     * unique, keeping the first spelling seen. See SourceResolver.
     */
    public static function slugFor(string $rawName): string
    {
        $parts = self::normalisedParts($rawName);

        $slug = Str::slug(implode(' or ', $parts));

        return $slug === '' ? 'unknown' : $slug;
    }

    /**
     * The pipe-separated contributors, punctuation-normalised, trimmed,
     * empties dropped, sorted so ordering can't fork a composite.
     *
     * @return list<string>
     */
    public static function normalisedParts(string $rawName): array
    {
        $normalised = str_replace(
            ["\u{00A0}", "\u{2019}", "\u{2018}"],
            [' ', "'", "'"],
            $rawName
        );

        $parts = array_values(array_filter(
            array_map(
                static fn (string $part): string => trim(preg_replace('/\s+/u', ' ', $part) ?? ''),
                explode('|', $normalised)
            ),
            static fn (string $part): bool => $part !== ''
        ));

        // Case-insensitive so "FITAGE|watch" and "watch|FITAGE" agree.
        usort($parts, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $parts;
    }

    /**
     * Human-facing label. Never empty — an unlabelled row in a UI is
     * indistinguishable from a rendering bug.
     */
    public static function displayNameFor(string $rawName): string
    {
        $parts = self::normalisedParts($rawName);

        return $parts === [] ? 'Unknown source' : implode(' + ', $parts);
    }

    /**
     * Best-effort classification from the raw string, seeding
     * `device_kind`. Priority config keys on the result, so getting this
     * wrong misroutes rollups — deliberately conservative, falling through
     * to Unknown rather than guessing.
     */
    public static function kindFor(string $rawName): DeviceKind
    {
        $normalised = mb_strtolower(str_replace("\u{00A0}", ' ', $rawName));

        if (str_contains($normalised, '|')) {
            return DeviceKind::Composite;
        }

        return match (true) {
            $normalised === ''                                                        => DeviceKind::Unknown,
            str_contains($normalised, 'watch')                                        => DeviceKind::Watch,
            str_contains($normalised, 'iphone'), str_contains($normalised, 'ipad')    => DeviceKind::Phone,
            str_contains($normalised, 'fitage'), str_contains($normalised, 'fitdays') => DeviceKind::Scale,
            default                                                                   => DeviceKind::App,
        };
    }
}
