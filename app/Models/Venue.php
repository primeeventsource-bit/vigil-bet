<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'is_dome' => 'boolean',
            'altitude_m' => 'integer',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'primary_venue_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lon !== null;
    }
}
