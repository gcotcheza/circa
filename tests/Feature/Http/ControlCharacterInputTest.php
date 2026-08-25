<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Contracts\Http\Kernel;
use App\Http\Middleware\StripControlCharacters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;

/**
 * A control character in a box is junk, and junk must not reach the row.
 *
 * The one doing damage is a NUL in the MIDDLE of a value, where TrimStrings —
 * an end-trimmer — never looked: "Chicken\x00 curry" was stored as "Chicken",
 * the rest of the name gone, with no error raised anywhere. The form feed is
 * the other half of the story: `is_numeric("\x0C5")` is true and PHP's `trim()`
 * leaves it in place, so the validator throws instead of answering — kept off
 * that path today only by Laravel's own trim happening to remove it first,
 * which is the argument in StripControlCharacters.
 *
 * These are HTTP tests on purpose: the fix is a middleware, and what has to
 * hold is what a REQUEST does, in the stack's real order.
 */
final class ControlCharacterInputTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    public function test_a_form_feed_in_front_of_a_number_saves_the_number(): void
    {
        // Green before this middleware too — Laravel's trim reaches a LEADING
        // form feed first. Pinned anyway: it is the value the crash is about,
        // and nothing should quietly start refusing or storing it differently.
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => "\x0C120"],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        // Quick mode stores 100 g at 120 kcal/100 g, so the figure survived the
        // stripping as the number the user typed.
        self::assertSame(120.0, (float) MealItem::query()->sole()->kcal_per_100g_min);
    }

    public function test_the_same_value_sent_as_json_is_answered_too(): void
    {
        // The shape the offline queue replays a save in: fetch() and a JSON
        // body, which the middleware cleans out of a different bag than a form
        // post. The stripped value is valid, so this stores 120 like any other.
        $this->postJson('/meals', $this->payload([
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => "\x0C120"],
        ]))->assertRedirect();

        self::assertSame(120.0, (float) MealItem::query()->sole()->kcal_per_100g_min);
    }

    public function test_a_control_character_inside_a_name_is_dropped_and_the_name_stands(): void
    {
        $this->post('/meals', $this->payload([
            ['name' => "Chicken\x00 curry", 'basis' => 'absolute'],
        ]))->assertSessionHasNoErrors();

        self::assertSame('Chicken curry', MealItem::query()->sole()->name);
    }

    public function test_a_box_holding_only_a_control_character_is_an_empty_box(): void
    {
        // The three middlewares in order: stripped to '', trimmed, then made
        // null — so this is refused exactly as a blank name box is, rather than
        // saved as an item called "\x0C".
        $this->from('/')->post('/meals', $this->payload([
            ['name' => "\x0C", 'basis' => 'absolute'],
        ]))->assertSessionHasErrors('items.0.name');

        self::assertSame(0, Meal::query()->count());

        // And the same emptiness on an optional number is not an error at all.
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => "\x0C"],
        ]))->assertSessionHasNoErrors();

        self::assertSame(0.0, (float) MealItem::query()->sole()->kcal_per_100g_min);
    }

    public function test_a_ceiling_still_refuses_in_words(): void
    {
        // The stripped value is still weighed, not waved through — and `max` on
        // a number is the rule the form-feed crash lives on.
        $this->from('/')->post('/meals', $this->payload([
            ['name' => 'Impossible', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => "\x0C5000"],
        ]))->assertSessionHasErrors('items.0.kcal_per_100g');
    }

    public function test_the_stripping_runs_before_the_trimming_and_the_null_conversion(): void
    {
        $kernel = app(Kernel::class);

        self::assertInstanceOf(HttpKernel::class, $kernel);

        $global = array_values(array_filter($kernel->getGlobalMiddleware(), 'is_string'));

        $at = function (string $class) use ($global): int {
            $index = array_search($class, $global, true);

            self::assertIsInt($index, $class.' is not in the global middleware stack.');

            return $index;
        };

        self::assertLessThan(
            $at(TrimStrings::class),
            $at(StripControlCharacters::class),
            'trimming a value still holding a form feed leaves the form feed.'
        );

        self::assertLessThan(
            $at(ConvertEmptyStringsToNull::class),
            $at(StripControlCharacters::class),
            'a box stripped to nothing must reach the rules as null, like any other empty box.'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'uuid'  => (string) Str::uuid(),
            'date'  => self::DATE,
            'time'  => '12:30',
            'items' => $items,
        ];
    }
}
