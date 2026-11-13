<?php

declare(strict_types=1);

namespace Tests\Feature\Seeding;

use LogicException;
use Tests\TestCase;
use App\Models\User;
use Database\Seeders\SingleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** SingleUserSeeder prints the password it generates, so it refuses to run where nothing can show it. */
final class SingleUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seeding.user_email' => 'owner@example.test', 'seeding.user_name' => 'Owner', 'seeding.user_password' => null]);
    }

    public function test_under_artisan_it_creates_the_one_user(): void
    {
        $this->seed(SingleUserSeeder::class);

        self::assertSame(1, User::query()->where('email', 'owner@example.test')->count());
    }

    public function test_without_a_console_it_refuses_and_writes_nothing(): void
    {
        try {
            $this->app->make(SingleUserSeeder::class)->run();
            self::fail('SingleUserSeeder ran without a console.');
        } catch (LogicException $e) {
            self::assertStringContainsString('must run under artisan', $e->getMessage());
        }

        self::assertSame(0, User::query()->count());
    }
}
