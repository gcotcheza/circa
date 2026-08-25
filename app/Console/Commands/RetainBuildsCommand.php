<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keeps the last few builds' assets on disk, deletes the rest — the ledger
 * that makes `emptyOutDir: false` safe for a page left open across a
 * deploy. See docs/rationale-app.md § "RetainBuildsCommand: why deploys
 * need a ledger, not just files".
 */
final class RetainBuildsCommand extends Command
{
    protected $signature = 'build:retain
                            {--keep=3 : How many builds to keep, counting the current one}
                            {--dir= : Build directory (default public/build)}
                            {--dry-run : List what would be deleted, delete nothing}';

    protected $description = 'Snapshot the current build and prune assets belonging to builds older than the last few';

    private const LEDGER = 'builds';

    /** The only directory this command will ever delete a file from. */
    private const ASSETS = 'assets';

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?? '') ?: public_path('build');

        $keep = max(1, (int) $this->option('keep'));

        $manifestPath = $dir.'/manifest.json';

        if (! File::exists($manifestPath)) {
            // Not an error: the deploy runs this straight after the build, so a
            // missing manifest here means the build already failed and said so.
            $this->warn("No build manifest at {$manifestPath} — nothing to retain.");

            return self::SUCCESS;
        }

        $version = substr((string) md5_file($manifestPath), 0, 12);

        $recorded = $this->record($dir, $version, $this->filesInManifest($manifestPath));

        $this->line($recorded
            ? "  recorded build {$version}"
            : "  build {$version} was already recorded");

        [$retained, $dropped] = $this->pruneSnapshots($dir, $keep);

        $this->line('  keeping '.count($retained).' build(s): '.implode(', ', $retained));

        foreach ($dropped as $old) {
            $this->line("  dropped build {$old}");
        }

        $deleted = $this->pruneAssets($dir, $retained);

        $this->line(($this->option('dry-run') ? '  would delete ' : '  deleted ')
            .count($deleted).' orphaned asset file(s)');

        foreach ($deleted as $file) {
            $this->line("    {$file}");
        }

        return self::SUCCESS;
    }

    // `file`, `css` and `assets` — the chunk, its stylesheets, and anything
    // else Vite emitted (the zxing wasm binary is one of these).
    /** @return list<string> */
    private function filesInManifest(string $manifestPath): array
    {
        $decoded = json_decode((string) File::get($manifestPath), true);

        if (! is_array($decoded)) {
            return [];
        }

        $files = [];

        foreach ($decoded as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            if (isset($chunk['file']) && is_string($chunk['file'])) {
                $files[] = $chunk['file'];
            }

            foreach (['css', 'assets'] as $key) {
                foreach ((array) ($chunk[$key] ?? []) as $extra) {
                    if (is_string($extra)) {
                        $files[] = $extra;
                    }
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    // Not overwritten on a re-run — refreshing `recorded_at` would make
    // today's scheduled run look like a new build and evict a real one.
    /**
     * @param  list<string>  $files
     * @return bool whether a new snapshot was written
     */
    private function record(string $dir, string $version, array $files): bool
    {
        $ledger = $dir.'/'.self::LEDGER;

        File::ensureDirectoryExists($ledger);

        $path = $ledger.'/'.$version.'.json';

        if (File::exists($path)) {
            return false;
        }

        File::put($path, (string) json_encode([
            'version' => $version,

            // Microseconds: two deploys can land in the same second, and at second
            // resolution the ordering ties and retention drops the wrong build.
            'recorded_at' => now()->format('Y-m-d\TH:i:s.uP'),

            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} retained versions, dropped versions
     */
    private function pruneSnapshots(string $dir, int $keep): array
    {
        $ledger = $dir.'/'.self::LEDGER;

        $snapshots = [];

        foreach (File::glob($ledger.'/*.json') ?: [] as $path) {
            $decoded = json_decode((string) File::get($path), true);

            $snapshots[] = [
                'version' => basename($path, '.json'),
                'path'    => $path,
                // File's own timestamp is the fallback, for a snapshot written
                // by hand or truncated by a half-finished deploy.
                'at' => is_array($decoded) && is_string($decoded['recorded_at'] ?? null)
                    ? $decoded['recorded_at']
                    : date(DATE_ATOM, (int) File::lastModified($path)),
            ];
        }

        usort($snapshots, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $retained = array_slice($snapshots, 0, $keep);
        $dropped = array_slice($snapshots, $keep);

        foreach ($dropped as $snapshot) {
            if (! $this->option('dry-run')) {
                File::delete($snapshot['path']);
            }
        }

        return [
            array_map(static fn (array $s): string => $s['version'], $retained),
            array_map(static fn (array $s): string => $s['version'], $dropped),
        ];
    }

    // Deletes everything in `assets/` that no retained snapshot names, and
    // keeps each retained file's `.map` too. See docs/rationale-app.md §
    // "RetainBuildsCommand: why deploys need a ledger, not just files".
    /**
     * @param  list<string>  $retained
     * @return list<string> paths deleted, relative to the build dir
     */
    private function pruneAssets(string $dir, array $retained): array
    {
        $keepSet = [];

        foreach ($retained as $version) {
            $decoded = json_decode((string) File::get($dir.'/'.self::LEDGER.'/'.$version.'.json'), true);

            foreach ((array) ($decoded['files'] ?? []) as $file) {
                if (is_string($file)) {
                    $keepSet[$file] = true;
                    $keepSet[$file.'.map'] = true;
                }
            }
        }

        $assets = $dir.'/'.self::ASSETS;

        if (! File::isDirectory($assets)) {
            return [];
        }

        $deleted = [];

        foreach (File::files($assets) as $file) {
            $relative = self::ASSETS.'/'.$file->getFilename();

            if (isset($keepSet[$relative])) {
                continue;
            }

            $deleted[] = $relative;

            if (! $this->option('dry-run')) {
                File::delete($file->getPathname());
            }
        }

        sort($deleted);

        return $deleted;
    }
}
