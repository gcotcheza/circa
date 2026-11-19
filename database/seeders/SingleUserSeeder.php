<?php

declare(strict_types=1);

namespace Database\Seeders;

use LogicException;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The one account this app will ever have.
 *
 *   docker compose exec app php artisan db:seed --class=SingleUserSeeder
 *
 * Password is GENERATED and printed once — not in this file, not in
 * `.env.example`, not recoverable after (bcrypt hash only). Losing it
 * means rerunning with `SEED_USER_PASSWORD` set: a deliberate rotation,
 * not a recovery flow. No password-reset route exists — SPEC.md rules
 * out multi-user, and a reset flow for one account buys an
 * email-dependent attack surface for nothing.
 *
 * Re-running is safe: an existing user keeps their password unless
 * SEED_USER_PASSWORD is explicitly supplied, so this can stay in a
 * deploy script without locking the owner out on every release.
 */
final class SingleUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // The generated password is printed once and stored nowhere: no console, no write.
        $command = $this->command
            ?? throw new LogicException('SingleUserSeeder must run under artisan (db:seed): it prints the generated password.');

        $email = (string) config('seeding.user_email');
        $name = (string) config('seeding.user_name');
        $supplied = config('seeding.user_password');

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $supplied === null) {
            $command->info("User {$email} already exists; password left unchanged.");

            return;
        }

        // Str::password() is cryptographically random, mixes all four
        // character classes; 24 chars is well past a throttled-login attack.
        $password = is_string($supplied) && $supplied !== ''
            ? $supplied
            : Str::password(24);

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => $password, // hashed by the model's cast
                'email_verified_at' => now(),
            ]
        );

        $command->newLine();
        $command->info(sprintf('User %s <%s> is ready.', $name, $email));

        if (! is_string($supplied)) {
            $command->warn('Generated password (shown once, stored nowhere):');
            $command->line('  '.$password);
        }

        $command->newLine();
    }
}
