import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

/*
 * The zxing-wasm version that was ACTUALLY installed.
 *
 * `barcode-detector` bundles Emscripten glue compiled against one exact
 * zxing-wasm build, and resources/js/lib/barcode-reader.js imports the .wasm
 * binary out of that package. If npm ever hoists one version while nesting
 * another, the glue and the binary stop matching and the decoder fails at
 * instantiation with an unreadable error. Injecting the installed version lets
 * the reader assert the pair matches and say so in words instead.
 *
 * Read from the file rather than `require('zxing-wasm/package.json')` because
 * the package's `exports` map does not expose its own package.json.
 */
const zxingWasmVersion = JSON.parse(
    readFileSync(new URL('./node_modules/zxing-wasm/package.json', import.meta.url), 'utf8')
).version;

export default defineConfig({
    define: {
        __ZXING_WASM_VERSION__: JSON.stringify(zxingWasmVersion),
    },
    /*
     * `axios` is resolved as ONE module, on purpose.
     *
     * resources/js/lib/inertia-guard.js registers an axios response interceptor
     * to catch a malformed Inertia response before @inertiajs/core dereferences
     * it. That only works if the app's `import axios from 'axios'` and the
     * core's own `import { default as axios } from 'axios'` are the same
     * singleton. In a production build rollup resolves both to the one
     * deduplicated copy in node_modules and they are; in dev, the dependency
     * pre-bundler would otherwise inline a private copy of axios into the
     * optimised `@inertiajs/vue3` chunk and the interceptor would be registered
     * on an axios nobody calls — working in production, silently inert under
     * `npm run dev`, which is the worst of the two orders to find out in.
     *
     * Listing it as its own optimised entry makes both importers share it.
     */
    optimizeDeps: {
        include: ['axios'],
    },
    build: {
        /*
         * SOURCE MAPS ARE SHIPPED.
         *
         * Every client-side fault this app has ever recorded arrived as a
         * minified stack — `rb@app-BozACjIO.js:15:16699` — and the one that
         * mattered took reading @inertiajs/core's dist by hand to decode. That
         * is an afternoon's work to answer a question a source map answers
         * instantly, and it is the reason "make production errors traceable"
         * has been on the list since the reporter was written.
         *
         * `true` rather than `'hidden'`, deliberately. `hidden` emits the maps
         * but omits the `//# sourceMappingURL` comment, so nothing loads them
         * and symbolication is a manual job with a copy of the map — which is
         * only worth it when you are hiding source from the public. This app
         * has one user, sits behind a login, and its repository is private for
         * tidiness rather than secrecy; the bundle already contains the logic
         * that the map merely makes legible. In exchange, opening the inspector
         * on the phone shows real filenames and real line numbers, which is the
         * entire point.
         *
         * Two consequences, both handled rather than assumed:
         *   - `.map` files are NOT in the Vite manifest, so the service worker
         *     never precaches them and a browser only fetches one when the
         *     inspector is open. First paint is unaffected.
         *   - `php artisan build:retain` prunes `public/build/assets` down to
         *     the files the retained manifests name — which would have deleted
         *     every map on the next run. It now keeps the `.map` beside each
         *     file it keeps. See RetainBuildsCommand::pruneAssets.
         */
        sourcemap: true,

        /*
         * DO NOT let the build empty public/build.
         *
         * Vite's default is to wipe the output directory, which means every
         * deploy DELETES the previous build's chunks. This app is a
         * client-rendered PWA: a page still open across a deploy asks for the
         * photo-capture or review-sheet chunk by its old hashed name and gets a
         * 404, and a document served from any cache asks for a deleted entry
         * chunk and renders a completely blank white page. Both were live bugs;
         * neither left a trace in the nginx log.
         *
         * Keeping the old files costs a few hundred kilobytes per build and
         * makes a briefly-stale reference resolve instead of dying. The
         * directory is not allowed to grow without limit: `php artisan
         * build:retain` runs after this and keeps the newest three builds by
         * name, from a ledger it writes, and deletes the rest. It also runs on
         * the daily schedule, so a forgotten deploy step is a day of extra
         * chunks rather than a full disk.
         */
        emptyOutDir: false,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
