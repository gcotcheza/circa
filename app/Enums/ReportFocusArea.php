<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five areas a report can be pointed at.
 *
 * Not the five blocks in the fact sheet (`anchor_statistics` and `coverage`
 * are machinery nobody asks a report about) but the five subjects somebody
 * would actually name: sleep, eating, training, weight, stress. A closed
 * set on purpose — the alternative, reading the free-text box to guess
 * which blocks it implies, is an NLP guess in front of a deterministic
 * assembler, and a wrong guess writes the report from facts that quietly
 * lack the answer. A chip is a promise the app can keep: tap Stress and the
 * stress blocks are in the document, checkably, every time — free text
 * steers the WRITING, chips decide the FACTS. No chips isn't a sixth case;
 * it means everything, what every report before this feature was and what
 * the weekly cron still defaults to.
 */
enum ReportFocusArea: string
{
    case Stress = 'stress';
    case Sleep = 'sleep';
    case Food = 'food';
    case Training = 'training';
    case Weight = 'weight';

    /** The chip's own word, and the one the archive line prints. */
    public function label(): string
    {
        return match ($this) {
            self::Stress   => 'Stress',
            self::Sleep    => 'Sleep',
            self::Food     => 'Food',
            self::Training => 'Training',
            self::Weight   => 'Weight',
        };
    }

    /**
     * What this area means, said to the model rather than to the user.
     *
     * The prompt names the selected areas back; a bare word list would leave
     * "Weight" to be interpreted, and the whole point of the focus block is that
     * the reader's request and the facts in front of it agree about what was
     * asked for.
     */
    public function forModel(): string
    {
        return match ($this) {
            self::Stress   => 'stress scores, recovery, and how rested they have been',
            self::Sleep    => 'sleep duration, timing and consistency, and what follows a short night',
            self::Food     => 'what they ate, the energy balance, and the supplement side',
            self::Training => 'the sessions they did, the gaps between them, and what the movement cost',
            self::Weight   => 'weight, the trend line under it, and body composition',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
