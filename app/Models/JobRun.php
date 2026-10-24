<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Every job writes exactly one row here, success or failure. The Jobs screen
 * reads this table, so a silent failure is impossible by construction.
 */
class JobRun extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'sport' => Sport::class,
            'triggered_by' => TriggerSource::class,
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'attempt' => 'integer',
            'records_in' => 'integer',
            'records_out' => 'integer',
        ];
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', JobStatus::Failed);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at');
    }
}
