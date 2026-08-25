<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use App\Http\Middleware\StripControlCharacters;

/**
 * What the middleware takes out, and everything it must leave alone.
 *
 * The removals are the easy half. The half worth pinning is the rest: a
 * textarea's line breaks, the numbers and booleans a form posts alongside its
 * strings, the shape of a nested `items` list, an upload, and a password —
 * where silently editing the value would change what it unlocks.
 */
final class StripControlCharactersTest extends TestCase
{
    public function test_every_c0_control_character_except_the_three_that_mean_something_is_removed(): void
    {
        $stripped = $this->clean(['note' => "a\x00b\x01c\x08d\x0Be\x0Cf\x0Eg\x1Fh"]);

        self::assertSame('abcdefgh', $stripped['note']);
    }

    public function test_tab_newline_and_carriage_return_survive(): void
    {
        $note = "two lines\r\nand\ta column";

        self::assertSame($note, $this->clean(['note' => $note])['note']);
    }

    public function test_a_form_feed_in_front_of_a_digit_becomes_the_digit(): void
    {
        // The whole point: `is_numeric("\x0C5")` is true and PHP's trim() does
        // not remove the form feed, so this string reaches BigNumber and throws.
        self::assertSame('5', $this->clean(['kcal' => "\x0C5"])['kcal']);
    }

    public function test_a_box_holding_nothing_else_is_left_empty_for_the_middleware_behind_this_one(): void
    {
        // Not null here: turning '' into null is ConvertEmptyStringsToNull's
        // job, further down the global stack. See ControlCharacterInputTest.
        self::assertSame('', $this->clean(['name' => "\x0C"])['name']);
    }

    public function test_nested_rows_are_reached(): void
    {
        $cleaned = $this->clean(['items' => [
            ['name' => "Rice\x00", 'kcal' => "\x0C120"],
            ['name' => 'Chicken', 'kcal' => '240'],
        ]]);

        self::assertSame(
            [['name' => 'Rice', 'kcal' => '120'], ['name' => 'Chicken', 'kcal' => '240']],
            $cleaned['items'],
        );
    }

    public function test_what_is_not_a_string_is_returned_as_it_was(): void
    {
        $values = ['count' => 7, 'weight' => 82.4, 'flag' => true, 'nothing' => null, 'empty' => []];

        self::assertSame($values, $this->clean($values));
    }

    public function test_a_secret_is_whatever_was_typed(): void
    {
        $secret = "keep\x0Cthis";

        $cleaned = $this->clean([
            'password'              => $secret,
            'password_confirmation' => $secret,
            'current_password'      => $secret,
        ]);

        self::assertSame([$secret, $secret, $secret], array_values($cleaned));
    }

    public function test_the_query_string_is_cleaned_too(): void
    {
        $request = Request::create('/meals?q='.rawurlencode("cur\x00ry"), 'GET');

        self::assertSame('curry', $this->pass($request)->query('q'));
    }

    public function test_a_json_body_is_cleaned_as_well(): void
    {
        // The offline queue replays saves with fetch(), so this is the shape a
        // meal arrives in after a tunnel — a different bag from a form post.
        $body = (string) json_encode(['items' => [['name' => "Rice\x00", 'kcal' => "\x0C120"]]]);

        $request = Request::create('/meals', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        self::assertSame(
            [['name' => 'Rice', 'kcal' => '120']],
            $this->pass($request)->json('items'),
        );
    }

    public function test_an_upload_is_not_touched(): void
    {
        $file = UploadedFile::fake()->create('plate.jpg', 8);

        $request = Request::create('/meals/photo', 'POST', ['hint' => "a\x0Cb"], [], ['photo' => $file]);

        $passed = $this->pass($request);

        self::assertSame($file, $passed->file('photo'));
        self::assertSame('ab', $passed->input('hint'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function clean(array $input): array
    {
        return $this->pass(Request::create('/meals', 'POST', $input))->all();
    }

    private function pass(Request $request): Request
    {
        $passed = null;

        (new StripControlCharacters)->handle($request, static function (Request $received) use (&$passed): Response {
            $passed = $received;

            return new Response;
        });

        self::assertInstanceOf(Request::class, $passed);

        return $passed;
    }
}
