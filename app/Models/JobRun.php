<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every job writes exactly one row here, success or failure. The Jobs screen
 * reads this table, so a silent failure is impossible by construction.
 */
class JobRun extends Model
{
    use HasFactory, MassPrunable;

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

    /**
     * Rows old enough that nothing reads them any more.
     *
     * This is the table that grew: 3,700 rows a day, nothing deleting
     * them, and every reader only ever wanting the newest row for one
     * job key - PullCadence asks when a sport last pulled successfully,
     * ResultsCadence asks when it last ran at all, the doctor asks what
     * failed in the past two days.
     *
     * No exception for the newest row per key, unlike SyncRun. The worst
     * a missing one can do here is leave a cadence with no last-run time
     * and so count a job as due, which costs one early pull and writes a
     * fresh row - it heals itself on the next tick. SyncRun's newest row
     * carries a cursor, which does not.
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'started_at',
            '<',
            now()->subDays((int) config('edge.retention.job_runs_days')),
        );
    }
}
