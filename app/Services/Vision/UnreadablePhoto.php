<?php

declare(strict_types=1);

namespace App\Services\Vision;

use RuntimeException;

/**
 * The upload was not an image this app can decode.
 *
 * Distinct from a MIME-type validation failure, which only reads what the
 * client claimed — this is thrown after GD has actually looked at the
 * bytes (a `.jpg` that's really a HEIC, a truncated upload), and becomes a
 * 422 rather than a 500.
 */
final class UnreadablePhoto extends RuntimeException {}
