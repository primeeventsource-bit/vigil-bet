<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. Forecast before the game, observation after.
 */
class WeatherReading extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_forecast' => 'boolean',
            'valid_at' => 'datetime',
            'fetched_at' => 'datetime',
            'temp_f' => 'float',
            'wind_mph' => 'float',
            'wind_deg' => 'integer',
            'gust_mph' => 'float',
            'precip_prob' => 'float',
            'precip_in' => 'float',
            'humidity' => 'float',
            'pressure_mb' => 'float',
            'lead_days' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * How many days before kickoff this forecast was made.
     *
     * Stored on every row written since the column existed. Derived from
     * the timestamps for the handful written before it, because the table
     * is append-only and a migration that amended them would be refused by
     * the database - rightly.
     */
    public function leadDays(): ?int
    {
        if ($this->lead_days !== null) {
            return $this->lead_days;
        }

        if ($this->valid_at === null || $this->fetched_at === null) {
            return null;
        }

        $days = (int) floor($this->fetched_at->diffInDays($this->valid_at, absolute: false));

        // Fetched after the game had started, so it is not a forecast of
        // anything. Null rather than clamped to zero: clamping would make a
        // reading taken afterwards look like one taken at kickoff, which is
        // the exact confusion lead_days exists to prevent.
        return $days < 0 ? null : $days;
    }

    /**
     * Readings a model may use for a game, at a fixed lead time.
     *
     * The fixed lead is the whole point. Training on whatever forecast
     * happened to be newest and then predicting from a six-day-out guess
     * compares two different things and calls the difference skill. Asking
     * for one lead makes the training set and the live board the same
     * measurement.
     *
     * `>=` rather than `=` so a game whose exact run is missing falls back
     * to an earlier, less informed one, never a later and better one.
     */
    public function scopeAtLeadDays(Builder $query, int $leadDays): Builder
    {
        return $query
            ->where('is_forecast', true)
            ->whereNotNull('lead_days')
            ->where('lead_days', '>=', $leadDays)
            ->orderBy('lead_days');
    }
}
