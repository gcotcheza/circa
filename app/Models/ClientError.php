<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that went wrong inside the browser.
 *
 * Write-mostly: the reporter inserts, `/api/health` counts, a human reads via
 * raw SQL. No screen — a crash-report list rendered by the client that
 * crashed is a joke with a long setup.
 *
 * @property int $id
 * @property string $kind
 * @property string $message
 * @property string|null $source
 * @property int|null $line
 * @property int|null $col
 * @property string|null $stack
 * @property string|null $url
 * @property string|null $user_agent
 * @property string|null $build
 * @property string|null $component
 * @property string|null $context
 * @property Carbon $created_at
 */
final class ClientError extends Model
{
    protected $table = 'client_errors';

    /**
     * No `updated_at`: a report is a one-moment fact, never edited.
     * `created_at` comes from the column default; Eloquent leaves both alone.
     */
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line'       => 'integer',
            'col'        => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
