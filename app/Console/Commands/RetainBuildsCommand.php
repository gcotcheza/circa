<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan build:retain` — keep the last few builds' assets on disk, and
 * delete the ones before that.
 *
 * THE BUG THIS ANSWERS: `vite build` empties `public/build`, and this app is
 * a client-rendered PWA whose HTML does nothing but name one of its chunks —
 * so every deploy DELETES the previous build's files, and anything still
 * pointing at the old one is not degraded, it's dead. A PAGE LEFT OPEN
 * ACROSS A DEPLOY runs fine until its first lazy import (photo capture,
 * review sheet, barcode scanner — not corners of the app, how a meal gets
 * logged): the chunk 404s and the button does nothing forever. A DOCUMENT
 * SERVED FROM ANY CACHE names a deleted entry chunk, which for a
 * client-rendered app is a blank white page with no server-side symptom.
 * (NoStoreHtmlResponses attacks that one from the other end — both, because
 * they fail independently.) Neither showed up in the nginx log because
 * `location ^~ /build/` had `access_log off`, hiding the 404 that would have
 * named the problem for a day; fixed in docker/web/nginx.conf.
 *
 * HOW IT WORKS: A LEDGER, because a file's mtime is not its build.
 * `build.emptyOutDir` is `false` in vite.config.js, so a build adds files
 * rather than replacing the directory — something has to delete the old
 * ones, and that something needs to know which file belonged to which
 * build, which the filesystem can't answer (an unchanged chunk keeps its
 * name AND timestamp across builds). So each run writes a snapshot —
 * `public/build/builds/<version>.json`, listing exactly the files the
 * manifest referenced. `<version>` is the md5 prefix of manifest.json, the
 * same string BuildAssets uses for the service worker's cache name, so
 * "a build" means the same thing in both places. Retention keeps the
 * newest N snapshots, keeps the union of files they name, deletes
 * everything else in `assets/` — a chunk shared by three builds survives
 * until the last of them is dropped.
 *
 * RUNS TWICE: in the deploy, straight after the asset build (the moment the
 * new snapshot has to be written and the pruning is wanted), and on a
 * schedule (routes/console.php), because `emptyOutDir: false` turns a
 * forgotten deploy step into a filling disk rather than a no-op — worst
 * case is a day of extra chunks. Idempotent: a run with no new build
 * re-reads the same manifest, finds its snapshot already there, deletes
 * nothing still referenced.
 *
 * WHY THREE: the window that matters is how long a phone can hold a
 * reference to an old build and still be rescued, bounded by how often we
 * deploy on a bad day — three times, on the day this was written. Beyond
 * three the returns are nothing: a device that missed four deploys has been
 * asleep for days and fetches fresh HTML the moment it wakes.
 */
final class RetainBuildsCommand extends Command
{
    protected $signature = 'build:retain
                            {--keep=3 : How many builds to keep, counting the current one}
                            {--dir= : Build directory (default public/build)}
                            {--dry-run : List what would be deleted, delete nothing}';

    protected $description = 'Snapshot the current build and prune assets belonging to builds older than the last few';

    /** Where the per-build snapshots live, relative to the build directory. */
    private const LEDGER = 'builds';

    /** The only directory this command will ever delete a file from. */
    private const ASSETS = 'assets';

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?? '') ?: public_path('build');

        $keep = max(1, (int) $this->option('keep'));

        $manifestPath = $dir.'/manifest.json';

        if (! File::exists($manifestPath)) {
            // A checkout never built. Not an error: the deploy runs this
            // straight after the build, and a missing manifest there means
            // the build already failed and said so.
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

    /**
     * Every file the manifest points at, as paths relative to the build dir.
     *
     * `file`, `css` and `assets` — the chunk itself, its attached stylesheets,
     * and anything else Vite emitted (the zxing wasm binary is one of these,
     * and the largest file in the directory).
     *
     * @return list<string>
     */
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

    /**
     * Write the snapshot for this build, unless one already exists.
     *
     * NOT overwritten on a re-run — `recorded_at` orders the ledger, so
     * refreshing it would make today's scheduled run look like a new build
     * and push a genuinely newer one out of the window.
     *
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

            /*
             * MICROSECONDS, load bearing: `recorded_at` is the only thing
             * that orders the ledger, and two deploys can land in the same
             * second (a one-page rebuild takes well under one) — at second
             * resolution the sort ties and retention starts dropping the
             * wrong build.
             */
            'recorded_at' => now()->format('Y-m-d\TH:i:s.uP'),

            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Keep the newest `$keep` snapshots; delete the rest.
     *
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

    /**
     * Delete everything in `assets/` that no retained snapshot names.
     *
     * Scoped to this one directory on purpose — `public/build` also holds
     * `manifest.json` and the ledger, and pruning the whole tree would be one
     * glob away from deleting its own bookkeeping.
     *
     * A KEPT FILE KEEPS ITS SOURCE MAP. `vite.config.js` sets
     * `build.sourcemap: true`, but Vite doesn't list `app-XYZ.js.map` in the
     * manifest, only `app-XYZ.js` — so a prune keyed purely off the manifest
     * deleted every map, on every deploy, immediately, with no visible
     * symptom until an inspector needed one months later. Each retained name
     * is now kept with `.map` appended, whether or not that file exists.
     *
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
