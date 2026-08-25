<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\Supplement;
use Illuminate\Http\Request;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Services\Vision\PhotoStore;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\SupplementRequest;
use App\Services\Supplements\SupplementWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shelf: what you take, and the small screen for changing it.
 *
 * Reached from the day view's supplements card, not the bottom nav —
 * deliberate, since this is a settings surface visited a handful of times
 * a year, and a permanent nav item would cost a tap on every screen
 * forever to save one on the rare occasion somebody buys a bottle.
 * Ordinary Inertia forms throughout; only the LABEL READING is JSON (see
 * SupplementLabelController for why).
 */
final class SupplementController extends Controller
{
    public function __construct(
        private readonly SupplementWriter $writer,
        private readonly PhotoStore $photos,
    ) {}

    public function index(): Response
    {
        $supplements = Supplement::query()
            ->with('nutrients')
            ->orderBy('active', 'desc')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return Inertia::render('Supplements', [
            'supplements' => $supplements->map(fn (Supplement $supplement): array => [
                'id'          => $supplement->id,
                'name'        => $supplement->name,
                'brand'       => $supplement->brand,
                'servingText' => $supplement->serving_text,
                'unitsPerDay' => $supplement->units_per_day,
                'active'      => $supplement->active,
                /*
                 * Provenance, shown rather than hidden: five rows came from
                 * a seeder reading the manufacturers' own pages, and the
                 * user is entitled to know which they've never checked —
                 * with a link to the source page, so checking one is a
                 * tap, not a search.
                 */
                'dataSource' => $supplement->data_source,
                'sourceUrl'  => $supplement->source_url,
                'notes'      => $supplement->notes,
                'photoUrl'   => $supplement->photo_path === null
                    ? null
                    : route('supplements.photo', ['supplement' => $supplement->id]),
                /*
                 * Both numbers, every line: the printed figure lets the row
                 * be checked against the bottle, the daily total is what
                 * the user actually wanted — neither stored twice, see
                 * Supplement::dailyNutrients.
                 */
                'nutrients' => $supplement->dailyNutrients(),
            ])->values()->all(),
        ]);
    }

    /**
     * Confirm a reviewed label — or add one by hand.
     *
     * `client_id` links it back to the reading it came from: the
     * photograph becomes the supplement's, and the audit row learns what
     * it became. Absent on a hand-typed supplement — a supported path, not
     * second-class.
     */
    public function store(SupplementRequest $request): RedirectResponse
    {
        $reading = $this->reading($request->string('client_id')->value());

        $supplement = $this->writer->create(
            name: (string) $request->string('name')->trim()->value(),
            brand: $request->optionalText('brand'),
            servingText: $request->optionalText('serving_text'),
            unitsPerDay: (int) $request->integer('units_per_day', 1),
            photoPath: $this->photoPathOf($reading),
            nutrients: $request->nutrients(),
            // A create is active unless the user said otherwise — "bought it,
            // starting it when the current bottle runs out".
            active: $request->boolean('active', true),
            from: $reading,
        );

        return to_route('supplements.index')->with(
            'success',
            $supplement->active
                ? $supplement->name.' added.'
                : $supplement->name.' added, ready for when you start it.'
        );
    }

    /**
     * Rename it, re-dose it, retype a line, or switch it off.
     *
     * The nutrient rows are replaced whole rather than diffed — see
     * SupplementWriter for why a printed panel has no row identity to diff on.
     */
    public function update(SupplementRequest $request, Supplement $supplement): RedirectResponse
    {
        $this->writer->update(
            supplement: $supplement,
            name: (string) $request->string('name')->trim()->value(),
            brand: $request->optionalText('brand'),
            servingText: $request->optionalText('serving_text'),
            unitsPerDay: (int) $request->integer('units_per_day', $supplement->units_per_day),
            // Absent means "leave it as is": the edit form and the on/off
            // toggle post the same route, so a forgotten field must not
            // silently reactivate it.
            active: $request->boolean('active', $supplement->active),
            nutrients: $request->nutrients(),
            notes: $request->has('notes') ? $request->optionalText('notes') : $supplement->notes,
        );

        return to_route('supplements.index')->with('success', 'Saved.');
    }

    /**
     * Throw it away for good, with its history.
     *
     * The destructive one — "stopped taking it" is the `active` toggle the
     * card offers; this is for a bottle added by mistake, cascading the
     * intakes away since it never should have had evenings worth keeping.
     */
    public function destroy(Supplement $supplement): RedirectResponse
    {
        $name = $supplement->name;

        $this->writer->delete($supplement);

        return to_route('supplements.index')->with('success', $name.' deleted.');
    }

    /**
     * The label photograph, at 256 px.
     *
     * Streamed from the private disk behind session auth — no URL under
     * /storage reaches these bytes, same as a meal plate. Cached a year
     * and `immutable`, safely, for the same reason as the plate thumbnail:
     * written once by PhotoStore::storeLabel under a path derived from the
     * capture's client id, so nothing rewrites it, and deleting the
     * supplement 404s the URL rather than serving something else.
     */
    public function photo(Request $request, Supplement $supplement): StreamedResponse
    {
        abort_if($supplement->photo_path === null, 404);

        $thumb = $this->photos->labelThumbPathFor($supplement->photo_path);

        $path = $this->photos->disk()->exists($thumb) ? $thumb : $supplement->photo_path;

        abort_if(! $this->photos->disk()->exists($path), 404);

        $response = $this->photos->disk()->response($path, headers: [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);

        // The path is the ETag, for the reason MealPhotoController gives:
        // every file here is content-addressed, so "same path" means "same
        // bytes".
        $response->setEtag(sha1($path));
        $response->isNotModified($request);

        return $response;
    }

    /** The label reading this confirm came from, if it came from one. */
    private function reading(string $clientId): ?VisionRequest
    {
        if ($clientId === '') {
            return null;
        }

        return VisionRequest::query()
            ->where('request_kind', VisionRequestKind::Label)
            ->forClientId($clientId)
            ->orderByDesc('id')
            ->first();
    }

    private function photoPathOf(?VisionRequest $reading): ?string
    {
        $payload = $reading?->input_payload;

        if (! is_array($payload) || ! is_string($payload['photo_path'] ?? null)) {
            return null;
        }

        return $payload['photo_path'];
    }
}
