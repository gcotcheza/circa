<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Support\Facades\File;

/**
 * What the service worker is allowed to keep, and what version "kept" means.
 *
 * The precache list is READ from `public/build/manifest.json` (the same
 * file `@vite()` reads for script tags) rather than hand-written, since
 * hashes move every build and precaching a deleted, hand-listed asset
 * fails silently. PRECACHED: entry chunks, the offline page, manifest, and
 * icons. NOT PRECACHED: lazy chunks, above all the ~1 MB zxing
 * WebAssembly binary — precaching runs on INSTALL, and downloading a
 * megabyte of barcode decoder on mobile data for a scanner that may not
 * open that week is exactly what the dynamic import avoids; the runtime
 * cache-first rule for `/build/` picks those up once genuinely fetched.
 *
 * THE VERSION is a hash of the manifest, changing only when the build
 * output does — a deploy busts the cache, a restart doesn't — and is what
 * makes `/sw.js`'s bytes differ after a deploy, the only thing that makes
 * a browser treat it as new.
 */
final class BuildAssets
{
    /**
     * Everything precached that is not a build asset — the offline page is
     * first, since its absence turns the whole feature off.
     *
     * @var list<string>
     */
    public const STATIC_ASSETS = [
        '/offline',
        '/manifest.webmanifest',
        '/icons/icon-192.png',
        '/icons/icon-512.png',
        '/icons/apple-touch-icon-180.png',
    ];

    public function __construct(private readonly ?string $manifestPath = null) {}

    /**
     * Absolute paths, from the site root, of every URL the worker precaches.
     *
     * @return list<string>
     */
    public function precacheUrls(): array
    {
        return [...self::STATIC_ASSETS, ...$this->entryUrls()];
    }

    /**
     * The entry chunks — `resources/js/app.js` and `resources/css/app.css` —
     * and any CSS Vite attached to them.
     *
     * @return list<string>
     */
    public function entryUrls(): array
    {
        $urls = [];

        foreach ($this->manifest() as $chunk) {
            if (($chunk['isEntry'] ?? false) !== true) {
                continue;
            }

            $urls[] = '/build/'.$chunk['file'];

            foreach ($chunk['css'] ?? [] as $css) {
                $urls[] = '/build/'.$css;
            }
        }

        // Vite lists an entry's CSS on the entry AND as its own manifest
        // key when the stylesheet is itself an input, so a duplicate can
        // arrive and make the worker fetch it twice on install.
        return array_values(array_unique($urls));
    }

    /**
     * Short, stable, changes exactly when the build does. Falls back to the
     * app version when there's no build yet, so a fresh checkout without
     * `npm run build` still serves a coherent worker rather than throwing.
     */
    public function version(): string
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return 'no-build';
        }

        return substr((string) md5_file($path), 0, 12);
    }

    public function hasBuild(): bool
    {
        return File::exists($this->path());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifest(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function path(): string
    {
        return $this->manifestPath ?? public_path('build/manifest.json');
    }
}
