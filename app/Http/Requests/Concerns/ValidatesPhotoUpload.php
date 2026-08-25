<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * A photograph uploaded for the model to read: a meal plate or a supplement
 * label, accepted on identical terms.
 *
 * `image` checks the real MIME type rather than the extension, so a renamed
 * file is rejected here instead of by GD later. HEIC is deliberately absent
 * — the browser re-encodes through a canvas before upload, so what arrives
 * is always JPEG, and GD cannot decode HEIC anyway. The size ceiling is
 * generous (a 1024 px JPEG is ~150-400 KB): enough that a client whose
 * canvas step failed still gets through, small enough to block
 * disk-filling abuse.
 *
 * The messages travel with the rules because they are what the user reads
 * when a ceiling is hit, and a plate and a label refusing the same
 * photograph in different words would be a difference the app cannot
 * justify.
 */
trait ValidatesPhotoUpload
{
    /** @return list<string> */
    protected function photoRules(): array
    {
        return [
            'required',
            'file',
            'image',
            'mimes:jpeg,jpg,png,webp',
            'max:'.config('health.vision.max_upload_kb'),
        ];
    }

    /** @return array<string, string> */
    protected function photoMessages(): array
    {
        return [
            'photo.max'   => 'That photo is too large. It should have been shrunk before upload.',
            'photo.mimes' => 'That is not a photo this app can read. JPEG, PNG or WebP.',
        ];
    }
}
