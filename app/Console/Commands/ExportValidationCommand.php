<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use App\Services\Validation\ValidationArtifacts;

/**
 * Write the browser's copy of the validation rules, and the cases that hold it to them.
 *
 *   php artisan validation:export           # rewrite both files
 *   php artisan validation:export --check   # say whether they are stale, write nothing
 *
 * The agreement test fails the gate while either file is stale, and names this command.
 */
final class ExportValidationCommand extends Command
{
    protected $signature = 'validation:export {--check : Report staleness as a non-zero exit, write nothing}';

    protected $description = 'Export the server validation rules and sentences for the browser to mirror';

    public function handle(ValidationArtifacts $artifacts): int
    {
        try {
            $generated = $artifacts->all();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $stale = [];

        foreach ($generated as $path => $contents) {
            if ($artifacts->committed($path) === $contents) {
                $this->components->twoColumnDetail($path, '<fg=gray>unchanged</>');

                continue;
            }

            $stale[] = $path;

            if ($this->option('check')) {
                $this->components->twoColumnDetail($path, '<fg=red>stale</>');

                continue;
            }

            $artifacts->write($path, $contents);
            $this->components->twoColumnDetail($path, '<info>written</info>');
        }

        if ($stale !== [] && $this->option('check')) {
            $this->components->error('Out of date. Run: php artisan validation:export');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
