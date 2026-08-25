<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Real image bytes for the photo tests, generated rather than committed as
 * fixtures: a binary blob in the repository is a thing nobody can read in a
 * diff, and the one property these tests care about — "does an EXIF block
 * survive the pipeline?" — is clearer as six lines of byte-splicing.
 */
final class TestImage
{
    /**
     * A plain JPEG with no metadata at all.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    public static function jpeg(int $width = 1600, int $height = 1200): string
    {
        $image = imagecreatetruecolor($width, $height);

        $fill = imagecolorallocate($image, 240, 235, 220);
        $outline = imagecolorallocate($image, 190, 120, 70);
        assert($fill !== false && $outline !== false, 'the colour table on a fresh truecolor image is never full');

        // Structure, so the encoder cannot compress a flat colour below its header.
        imagefilledrectangle($image, 0, 0, $width, $height, $fill);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.6), (int) ($height * 0.6), $outline);

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /**
     * The same JPEG with an APP1 `Exif` segment spliced in after the SOI marker —
     * the shape a phone camera produces, and the one that carries GPS. Decoders
     * skip unknown APP segments, so it is still a valid JPEG; the point is the
     * literal bytes `Exif\0\0`, which the stored file must not contain.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    public static function jpegWithExif(int $width = 1600, int $height = 1200): string
    {
        $jpeg = self::jpeg($width, $height);

        // A minimal little-endian TIFF header with zero IFD entries, plus a
        // recognisable marker so the assertion cannot pass by accident.
        $payload = "Exif\0\0"."II\x2a\0\x08\0\0\0\0\0".'GPS-COORDINATES-OF-A-KITCHEN';

        // APP1: FF E1, then a 2-byte big-endian length that includes itself.
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // After SOI (FF D8), before everything else.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * A PNG, to prove the pipeline is not JPEG-only on the way in.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    public static function png(int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        $fill = imagecolorallocate($image, 20, 140, 120);
        assert($fill !== false, 'the colour table on a fresh truecolor image is never full');
        imagefilledrectangle($image, 0, 0, $width, $height, $fill);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
