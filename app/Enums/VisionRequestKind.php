<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What was actually sent to the model.
 *
 * `vision_requests` is named for the pipeline, not the modality: the
 * state machine (analyzing -> proposed -> confirmed | failed), the
 * idempotency key, the audit row and the review sheet are all identical
 * whether the evidence was a photograph or a typed sentence. This column
 * is the one difference that has to stay queryable — the two prompts are
 * versioned separately and cost differently, and "how good is the text
 * path?" is a question about a subset of the rows.
 */
enum VisionRequestKind: string
{
    /** A photograph, downscaled and EXIF-stripped. `image_sha256` identifies it. */
    case Photo = 'photo';

    /** The meal as the user typed it. `input_payload` holds it verbatim. */
    case Text = 'text';

    /**
     * A photograph of a supplement label. `meal_id` is NULL on these rows —
     * there is no meal, and at the moment the row is claimed there is no
     * supplement either, because the user reviews the transcription before
     * agreeing to create one. See the migration that widened the column.
     */
    case Label = 'label';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
