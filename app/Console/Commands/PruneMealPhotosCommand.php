<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Vision\PhotoStore;

/**
 * Photo retention: delete originals past their keep-window, keep thumbnails.
 *
 * `meal_items` is the record of what was eaten; the photograph is evidence
 * for a decision already made and confirmed, so a year of full-resolution
 * dinners is a liability with no matching benefit — every one is a picture
 * of somebody's home. The 256 px thumbnail survives because a list of meals
 * with a picture beside each is how a human recognises "that Tuesday", and
 * costs nothing to keep forever.
 *
 * `meal_photos.path` is REPOINTED at the thumbnail rather than nulled (the
 * step-1 migration sketched nulling, which would orphan the thumbnail and
 * leave nothing on screen). Paths are prefixed (`originals/` vs `thumbs/`),
 * so the column keeps saying which kind of image it holds — how the
 * re-analyse endpoint knows to refuse rather than send a postage stamp to
 * the model.
 *
 * WALKS PLATES, NOT MEALS: a dinner photographed in three courses is three
 * rows, each expiring on the MEAL's `eaten_at` rather than its own
 * `created_at`, so a meal's plates disappear together rather than the
 * dessert outliving the main course.
 *
 * IDEMPOTENT BY CONSTRUCTION: it only selects rows still under `originals/`,
 * and the first thing it does to one is stop it being one — run it twice in
 * a minute and the second run finds nothing.
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
