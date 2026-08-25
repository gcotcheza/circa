<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The single-user seed
    |--------------------------------------------------------------------------
    |
    | Read here rather than via env() inside SingleUserSeeder so Larastan's
    | env-outside-config check passes. The deploy checklist (README) never
    | runs config:cache and nothing else in the repo does, so these resolve
    | from the live env on every `artisan db:seed` — no behaviour change from
    | a direct env() call. That never-cache rule is also what keeps
    | SEED_USER_PASSWORD out of bootstrap/cache/config.php: a cached config
    | would bake the plaintext in and make a rotation silently reuse it.
    |
    */

    'user_email' => env('SEED_USER_EMAIL', 'user@example.com'),

    'user_name' => env('SEED_USER_NAME', 'Demo User'),

    'user_password' => env('SEED_USER_PASSWORD'),

];
