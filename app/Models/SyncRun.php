<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One sync attempt. Every job writes exactly one of these, whatever
 * happens, so a feed that quietly stopped is visible rather than looking
 * like a quiet night.
 */
class SyncRun extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'records_fetched' => 'integer',
            'records_written' => 'integer',
            'cursor_before' => 'integer',
            'cursor_after' => 'integer',
            'duration_ms' => 'integer',
            'pages' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function scopeFeed(Builder $query, string $feed): Builder
    {
        return $query->where('feed', $feed);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /** Failures worth looking at now, rather than the whole history. */
    public function scopeRecent24h(Builder $query): Builder
    {
        return $query->where('started_at', '>=', now()->subDay());
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * Where the next sync of this feed should start.
     *
     * Read off the last successful run rather than kept in a settings
     * row, so a failed run cannot advance the cursor past rows it never
     * actually read.
     */
    public static function cursorFor(string $feed): ?int
    {
        $cursor = static::query()->feed($feed)->succeeded()->latestFirst()->value('cursor_after');

        return $cursor === null ? null : (int) $cursor;
    }

    public static function lastSuccessFor(string $feed): ?self
    {
        return static::query()->feed($feed)->succeeded()->latestFirst()->first();
    }
}
