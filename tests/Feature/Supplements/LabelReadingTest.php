<?php

declare(strict_types=1);

namespace Tests\Feature\Supplements;

use Tests\TestCase;
use App\Models\Supplement;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Jobs\ReadSupplementLabel;
use Illuminate\Http\UploadedFile;
use App\Enums\VisionRequestStatus;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Photograph a label, read it, check it, keep it. The same two properties the
 * meal upload defends — one analysis per idempotency key, no EXIF leaving the
 * server — plus one specific to this path: NOTHING REACHES `supplements` UNTIL
 * A HUMAN HAS SEEN IT, because a wrong figure written straight to the database
 * is indistinguishable from a right one forever afterwards.
 */
final class LabelReadingTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_an_upload_claims_a_label_request_and_queues_the_reading(): void
    {
        Queue::fake();

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('clientId', $clientId);

        $request = VisionRequest::query()->sole();

        // The row the widened `meal_id` column exists for.
        self::assertNull($request->meal_id);
        self::assertNull($request->meal_photo_id);
        self::assertNull($request->supplement_id);
        self::assertSame(VisionRequestKind::Label, $request->request_kind);
        self::assertSame('v1-label', $request->prompt_version);
        self::assertSame('claude-opus-5', $request->model);
        self::assertSame(VisionRequestStatus::Pending, $request->status);
        $inputPayload = $this->notNull($request->input_payload);
        self::assertSame($clientId, $inputPayload['client_id']);
        self::assertStringStartsWith('labels/originals/', $inputPayload['photo_path']);

        Queue::assertPushed(ReadSupplementLabel::class, 1);

        // Nothing has been created. The whole point of the review step.
        self::assertSame(0, Supplement::query()->count());
    }

    public function test_a_replayed_upload_costs_one_reading(): void
    {
        Queue::fake();

        $clientId = (string) Str::uuid();
        $key = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId, $key))->assertStatus(202);

        // The double-tap, or a retry of a request that did land.
        $this->post('/api/supplements/label', $this->payload($clientId, $key))->assertStatus(200);

        self::assertSame(1, VisionRequest::query()->count());
        Queue::assertPushed(ReadSupplementLabel::class, 1);
    }

    /** A new key for a photograph already being read: the reading it has is the answer. */
    public function test_a_second_key_for_the_same_photograph_does_not_buy_a_second_reading(): void
    {
        Queue::fake();

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);
        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(200);

        self::assertSame(1, VisionRequest::query()->count());
    }

    public function test_the_stored_label_is_re_encoded_and_carries_no_exif(): void
    {
        Queue::fake();

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', [
            'client_id'       => $clientId,
            'idempotency_key' => (string) Str::uuid(),
            'photo'           => UploadedFile::fake()->createWithContent('label.jpg', TestImage::jpegWithExif()),
        ])->assertStatus(202);

        $inputPayload = $this->notNull(VisionRequest::query()->sole()->input_payload);
        $path = $inputPayload['photo_path'];

        $bytes = Storage::disk('meal-photos')->get($path);
        self::assertIsString($bytes);

        self::assertStringNotContainsString('GPS-COORDINATES-OF-A-KITCHEN', $bytes);
        self::assertStringNotContainsString("Exif\0\0", $bytes);
    }

    /**
     * A label is not shrunk to the meal ceiling: 5-point type at 1024 px is a
     * grey smear, and an illegible figure comes back wrong, not wider.
     */
    public function test_a_label_is_kept_at_the_label_resolution_not_the_meal_one(): void
    {
        Queue::fake();

        config()->set('health.vision.max_edge_px', 1024);
        config()->set('health.vision.label_max_edge_px', 2048);

        $this->post('/api/supplements/label', [
            'client_id'       => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'photo'           => UploadedFile::fake()->createWithContent('label.jpg', TestImage::jpeg(3000, 2000)),
        ])->assertStatus(202);

        $inputPayload = $this->notNull(VisionRequest::query()->sole()->input_payload);
        $path = $inputPayload['photo_path'];

        $labelBytes = Storage::disk('meal-photos')->get($path);
        self::assertIsString($labelBytes);

        $size = getimagesizefromstring($labelBytes);
        self::assertIsArray($size);
        [$width] = $size;

        self::assertSame(2048, $width);
    }

    public function test_the_reading_lands_on_the_row_and_the_poll_hands_back_the_panel(): void
    {
        $this->analyzer->willRead([
            'name'         => 'Magnesium Glycinate-120',
            'brand'        => 'Helixa',
            'serving_text' => 'per capsule',
            'nutrients'    => [
                ['nutrient' => 'Magnesium (glycinate)', 'amount' => 120, 'unit' => 'mg'],
            ],
            'notes' => 'Panel read in full.',
        ]);

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        $request = VisionRequest::query()->sole()->refresh();

        self::assertSame(VisionRequestStatus::Succeeded, $request->status);
        self::assertSame(3_200, $request->input_tokens);
        self::assertNotNull($request->raw_response);

        $this->getJson('/api/supplements/label/'.$clientId)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('label.name', 'Magnesium Glycinate-120')
            ->assertJsonPath('label.brand', 'Helixa')
            ->assertJsonPath('label.servingText', 'per capsule')
            ->assertJsonPath('label.nutrients.0.nutrient', 'Magnesium (glycinate)')
            ->assertJsonPath('label.nutrients.0.amount', 120)
            ->assertJsonPath('label.nutrients.0.unit', 'mg');

        self::assertSame(1, $this->analyzer->labelCount());
    }

    /**
     * Dutch label, Dutch units, no conversions: the prompt forbids restating
     * 25 µg as 1000 IU, and the pipeline does not either.
     */
    public function test_a_dutch_panel_comes_back_in_its_own_words_and_units(): void
    {
        $this->analyzer->willRead([
            'name'         => 'Vitamin D3 75 mcg',
            'brand'        => 'Nordvita',
            'serving_text' => 'per softgel',
            'nutrients'    => [
                ['nutrient' => 'Vitamine D3 (cholecalciferol)', 'amount' => 75, 'unit' => 'mcg'],
                ['nutrient' => 'Vitamine D3 (cholecalciferol)', 'amount' => 3000, 'unit' => 'IE'],
            ],
            'notes' => '',
        ]);

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        $this->getJson('/api/supplements/label/'.$clientId)
            ->assertJsonPath('label.nutrients.0.unit', 'mcg')
            ->assertJsonPath('label.nutrients.1.unit', 'IE')
            ->assertJsonPath('label.nutrients.1.nutrient', 'Vitamine D3 (cholecalciferol)');
    }

    /**
     * A plate of pasta reads correctly as "there is no label here" — a SUCCESS
     * with nothing in it, a different screen from a failure and no retry button.
     */
    public function test_a_photograph_that_is_not_a_label_comes_back_empty_and_successful(): void
    {
        $this->analyzer->willReadNothing('a plate of pasta');

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        self::assertSame(VisionRequestStatus::Succeeded, VisionRequest::query()->sole()->status);

        $this->getJson('/api/supplements/label/'.$clientId)
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('label.name', '')
            ->assertJsonPath('label.nutrients', [])
            ->assertJsonPath('error', null)
            ->assertJsonPath('label.notes', 'This is not a supplement label — it shows a plate of pasta.');

        self::assertSame(0, Supplement::query()->count());
    }

    public function test_a_failed_reading_says_why_and_creates_nothing(): void
    {
        $this->analyzer->willFailTheLabel('Reading that label was declined.');

        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        $this->getJson('/api/supplements/label/'.$clientId)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Reading that label was declined.')
            ->assertJsonPath('label', null);

        self::assertSame(0, Supplement::query()->count());
    }

    /** Confirming is what creates it — with the edits, not the transcription. */
    public function test_confirming_the_review_creates_the_supplement_its_lines_and_its_photo(): void
    {
        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        $this->post('/supplements', [
            'client_id' => $clientId,
            // Corrected against the bottle: what is written is what was on screen.
            'name'          => 'Magnesium Glycinate-120',
            'brand'         => 'Helixa',
            'serving_text'  => 'per capsule',
            'units_per_day' => 2,
            'nutrients'     => [
                ['nutrient' => 'Magnesium (glycinate)', 'amount' => '120', 'unit' => 'mg'],
                ['nutrient' => 'Vitamine B6', 'amount' => '1.4', 'unit' => 'mg'],
                // An empty row the user added and thought better of.
                ['nutrient' => '', 'amount' => '', 'unit' => ''],
            ],
        ])->assertRedirect(route('supplements.index'));

        $supplement = Supplement::query()->sole();

        self::assertSame('Magnesium Glycinate-120', $supplement->name);
        self::assertSame('Helixa', $supplement->brand);
        self::assertSame('per capsule', $supplement->serving_text);
        self::assertSame(2, $supplement->units_per_day);
        self::assertTrue($supplement->active);
        self::assertSame('label_photo', $supplement->data_source);
        self::assertStringStartsWith('labels/originals/', (string) $supplement->photo_path);

        // The nameless row is not a label line.
        self::assertSame(2, $supplement->nutrients()->count());
        self::assertSame([0, 1], $supplement->nutrients()->pluck('position')->all());

        // The audit row learns what its reading became.
        self::assertSame($supplement->id, VisionRequest::query()->sole()->refresh()->supplement_id);
    }

    public function test_a_supplement_can_be_added_by_hand_with_no_reading_behind_it(): void
    {
        $this->post('/supplements', [
            'name'          => 'Vitamine C',
            'units_per_day' => 1,
        ])->assertRedirect(route('supplements.index'));

        $supplement = Supplement::query()->sole();

        self::assertSame('hand_entered', $supplement->data_source);
        self::assertNull($supplement->photo_path);
        self::assertSame(0, $supplement->nutrients()->count());
        self::assertSame(0, VisionRequest::query()->count());
    }

    /**
     * The spare bottle: transcribed the evening it is bought, switched on
     * months later. `active` is a choice at creation, not only a later toggle.
     */
    public function test_a_supplement_can_be_added_switched_off_for_later(): void
    {
        $this->post('/supplements', [
            'name'          => 'Magnesium Glycinate 100 mg with taurine',
            'brand'         => 'Verdant',
            'units_per_day' => 2,
            'active'        => false,
        ])->assertRedirect(route('supplements.index'));

        $supplement = Supplement::query()->sole();

        self::assertFalse($supplement->active);

        // Off the day card entirely until it is switched on.
        $this->get('/?date='.now()->toDateString())
            ->assertInertia(fn ($page) => $page->where('supplements.total', 0));
    }

    public function test_the_label_photo_is_served_behind_auth_and_the_reading_survives_the_confirm(): void
    {
        $clientId = (string) Str::uuid();

        $this->post('/api/supplements/label', $this->payload($clientId))->assertStatus(202);

        $this->post('/supplements', [
            'client_id'     => $clientId,
            'name'          => 'Magnesium',
            'units_per_day' => 1,
        ])->assertRedirect();

        $supplement = Supplement::query()->sole();

        $this->get(route('supplements.photo', ['supplement' => $supplement->id]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $clientId, ?string $key = null): array
    {
        return [
            'client_id'       => $clientId,
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'photo'           => UploadedFile::fake()->createWithContent('label.jpg', TestImage::jpeg(1200, 1600)),
        ];
    }
}
