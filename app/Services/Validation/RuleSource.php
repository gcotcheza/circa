<?php

declare(strict_types=1);

namespace App\Services\Validation;

/**
 * One set of rules the browser mirrors, named as the browser refers to them.
 * Not always a FormRequest — the login rules live in a controller constant
 * instead.
 */
final readonly class RuleSource
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $name,
        public array $rules,
        public array $messages = [],
        public array $attributes = [],
    ) {}
}
