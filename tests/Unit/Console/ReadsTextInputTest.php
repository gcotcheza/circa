<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TextInputProbeCommand;

final class ReadsTextInputTest extends TestCase
{
    public function test_a_given_argument_and_option_are_their_text(): void
    {
        $command = TextInputProbeCommand::given(['name' => 'meal-uuid', '--date' => '2026-11-13']);

        $this->assertSame('meal-uuid', $command->readArgument('name'));
        $this->assertSame('2026-11-13', $command->readOption('date'));
    }

    public function test_a_number_passed_from_code_is_its_text(): void
    {
        $this->assertSame('7', TextInputProbeCommand::given(['name' => 7])->readArgument('name'));
    }

    public function test_an_absent_option_is_null_not_empty_text(): void
    {
        $this->assertNull(TextInputProbeCommand::given(['name' => 'x'])->readOption('date'));
    }

    public function test_an_absent_argument_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The argument 'nickname' of probe:text has no value");

        TextInputProbeCommand::given(['name' => 'x'])->readArgument('nickname');
    }

    public function test_a_flag_option_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The option 'dry-run' of probe:text is not text");

        TextInputProbeCommand::given(['name' => 'x'])->readOption('dry-run');
    }

    public function test_a_list_option_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The option 'tag' of probe:text is not text");

        TextInputProbeCommand::given(['name' => 'x', '--tag' => ['a']])->readOption('tag');
    }
}
