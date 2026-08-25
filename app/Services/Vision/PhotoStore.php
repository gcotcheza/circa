<?php

declare(strict_types=1);

namespace App\Services\Vision;

use GdImage;
use App\Models\Meal;
use RuntimeException;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Meal photos on disk: write, read, prune, forget.
 *
 * The server re-encodes on purpose: the browser already downscales to
 * ~1024 px through a canvas, but doing it again here turns that into a
 * guarantee. EXIF (kitchen GPS, on an iPhone plate photo) is stripped
 * because GD's encoder never carries metadata across, so a client whose
 * canvas step silently failed can't smuggle any through — and the sha256
 * in `vision_requests` ends up hashing a canonical rendering, not whatever
 * the client produced.
 *
 * Paths carry their own meaning: after the retention prune deletes an
 * original, `meal_photos.path` is REPOINTED at the thumbnail rather than
 * nulled, so the `originals/`/`thumbs/` prefix is how the app knows the
 * full-size image is gone — re-analysis reads that and refuses rather than
 * asking the model to identify a dinner from a 256 px thumbnail.
 *
 * One file per plate, named by the client id:
 * `originals/Y/m/{meal-uuid}/{client-id}.jpg`. The path is DERIVED rather
 * than allocated, so two flushes of the same queued upload write the same
 * bytes to the same place and the loser has nothing to clean up.
 */
final class PhotoStore
{
    private const ORIGINALS = 'originals/';

    private const THUMBS = 'thumbs/';

    /**
     * Supplement labels live under their own prefix, and the prefix IS the
     * retention policy: `pruneOriginalsEatenBefore()` matches `originals/%`
     * joined to `meals`, so nothing under `labels/` is ever caught,
     * intentionally. A meal photo is evidence for a decision already
     * written into `meal_items` and becomes a liability after ninety days;
     * a label is reference data about a bottle still owned, checked long
     * after purchase (is the magnesium really 375 mg?).
     *
     * The exemption's cost is bounded by supplement count (a couple dozen
     * files, once), not by how often the person eats.
     */
    private const LABEL_ORIGINALS = 'labels/originals/';

    private const LABEL_THUMBS = 'labels/thumbs/';

    public function disk(): Filesystem
    {
        return Storage::disk('meal-photos');
    }

    /**
     * Re-encode, hash, write the original and its thumbnail.
     *
     * @param  string  $mealUuid  the meal this plate belongs to — directory only
     * @param  string  $clientId  the photo's own id — file name, and idempotency
     *
     * @throws UnreadablePhoto
     */
    public function store(string $mealUuid, string $clientId, string $uploadedBytes, ?CarbonImmutable $at = null): StoredPhoto
    {
        $at ??= CarbonImmutable::now();

        $path = self::ORIGINALS.$at->format('Y/m').'/'.$mealUuid.'/'.$clientId.'.jpg';

        return $this->write($path, $this->thumbPathFor($path), $uploadedBytes, (int) config('health.vision.max_edge_px'));
    }

    /**
     * The same treatment for a supplement label: re-encode, strip EXIF,
     * write a thumbnail beside it — AT TWICE THE RESOLUTION.
     *
     * A meal's 1024 px ceiling suits "how much rice is that, roughly." A
     * supplement-facts panel is 5-point type, and the question is what the
     * printed number IS — run one through the meal pipeline and "25 µg" and
     * "2,5 µg" become the same grey smudge, silently: a transcription can't
     * say "I could not read it" the way an estimate widens its range, it
     * just comes back wrong, looking right. So labels go through at
     * `label_max_edge_px` (2048), inside what the model reads without
     * downsampling — four times a plate's image tokens, once per bottle,
     * against a lifetime of correct figures. The thumbnail stays at 256 px,
     * only ever needing to be recognisable at 40 px in a list.
     *
     * Privacy is if anything a stronger argument here. A photograph of a
     * bottle on a kitchen counter carries the same GPS coordinates an
     * iPhone puts on everything, and the thing being photographed is a
     * medical fact about the person holding the phone. The path is
     * DERIVED from the client id as a plate's is, so a replayed upload has
     * nothing for the losing request to clean up.
     *
     * @param  string  $clientId  the capture's own id — file name, and idempotency
     *
     * @throws UnreadablePhoto
     */
    public function storeLabel(string $clientId, string $uploadedBytes, ?CarbonImmutable $at = null): StoredPhoto
    {
        $at ??= CarbonImmutable::now();

        $path = self::LABEL_ORIGINALS.$at->format('Y/m').'/'.$clientId.'.jpg';

        return $this->write(
            $path,
            $this->labelThumbPathFor($path),
            $uploadedBytes,
            (int) config('health.vision.label_max_edge_px'),
        );
    }

    public function labelThumbPathFor(string $originalPath): string
    {
        return self::LABEL_THUMBS.substr($originalPath, strlen(self::LABEL_ORIGINALS));
    }

    /** The bytes to send to the model, or null if the file is not there. */
    public function bytesAt(?string $path): ?string
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            return null;
        }

        return $this->disk()->get($path);
    }

    /**
     * Delete a label and its thumbnail.
     *
     * Called when a supplement is deleted outright. Deactivating one does NOT
     * come through here: the bottle is off the card but the record of the
     * evenings it was taken is still true, and so is the label those evenings
     * were about.
     */
    public function forgetLabel(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach (array_unique([$path, $this->labelThumbPathFor($path)]) as $candidate) {
            if ($this->disk()->exists($candidate)) {
                $this->disk()->delete($candidate);
            }
        }
    }

    /**
     * The shared half of both stores: decode once, encode twice, write both.
     *
     * `$maxEdge` is a parameter rather than a config read, because it is the
     * one thing the two callers genuinely disagree about — see storeLabel().
     *
     * @throws UnreadablePhoto
     */
    private function write(string $path, string $thumbPath, string $uploadedBytes, int $maxEdge): StoredPhoto
    {
        $source = @imagecreatefromstring($uploadedBytes);

        if ($source === false) {
            throw new UnreadablePhoto('That file could not be read as an image.');
        }

        // No imagedestroy(): GdImage is garbage-collected since PHP 8.0, and
        // the function is deprecated in 8.5 — calling it warns for nothing.
        $canonical = $this->encode($source, $maxEdge);
        $thumbnail = $this->encode($source, (int) config('health.vision.thumb_edge_px'));

        $this->disk()->put($path, $canonical['bytes']);
        $this->disk()->put($thumbPath, $thumbnail['bytes']);

        return new StoredPhoto(
            path: $path,
            thumbPath: $thumbPath,
            sha256: hash('sha256', $canonical['bytes']),
            bytes: strlen($canonical['bytes']),
            width: $canonical['width'],
            height: $canonical['height'],
        );
    }

    /** The bytes to send to the model, or null if there is no full-size image. */
    public function originalBytes(MealPhoto $photo): ?string
    {
        if (! $this->isOriginal($photo->path) || ! $this->disk()->exists($photo->path)) {
            return null;
        }

        return $this->disk()->get($photo->path);
    }

    public function isOriginal(?string $path): bool
    {
        return $path !== null && str_starts_with($path, self::ORIGINALS);
    }

    public function thumbPathFor(string $originalPath): string
    {
        return self::THUMBS.substr($originalPath, strlen(self::ORIGINALS));
    }

    /**
     * Delete every trace of ONE plate's photograph.
     *
     * The row is the caller's to delete — this is only the files, because
     * whether the ITEMS that came off the plate go with it is a question the
     * user answers rather than a consequence of deleting a picture.
     */
    public function forgetPhoto(MealPhoto $photo): void
    {
        $paths = $this->isOriginal($photo->path)
            ? [$photo->path, $photo->thumb_path]
            : [$photo->path];

        foreach (array_unique($paths) as $candidate) {
            if ($this->disk()->exists($candidate)) {
                $this->disk()->delete($candidate);
            }
        }
    }

    /**
     * Delete every trace of every photo on a meal.
     *
     * Called when the meal itself is deleted — "Discard" on a proposal, or any
     * other deletion. `meal_items` is the record of what was eaten; a meal that
     * no longer exists has no record to support, so the pictures go too.
     */
    public function forget(Meal $meal): void
    {
        foreach ($meal->photos()->get() as $photo) {
            $this->forgetPhoto($photo);
        }
    }

    /**
     * Retention: delete originals for meals eaten before the cutoff, keep the
     * thumbnail, and repoint `meal_photos.path` at it.
     *
     * Idempotent by construction: it only looks at rows still under
     * `originals/`, and the first thing it does to one is stop it being
     * one — so a second run finds nothing, and a run after a restore finds
     * only what was restored.
     *
     * The cutoff is on the MEAL's `eaten_at`, not the photo's `created_at`:
     * a dinner backfilled last week isn't a fresh photograph, and a dessert
     * shot half an hour later expires with the meal rather than after it —
     * the only reading under which a meal's plates disappear together.
     *
     * @return int photos pruned
     */
    public function pruneOriginalsEatenBefore(CarbonImmutable $cutoff): int
    {
        $pruned = 0;

        $this->prunable($cutoff)
            ->orderBy('meal_photos.id')
            ->chunkById(200, function ($photos) use (&$pruned): void {
                foreach ($photos as $photo) {
                    if ($this->disk()->exists($photo->path)) {
                        $this->disk()->delete($photo->path);
                    }

                    // No thumbnail (pre-thumbnail photo, half-written
                    // upload) leaves nothing to point at, and a missing path
                    // is worse than the original — left alone, uncounted.
                    // Can't loop: the original's already gone.
                    if (! $this->disk()->exists($photo->thumb_path)) {
                        continue;
                    }

                    $photo->path = $photo->thumb_path;

                    // saveQuietly: housekeeping about a photograph, not a
                    // change to what was eaten — firing observers would queue
                    // a daily-summary rebuild per pruned day.
                    $photo->saveQuietly();

                    $pruned++;
                }
            }, 'meal_photos.id', 'id');

        return $pruned;
    }

    /** How many originals `pruneOriginalsEatenBefore()` would delete. */
    public function prunableCount(CarbonImmutable $cutoff): int
    {
        return $this->prunable($cutoff)->count();
    }

    /**
     * @return Builder<MealPhoto>
     */
    private function prunable(CarbonImmutable $cutoff): Builder
    {
        return MealPhoto::query()
            ->select('meal_photos.*')
            ->join('meals', 'meals.id', '=', 'meal_photos.meal_id')
            ->where('meal_photos.path', 'like', self::ORIGINALS.'%')
            ->where('meals.eaten_at', '<', $cutoff);
    }

    /**
     * Fit inside a square of `$maxEdge`, flatten onto white, encode as JPEG.
     *
     * Flattening matters for PNG and WebP uploads: JPEG has no alpha channel,
     * and copying a transparent background straight across renders it black.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function encode(GdImage $source, int $maxEdge): array
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min(1.0, $maxEdge / max($width, $height));

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        $white = imagecolorallocate($canvas, 255, 255, 255);

        // False means a full palette — impossible on a truecolor canvas.
        // strict_types already turns the next line into a TypeError about an
        // argument position; this says what actually went wrong instead.
        if ($white === false) {
            throw new RuntimeException('GD could not allocate white to flatten onto.');
        }

        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, (int) config('health.vision.jpeg_quality'));
        $bytes = (string) ob_get_clean();

        return ['bytes' => $bytes, 'width' => $targetWidth, 'height' => $targetHeight];
    }
}
