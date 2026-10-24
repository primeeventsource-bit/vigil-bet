<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'active' => 'boolean',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'primary_venue_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TeamAlias::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(TeamExternalId::class);
    }

    public function homeEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'home_team_id');
    }

    public function awayEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'away_team_id');
    }

    public function scopeForSport(Builder $query, Sport|string $sport): Builder
    {
        return $query->where('sport', $sport instanceof Sport ? $sport->value : $sport);
    }
}
