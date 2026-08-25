/**
 * Turning a camera capture into something worth uploading.
 *
 * The server re-encodes anyway (App\Services\Vision\PhotoStore), so the 1024 px
 * ceiling and the EXIF strip are not *enforced* here. Only the client can solve
 * three things: an iPhone photo is 3-6 MB, so shrinking it first is a
 * two-second upload rather than a twenty-second one; iOS shoots HEIC, which GD
 * cannot decode, so without this re-encode the server would reject half the
 * photos the phone takes; and the GPS of somebody's kitchen never leaves the
 * device, since reading a canvas back as JPEG yields pixels and nothing else.
 *
 * ORIENTATION. Decoding through an <img> rather than createImageBitmap is what
 * applies the EXIF orientation tag (Safari does it automatically, an
 * ImageBitmap does not unless asked), and drawing that <img> bakes the rotation
 * in — needed, as the output JPEG has no EXIF left to carry it.
 */

const MAX_EDGE = 1024
const QUALITY = 0.82

/**
 * @returns {Promise<{blob: Blob, url: string, width: number, height: number}>}
 */
export async function preparePhoto(file, { maxEdge = MAX_EDGE, quality = QUALITY } = {}) {
  const source = await decode(file)

  const scale = Math.min(1, maxEdge / Math.max(source.width, source.height))
  const width = Math.max(1, Math.round(source.width * scale))
  const height = Math.max(1, Math.round(source.height * scale))

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height

  const context = canvas.getContext('2d')

  // PNGs and screenshots can be transparent; JPEG cannot, and an unfilled
  // canvas composites to black.
  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, width, height)
  context.drawImage(source.image, 0, 0, width, height)

  source.release()

  const blob = await toBlob(canvas, quality)

  if (!blob) throw new Error('The photo could not be prepared on this device.')

  return { blob, url: URL.createObjectURL(blob), width, height }
}

async function decode(file) {
  const url = URL.createObjectURL(file)
  const image = new Image()

  image.src = url

  try {
    // decode() rather than the load event: it resolves when the pixels are
    // ready to draw, which on iOS is a later moment.
    if (typeof image.decode === 'function') {
      await image.decode()
    } else {
      await new Promise((resolve, reject) => {
        image.onload = resolve
        image.onerror = () => reject(new Error('That file could not be read as a photo.'))
      })
    }
  } catch {
    URL.revokeObjectURL(url)

    throw new Error('That file could not be read as a photo.')
  }

  return {
    image,
    width: image.naturalWidth,
    height: image.naturalHeight,
    release: () => URL.revokeObjectURL(url),
  }
}

function toBlob(canvas, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
}
