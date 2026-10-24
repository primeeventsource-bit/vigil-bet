<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'status' => EventStatus::class,
            'season_type' => SeasonType::class,
            'season' => 'integer',
            'week' => 'integer',
            'neutral_site' => 'boolean',
            'went_overtime' => 'boolean',
            'divisional' => 'boolean',
            'home_rest_days' => 'integer',
            'away_rest_days' => 'integer',
            'starts_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_result_sync_at' => 'datetime',
            'final_home_score' => 'integer',
            'final_away_score' => 'integer',
            'period_scores' => 'array',
        ];
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Every reading taken for this game, forecast and observed. Append-only,
     * so a game played in wind has a record of what was forecast as well as
     * what arrived.
     */
    public function weatherReadings(): HasMany
    {
        return $this->hasMany(WeatherReading::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(EventExternalId::class);
    }

    public function oddsSnapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class);
    }

    public function lineMarkers(): HasMany
    {
        return $this->hasMany(EventLineMarker::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function teamGameStats(): HasMany
    {
        return $this->hasMany(TeamGameStat::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>', now())->where('status', EventStatus::Scheduled);
    }

    public function scopeForSport(Builder $query, Sport|string $sport): Builder
    {
        return $query->where('sport', $sport instanceof Sport ? $sport->value : $sport);
    }

    public function matchup(): string
    {
        return ($this->awayTeam?->display_name ?? 'TBD').' at '.($this->homeTeam?->display_name ?? 'TBD');
    }

    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    public function margin(): ?int
    {
        if ($this->final_home_score === null || $this->final_away_score === null) {
            return null;
        }

        return $this->final_home_score - $this->final_away_score;
    }

    public function totalPoints(): ?int
    {
        if ($this->final_home_score === null || $this->final_away_score === null) {
            return null;
        }

        return $this->final_home_score + $this->final_away_score;
    }
}
