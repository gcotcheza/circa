<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Dashboard Token
    |--------------------------------------------------------------------------
    |
    | Not a stock Horizon setting — added here because this app has no user
    | accounts to gate on (step 3 brings the UI) and the default gate would
    | therefore evaluate against a null user forever.
    |
    | App\Providers\HorizonServiceProvider allows the dashboard when the app is
    | running locally, or when a request carries this value in `X-Horizon-Token`
    | (or `?token=`). EMPTY MEANS DENY: an unset secret must never be read as
    | "no secret required". In production it is left unset, and the host vhost
    | 404s /horizon anyway — the token exists for a tunnelled look at
    | 127.0.0.1:3083, not for public access.
    |
    */

    'dashboard_token' => env('HORIZON_DASHBOARD_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'ingest' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses'        => 1,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            // Retries are configured on the job (ParseRawPayload::$tries = 3),
            // not here: `tries` in this file is only the fallback for jobs that
            // do not state their own.
            'tries'   => 1,
            'timeout' => 300,
            'nice'    => 0,
        ],
    ],

    /*
    | ONE process, on purpose.
    |
    | The entire workload is "parse an hourly payload" — a few hundred upserts,
    | once an hour, from a single phone. A second worker would buy nothing and
    | would put two transactions on the same `health_metrics` rows, which is
    | correct but deadlock-prone under Postgres's row locks. ParseRawPayload is
    | ShouldBeUnique for the same reason. Raise this when there is a workload
    | that justifies it, not before.
    |
    | `timeout` is 300s to match the job: a full backfill payload is bigger than
    | an hourly one, and a worker killed mid-parse leaves a run in `processing`.
    |
    | ONE KEY PER APP_ENV THAT RUNS HORIZON — and a MISSING key is silent.
    |
    | Horizon picks its supervisor set by APP_ENV: it reads
    | `environments.<APP_ENV>` and, finding nothing, boots the master process
    | with ZERO workers. There is no error and no warning — every queued job
    | just sits in `pending` forever. So each environment that runs
    | `php artisan horizon` needs an entry here. `staging` is one of them: the
    | staging stack runs Horizon under
    | APP_ENV=staging, where the queue does real work — meal-photo vision jobs
    | and the hand-triggered health report both run on `default`. Without the
    | `staging` block below, a staged branch would accept that work and then
    | never process it, which reads as a bug in the branch and is not. Do NOT
    | drop `staging` when reconciling this file with production's shape.
    */
    'environments' => [
        'production' => [
            'ingest' => [
                'maxProcesses' => 1,
            ],
        ],

        // Mirrors production, and required for the same reason it exists there:
        // the staging stack runs one Horizon worker on the `default` queue, so
        // a staged branch processes its jobs instead of leaving them `pending`.
        // See the note above — remove this and staging runs with no workers.
        'staging' => [
            'ingest' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'ingest' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
