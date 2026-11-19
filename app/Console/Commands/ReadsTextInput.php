<?php

declare(strict_types=1);

namespace App\Console\Commands;

use LogicException;

/** A required argument, or a value option that may be absent, as text; a caller in code may pass a number. */
trait ReadsTextInput
{
    protected function textArgument(string $name): string
    {
        return $this->asText('argument', $name, $this->argument($name))
            ?? throw new LogicException("The argument '{$name}' of {$this->getName()} has no value: its signature makes it optional.");
    }

    protected function textOptionOrNull(string $name): ?string
    {
        return $this->asText('option', $name, $this->option($name));
    }

    private function asText(string $kind, string $name, mixed $value): ?string
    {
        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new LogicException("The {$kind} '{$name}' of {$this->getName()} is not text: its signature makes it a flag or a list.");
        }

        return $value === null ? null : (string) $value;
    }
}
