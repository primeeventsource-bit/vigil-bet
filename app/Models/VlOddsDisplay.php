<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY. A price for display, for one game.
 *
 * No `current` scope keyed on an Edge Lab id, because a quote has no
 * single id upstream - it is keyed on event, book, market and side. The
 * latest per selection is what the game page asks for.
 */
class VlOddsDisplay extends Model
{
    use AppendOnly, HasFactory;

    protected $table = 'vl_odds_display';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'price_american' => 'integer',
            'price_decimal' => 'float',
            'quoted_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    /** Core markets only. A player prop is a different question. */
    public function scopeCoreMarkets(Builder $query): Builder
    {
        return $query->whereNull('market_key');
    }

    public function scopeProps(Builder $query): Builder
    {
        return $query->whereNotNull('market_key');
    }

    public function isProp(): bool
    {
        return $this->market_key !== null;
    }
}
