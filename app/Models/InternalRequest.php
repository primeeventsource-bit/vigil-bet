<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY. One row per call to the internal API, accepted or rejected.
 *
 * The subsystem this logged is retired. The engine and the product were
 * two deployments and this recorded every request between them; they are
 * one application now and nothing writes this table any more.
 *
 * The model stays for two reasons. The rows are real history on the
 * database that is still in use, and the table is append-only in Postgres
 * - so without a model declaring it, the hardening sweep would find a
 * trigger guarding a table that nothing in PHP admits exists, which is
 * exactly the mismatch that sweep is there to catch.
 */
class InternalRequest extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'records_returned' => 'integer',
            'authenticated' => 'boolean',
            'requested_at' => 'datetime',
        ];
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->whereNotNull('rejected_reason');
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('requested_at', '>=', now()->subHours($hours));
    }
}
