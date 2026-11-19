<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Console\Command;
use App\Console\Commands\ReadsTextInput;
use Symfony\Component\Console\Input\ArrayInput;

final class TextInputProbeCommand extends Command
{
    use ReadsTextInput;

    protected $signature = 'probe:text {name} {nickname?} {--date=} {--dry-run} {--tag=*}';

    /** @param array<string, mixed> $given */
    public static function given(array $given): self
    {
        $command = new self;
        $command->setInput(new ArrayInput($given, $command->getDefinition()));

        return $command;
    }

    public function readArgument(string $name): string
    {
        return $this->textArgument($name);
    }

    public function readOption(string $name): ?string
    {
        return $this->textOptionOrNull($name);
    }
}
