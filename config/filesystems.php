<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | The default filesystem disk used by the framework.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Configure as many disks as necessary, even multiple per driver.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
            'report' => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw'      => false,
            'report'     => false,
        ],

        /*
         * Meal photos (step 5). A disk of its own, not a folder on `local`,
         * so the retention prune can't be pointed at anything else by a
         * typo, and moving to object storage later is a config change.
         *
         * NOT public and NOT `serve`d — no URL reaches these bytes. Served,
         * if at all, through an authenticated controller
         * (`GET /api/meals/{meal}/photo`): a plate of food is personal, and
         * a guessable path under /storage would be public.
         *
         * `throw` is on: a silent write failure would leave a meal stuck in
         * `analyzing` with nothing on disk to explain why.
         */
        'meal-photos' => [
            'driver' => 'local',
            'root'   => storage_path('app/private/meal-photos'),
            'serve'  => false,
            'throw'  => true,
            'report' => false,
        ],

        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION'),
            'bucket'                  => env('AWS_BUCKET'),
            'url'                     => env('AWS_URL'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
            'report'                  => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Links created by `storage:link` — keys are link locations, values
    | their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
