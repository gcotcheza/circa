<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Vision\PhotoStore;

/**
 * Deletes meal-photo originals past retention; thumbnails and the row
 * survive, repointed rather than nulled. Idempotent: only rows still under
 * `originals/` are selected. See docs/rationale-app.md §
 * "PruneMealPhotosCommand: what prune repoints, and why per plate".
 */
final class PruneMealPhotosCommand extends Command
{
    protected $signature = 'photos:prune
                            {--days= : Override the retention window (config: health.vision.photo_retention_days)}
                            {--dry-run : Report what would be deleted, delete nothing}';

    protected $description = 'Delete meal-photo originals past the retention window, keeping their thumbnails';

    public function handle(PhotoStore $photos): int
    {
        $days = (int) ($this->option('days') ?? config('health.vision.photo_retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        // Cutoff is on `eaten_at`, not `created_at`: "older than 90 days" is a
        // statement about the meal, and a backfilled dinner isn't a fresh photo.
        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '%d original(s) eaten before %s would be deleted.',
                $photos->prunableCount($cutoff),
                $cutoff->toDateString(),
            ));

            return self::SUCCESS;
        }

        $pruned = $photos->pruneOriginalsEatenBefore($cutoff);

        $this->line(sprintf(
            '%d original(s) eaten before %s deleted; thumbnails kept.',
            $pruned,
            $cutoff->toDateString(),
        ));

        return self::SUCCESS;
    }
}
