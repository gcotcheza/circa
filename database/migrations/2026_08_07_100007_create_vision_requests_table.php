<?php

declare(strict_types=1);

use App\Enums\VisionRequestStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Audit trail for every call to the vision model. Persisted state, not
 * fire-and-forget.
 *
 * `idempotency_key` is generated on the client at capture and is unique here:
 * a double-tap must not double-pay, and an offline retry after a flush must
 * land on the row it already created. Claiming the row is what makes the send
 * safe, which is why `pending` exists as a status.
 *
 * Keeping `prompt_version` + `raw_response` turns prompt changes into something
 * evaluable: a new prompt can be replayed against the history of images and
 * diffed, instead of being judged on vibes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();

            $table->string('idempotency_key', 128)->unique();

            // e.g. "claude-opus-5". Recorded per request: the model in use will
            // change, and old rows must keep saying which one produced them.
            $table->string('model', 128);

            $table->string('prompt_version', 32);

            // sha256 of the bytes actually sent — after downscale to ~1024 px
            // long edge and EXIF strip, not of the original. Identical resends
            // are then detectable.
            $table->char('image_sha256', 64)->nullable();

            $table->jsonb('raw_response')->nullable();

            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('latency_ms')->nullable();

            $table->enum('status', VisionRequestStatus::values())
                ->default(VisionRequestStatus::Pending->value);

            $table->text('error')->nullable();

            $table->timestampsTz();

            $table->index('meal_id');
            $table->index(['status', 'created_at']);
            $table->index('image_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_requests');
    }
};
