<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\MirrorsEdgeLab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** APPEND ONLY. One observed state of a game. */
class VlEvent extends Model
{
    use AppendOnly, HasFactory, MirrorsEdgeLab;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'completed_at' => 'datetime',
            'observed_at' => 'datetime',
            'final_home_score' => 'integer',
            'final_away_score' => 'integer',
            'payload' => 'array',
        ];
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>', now())->orderBy('starts_at');
    }

    public function matchup(): string
    {
        return ($this->away_name ?? 'TBD').' at '.($this->home_name ?? 'TBD');
    }
}
