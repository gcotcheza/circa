/**
 * Getting a barcode detector, from wherever one can be had. This module is
 * statically imported; everything expensive is behind the `import()` below,
 * which is what keeps the wasm out of the main bundle.
 */

/**
 * Retail food barcodes only. Narrowing is accuracy and speed: every enabled
 * symbology is another set of hypotheses the decoder tests on every frame.
 * ITF-14 is deliberately absent — it is the outer-case code on shipping
 * cartons, and Open Food Facts is keyed on the consumer unit.
 */
export const BARCODE_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e']

let chunk = null

/** The dynamic import is memoised: one fetch of the wasm chunk per page life. */
function loadChunk() {
  chunk ??= import('./barcode-reader.js')

  return chunk
}

/**
 * Does this browser have a usable native BarcodeDetector?
 *
 * "Usable" includes our formats: an implementation can exist and support only
 * QR codes, and discovering that mid-scan looks like a camera that refuses to
 * see anything. On the only phone this app has the answer is no (WebKit ships
 * none, bug 281848); this is for the desk-bound Chrome that develops it.
 */
async function nativeDetector() {
  if (typeof globalThis.BarcodeDetector === 'undefined') return null

  try {
    const supported = await globalThis.BarcodeDetector.getSupportedFormats()

    if (!BARCODE_FORMATS.every((format) => supported.includes(format))) return null

    return new globalThis.BarcodeDetector({ formats: BARCODE_FORMATS })
  } catch {
    return null
  }
}

/**
 * @returns {Promise<{detect: (source) => Promise<Array>, engine: 'native'|'wasm'}>}
 */
export async function createBarcodeDetector() {
  const native = await nativeDetector()

  if (native) {
    return { detect: (source) => native.detect(source), engine: 'native' }
  }

  const module = await loadChunk()

  // Instantiating the wasm can fail (truncated download, wrong MIME type).
  // Awaiting HERE surfaces that as "the scanner could not start", with manual
  // entry one tap away, rather than a detect() that never resolves.
  await module.whenReady()

  const detector = module.createDetector(BARCODE_FORMATS)

  return { detect: (source) => detector.detect(source), engine: 'wasm' }
}
