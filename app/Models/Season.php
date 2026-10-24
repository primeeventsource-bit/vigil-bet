<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonType;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'season_type' => SeasonType::class,
            'year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weeks' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
