/**
 * The zxing-wasm decoder. THIS MODULE IS NEVER IMPORTED STATICALLY.
 *
 * Everything heavy lives behind this file so Vite gives it its own chunk,
 * fetched on the first "Scan" tap rather than on every page load: ~60 KB of
 * Emscripten glue plus a ~1 MB WebAssembly binary, on a view the app opens
 * several times a day, often on mobile data. `lib/scanner.js` is the only
 * importer, via `await import()`, and it falls back here every single time —
 * the only client is an iPhone, every iOS browser is WebKit, and WebKit has no
 * BarcodeDetector (bug 281848, still open).
 */

// `/pure` is the ponyfill: the class without ALSO assigning
// globalThis.BarcodeDetector, which would make scanner.js's native
// feature-detection answer "yes, native" after the first scan.
import { BarcodeDetector, ZXING_WASM_VERSION, prepareZXingModule } from 'barcode-detector/pure'

/*
 * The WebAssembly binary, served by US. zxing-wasm's stock `locateFile` points
 * at fastly.jsdelivr.net, which would put a third-party CDN in the critical
 * path of every scan and hand it a log line every time this user picks up a
 * jar. `?url` emits the file into public/build with a content hash:
 * same-origin, cacheable forever, shipped with the deploy that was tested. The
 * READER build, not the full one — this app never generates barcodes.
 */
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url'

/*
 * The glue is COMPILED AGAINST a specific wasm build; a mismatched pair fails
 * at instantiation with an error that says nothing useful. Hoisting normally
 * gives us the right file, but if a future barcode-detector bumps its
 * zxing-wasm pin while package.json still names the old one, npm nests the new
 * copy and hoists the old, and this import silently resolves to a binary the
 * glue cannot use. __ZXING_WASM_VERSION__ is injected by vite.config.js from
 * the package ACTUALLY installed, so that becomes a legible error here.
 */
if (ZXING_WASM_VERSION !== __ZXING_WASM_VERSION__) {
  throw new Error(
    `zxing-wasm mismatch: barcode-detector expects ${ZXING_WASM_VERSION}, ` +
      `the bundled binary is ${__ZXING_WASM_VERSION__}. Run npm install.`
  )
}

/*
 * Start fetching and instantiating the moment this chunk evaluates:
 * `fireImmediately` returns the promise, so the download overlaps
 * getUserMedia's permission prompt — on iOS, a second or more of the user
 * reading a dialog.
 */
const ready = prepareZXingModule({
  overrides: {
    locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path),
  },
  fireImmediately: true,
})

export function whenReady() {
  return ready
}

export function createDetector(formats) {
  return new BarcodeDetector({ formats })
}
