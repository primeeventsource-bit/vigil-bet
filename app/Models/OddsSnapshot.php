<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Side;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. One row per quote per pull. Never updated.
 *
 * Two kinds of row live here, and exactly one of two columns is set on each:
 *
 *   core market - `market` is a Market, `market_key` is null. These are the
 *                 three markets the model is trained and judged on.
 *   player prop - `market_key` is The Odds API key, `market` is null. These
 *                 are display and line shopping only; no model has been
 *                 measured on one, so none of them may ever become a pick.
 *
 * The split is the enforcement. Every reader that feeds a prediction, a
 * verdict or a stake asks for a core market by name, so a prop cannot reach
 * one by accident - and the two readers that want every quote for a game
 * say `coreMarkets()` out loud.
 */
class OddsSnapshot extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'side' => Side::class,
            'line' => 'float',
            'price_american' => 'integer',
            'price_decimal' => 'float',
            'pulled_at' => 'datetime',
            'api_last_update_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scopeForMarket(Builder $query, Market|string $market): Builder
    {
        return $query->where('market', $market instanceof Market ? $market->value : $market);
    }

    /**
     * The three markets the model is allowed to see.
     *
     * For readers that want every quote on a game rather than one market.
     * Without it they would pick up player props, which are not quotes on
     * the game's outcome and have no `market` to group by.
     */
    public function scopeCoreMarkets(Builder $query): Builder
    {
        return $query->whereNotNull('market');
    }

    /** Player props only, optionally one market key. */
    public function scopeProps(Builder $query, ?string $marketKey = null): Builder
    {
        return $query
            ->whereNotNull('market_key')
            ->when($marketKey !== null, fn (Builder $q) => $q->where('market_key', $marketKey));
    }

    public function scopeAsOf(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('pulled_at', '<=', $moment);
    }

    public function isProp(): bool
    {
        return $this->market_key !== null;
    }

    /**
     * What this row is a quote on, as The Odds API names it. The two
     * columns never disagree because only one of them is ever set.
     */
    public function marketKey(): ?string
    {
        return $this->market_key ?? $this->market?->value;
    }
}
