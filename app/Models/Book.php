<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_tracked' => 'boolean',
            'is_bettable' => 'boolean',
            'is_primary' => 'boolean',
            'is_exchange' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function oddsSnapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class);
    }

    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('is_tracked', true);
    }

    /** Books I hold an account with. Only these can produce a pick. */
    public function scopeBettable(Builder $query): Builder
    {
        return $query->where('is_bettable', true);
    }

    public static function primary(): ?self
    {
        return static::query()->bettable()->orderByDesc('is_primary')->orderBy('display_order')->first();
    }

    /** @return array<int, int> ids of the books I can actually bet at */
    public static function bettableIds(): array
    {
        return static::query()->bettable()->pluck('id')->all();
    }
}
