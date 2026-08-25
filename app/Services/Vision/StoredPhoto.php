<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * What PhotoStore wrote, and the hash of the bytes actually sent.
 *
 * `sha256` is of the RE-ENCODED image, not the upload (per the
 * `vision_requests` migration comment) — it identifies the bytes the model
 * saw, so two uploads of the same photo from different devices (different
 * EXIF, different encoder) collapse to one hash instead of looking like
 * two experiments.
 */
final readonly class StoredPhoto
{
    public function __construct(
        public string $path,
        public string $thumbPath,
        public string $sha256,
        public int $bytes,
        public int $width,
        public int $height,
    ) {}
}
