<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. One observation of one feed at one moment.
 *
 * A latency measurement that can be edited afterwards measures nothing, so
 * these rows are immutable like every other history table here.
 */
class LiveFeedProbe extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'side' => Side::class,
            'home_score' => 'integer',
            'away_score' => 'integer',
            'period' => 'integer',
            'clock_seconds' => 'integer',
            'price_american' => 'integer',
            'line' => 'float',
            'staleness_ms' => 'integer',
            'http_ms' => 'integer',
            'ok' => 'boolean',
            'changed' => 'boolean',
            'payload' => 'array',
            'observed_at' => 'datetime',
            'source_reported_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeForSession(Builder $query, string $session): Builder
    {
        return $query->where('session', $session);
    }

    public function scopeGameState(Builder $query): Builder
    {
        return $query->where('kind', 'game_state');
    }

    public function scopeOdds(Builder $query): Builder
    {
        return $query->where('kind', 'odds');
    }
}
