<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One sync attempt. Every job writes exactly one of these, whatever
 * happens, so a feed that quietly stopped is visible rather than looking
 * like a quiet night.
 */
class SyncRun extends Model
{
    use HasFactory, MassPrunable;

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

    /**
     * Rows old enough that nothing reads them any more - except the one
     * row per feed that must never be deleted at any age.
     *
     * `cursorFor()` reads the next starting point off the last successful
     * run, deliberately, so that a failed run cannot advance a cursor
     * past rows it never read. That makes the newest success per feed
     * load-bearing in a way age does not express: prune it and the feed
     * restarts from nothing, and SyncEvents walks all thirty-five
     * thousand events again to re-derive what it already knew. The
     * digest means those pages write almost nothing, which is the bad
     * part - it would be a silent cost, paid in Edge Lab requests, with
     * every table looking exactly as it should.
     *
     * So the window prunes history and the exception keeps the cursor.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('started_at', '<', now()->subDays((int) config('edge.retention.sync_runs_days')))
            ->whereNotIn('id', static::cursorRowIds());
    }

    /**
     * The id of the row `cursorFor()` would read, for every feed.
     *
     * Asked through the same scopes the reader uses, deliberately. The
     * first version of this took `max(id)` per feed in one subquery,
     * which is the same answer in production and not the same answer in
     * general: `latestFirst()` orders by `started_at` before `id`, so a
     * run recorded out of order - a backfill, a replay, a clock that
     * stepped - puts the highest id and the latest start on different
     * rows. A test wrote those two rows and caught it.
     *
     * One indexed query per feed, and there are four.
     *
     * @return array<int, int>
     */
    private static function cursorRowIds(): array
    {
        $ids = [];

        foreach (static::query()->distinct()->pluck('feed') as $feed) {
            $id = static::query()->feed((string) $feed)->succeeded()->latestFirst()->value('id');

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
