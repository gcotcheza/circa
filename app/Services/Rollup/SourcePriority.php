<?php

declare(strict_types=1);

namespace App\Services\Rollup;

use App\Enums\DeviceKind;

/**
 * The read-side anti-double-count rule, as a rank.
 *
 * config('health.source_priority') lists device kinds best-first per
 * metric; this turns that into a sortable integer, so "pick the
 * Watch's version of this hour" is an ORDER BY, not a chain of ifs.
 *
 * Kinds missing from a metric's list rank last (not "never"): a day
 * whose only step data came from an unlisted source still beats a day
 * with no steps, and silently dropping it would be worse and invisible.
 */
final class SourcePriority
{
    /** @var array<string, list<string>> */
    private array $lists;

    /** @var list<string> */
    private array $default;

    /** @param  array<string, list<string>>|null  $config  the config block, or a test's own */
    public function __construct(?array $config = null)
    {
        $config ??= self::configured();

        $this->default = $config['default'] ?? DeviceKind::values();

        unset($config['default']);

        $this->lists = $config;
    }

    /**
     * The lists as `config/health.php` states them.
     *
     * Read through a coercion rather than trusted as-is: config is a PHP
     * file, so a typo there is a shape nothing checks, and the ranks it
     * feeds decide which device's version of an hour is believed.
     *
     * @return array<string, list<string>>
     */
    private static function configured(): array
    {
        $lists = [];

        foreach ((array) config('health.source_priority', []) as $metric => $kinds) {
            $lists[(string) $metric] = array_values(array_map(strval(...), (array) $kinds));
        }

        return $lists;
    }

    public function rankFor(string $metric, DeviceKind $kind): int
    {
        $list = $this->lists[$metric] ?? $this->default;

        $index = array_search($kind->value, $list, strict: true);

        // Unlisted kinds all share the same last rank; ties are then broken by
        // the caller's secondary ordering, deterministically.
        return $index === false ? count($list) : $index;
    }
}
