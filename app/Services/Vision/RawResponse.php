<?php

declare(strict_types=1);

namespace App\Services\Vision;

use JsonException;

/**
 * The answer dug back out of a stored `vision_requests.raw_response`.
 *
 * One reader for every class that replays a stored row, because the shape it
 * has to cope with is the message as the API sent it, not as any one answer
 * looks: the row keeps the message WHOLE, so the document sits in the first
 * `text` block, past any `thinking` block — hence walking the content rather
 * than indexing into it.
 */
final class RawResponse
{
    /**
     * Null whenever the row holds no readable document — a failed request, a
     * refusal, an unrecognised shape, or valid JSON that is a bare scalar
     * rather than the object asked for. What to do about that belongs to the
     * caller; it is not this class's business to invent an empty answer.
     *
     * Depth 64 because that is what DocumentCall decodes the live answer at:
     * a shallower limit here would replay a document the call accepted and
     * stored as though the row held nothing readable.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function firstJsonObject(array $raw): ?array
    {
        foreach ((array) ($raw['content'] ?? []) as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            try {
                $decoded = json_decode((string) ($block['text'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            if (! is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        return null;
    }
}
