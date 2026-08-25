<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Enums\VisionRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\MealPhotoFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One plate of a meal: the photograph, and everything that came off it.
 *
 * A "photo entry" is the vision pipeline's unit — uploaded, analysed (one
 * image, one `vision_requests` row, one call), reviewed, confirmed on its
 * own; confirming appends to the meal, not replaces it.
 *
 * @property int $id
 * @property int $meal_id
 * @property string $client_id generated at capture; the upload's idempotency key
 * @property string $path `originals/…` until retention, `thumbs/…` after
 * @property string $thumb_path
 * @property string|null $sha256 of the RE-ENCODED bytes, i.e. of what was sent
 * @property string|null $hint what the user said this plate is, in their own words
 * @property int $position 0-based; "photo 1" on screen is position 0
 * @property string|null $model_notes what the model said about THIS plate
 * @property numeric-string $share_fraction what the "I ate:" chips are set to; 1.0 is the whole plate
 * @property CarbonImmutable $created_at
 */
final class MealPhoto extends Model
{
    /** @use HasFactory<MealPhotoFactory> */
    use HasFactory;

    /**
     * No `updated_at`: a row is written once, then only ever repointed by
     * retention. A second timestamp would just be `eaten_at` plus the
     * retention window, restated — not worth storing twice.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The client generates it, and it's what the URL says — no internal id
     * is ever exposed to the PWA, exactly as with `meals.uuid`.
     */
    public function getRouteKeyName(): string
    {
        return 'client_id';
    }

    protected function casts(): array
    {
        return [
            'position'   => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Meal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * The items this plate produced — proposed or confirmed.
     *
     * Ordered by id — within one plate, the order the model listed food in
     * and the review sheet walked the user through. Same reasoning as
     * `Meal::items()`: unordered means Postgres heap order, which moves
     * under any UPDATE; plate-position is constant here, so id is the whole
     * tie-break.
     *
     * @return HasMany<MealItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)->orderBy('meal_items.id');
    }

    /** @return HasMany<VisionRequest, $this> */
    public function visionRequests(): HasMany
    {
        return $this->hasMany(VisionRequest::class);
    }

    /**
     * The analysis this entry's state is read from.
     *
     * Re-analysing adds a row, not edits one — the audit table exists for
     * that comparison, so "how is this plate doing" is always about the
     * last attempt.
     *
     * @return HasOne<VisionRequest, $this>
     */
    public function latestVisionRequest(): HasOne
    {
        return $this->hasOne(VisionRequest::class)->latestOfMany();
    }

    /**
     * The plate was shared, and the chips say so.
     *
     * Items carry their own fractions and drive the arithmetic; this is the
     * chip row's own, different, state. A plate at ½ with the pho overridden
     * back to All has items at both fractions while chips still read ½ —
     * not derivable from items, and what a re-analysis re-applies fresh.
     */
    public function isShared(): bool
    {
        return (float) $this->share_fraction < 1.0;
    }

    /** Retention has not taken the full-size image yet. */
    public function hasOriginal(): bool
    {
        return str_starts_with($this->path, 'originals/');
    }

    /**
     * Where this plate is, as one word — the per-ENTRY half of the state
     * machine `meals.status` used to hold alone.
     *
     * Derived, not stored: every input is already a fact elsewhere, and a
     * stored copy would be a fourth place for them to disagree:
     *
     *   analyzing  last request pending or sent.
     *   failed     last request failed, nothing has replaced it.
     *   proposed   answered, nothing agreed to yet.
     *   settled    user has confirmed something off this plate.
     *
     * An empty answer is still outstanding: "proposed" can't mean "has
     * unconfirmed items", because a photo of a chair reaches `proposed` with
     * ZERO items and a note explaining what it shows — a success, and the
     * one case where the model is done and the user has the most to do.
     * Read as `settled` it would drop off the review sheet and the poller's
     * `pending` count, leaving a photograph nothing on screen ever asks
     * about again.
     *
     * So the question runs the other way: has anything been agreed to? A
     * plate with a confirmed item is finished; anything else is still the
     * user's move, including discarding it.
     */
    public function entryState(): string
    {
        $request = $this->relationLoaded('latestVisionRequest')
            ? $this->latestVisionRequest
            : $this->latestVisionRequest()->first();

        if ($request === null) {
            return 'settled';
        }

        if (in_array($request->status, [VisionRequestStatus::Pending, VisionRequestStatus::Sent], true)) {
            return 'analyzing';
        }

        if ($request->status === VisionRequestStatus::Failed) {
            return 'failed';
        }

        // An outstanding proposal wins even where something was confirmed
        // earlier: a re-analysed plate can hold both while the new answer waits.
        if ($this->items()->whereNull('confirmed_at')->exists()) {
            return 'proposed';
        }

        return $this->items()->whereNotNull('confirmed_at')->exists() ? 'settled' : 'proposed';
    }

    /** Waiting on the user, or on the model. Either way the day view says so. */
    public function isPending(): bool
    {
        return $this->entryState() !== 'settled';
    }
}
