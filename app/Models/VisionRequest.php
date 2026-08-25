<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\VisionRequestFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One call to the model, kept for audit and for prompt evaluation.
 *
 * Photo or text: `request_kind` says which, and it is the only difference. A
 * text estimate claims a row the same way, is keyed on the same idempotency
 * key, and moves the same meal through the same states.
 *
 * @property int $id
 * @property int|null $meal_id the meal this call was about; NULL on a supplement-label read, which is about no meal at all
 * @property int|null $meal_photo_id which plate this call looked at; NULL on the text path
 * @property VisionRequestKind $request_kind
 * @property string $idempotency_key
 * @property string $model
 * @property string $prompt_version
 * @property string|null $image_sha256
 * @property array<string, mixed>|null $input_payload the typed meal on a text request; {"hint": "…"} on a hinted photo one
 * @property array<string, mixed>|null $raw_response
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $latency_ms
 * @property VisionRequestStatus $status
 * @property string|null $error
 */
final class VisionRequest extends Model
{
    /** @use HasFactory<VisionRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'        => VisionRequestStatus::class,
            'request_kind'  => VisionRequestKind::class,
            'input_payload' => 'array',
            'raw_response'  => 'array',
            'input_tokens'  => 'integer',
            'output_tokens' => 'integer',
            'latency_ms'    => 'integer',
        ];
    }

    /** @return BelongsTo<Meal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * The calls claimed against one client-generated id.
     *
     * `input_payload->client_id` is a JSON PATH, not a column — the phone
     * generates the id before any row exists, and it is stored inside the
     * payload rather than beside it because nothing else queries by it.
     * Written here rather than in each controller so the one thing static
     * analysis cannot follow is stated once.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForClientId(Builder $query, string $clientId): void
    {
        // @phpstan-ignore argument.type (Larastan checks where() columns against the model's properties and has no spelling for a JSON path)
        $query->where('input_payload->client_id', $clientId);
    }

    /**
     * The plate this call looked at.
     *
     * NULL on the text path, and NULL again once retention has deleted the
     * photograph — the audit row outlives the image on purpose, because what it
     * cost and what it answered stay true after the picture is gone.
     *
     * @return BelongsTo<MealPhoto, $this>
     */
    public function mealPhoto(): BelongsTo
    {
        return $this->belongsTo(MealPhoto::class);
    }
}
