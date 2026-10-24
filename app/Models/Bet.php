<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read model. The truth is bet_events; this row is projected from it and
 * must only be written by the projector.
 */
class Bet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'side' => Side::class,
            'status' => BetStatus::class,
            'is_custom' => 'boolean',
            'is_paper' => 'boolean',
            'line' => 'float',
            'price_american' => 'integer',
            'price_decimal' => 'float',
            'stake_cents' => 'integer',
            'payout_cents' => 'integer',
            'pnl_cents' => 'integer',
            'clv_cents' => 'integer',
            'closing_price_american' => 'integer',
            'closing_line' => 'float',
            'clv_prob_delta' => 'float',
            'model_prob_at_placement' => 'float',
            'fair_prob_at_placement' => 'float',
            'edge_at_placement' => 'float',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
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

    public function valueBet(): BelongsTo
    {
        return $this->belongsTo(ValueBet::class);
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BetEvent::class)->orderBy('sequence');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', BetStatus::Pending);
    }

    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_paper', false);
    }

    /** Did the line move between the bet and the close. */
    public function clvLineMoved(): bool
    {
        return $this->line !== null
            && $this->closing_line !== null
            && abs($this->closing_line - $this->line) >= 0.001;
    }

    /**
     * Why this bet has no closing line value, for the screens that would
     * otherwise show a blank cell. Null when it has one.
     *
     * A missing CLV is never a bug to hide: it says the close could not be
     * read honestly, and which of the reasons it was matters when deciding
     * whether the pull cadence or the book coverage needs attention.
     */
    public function clvUnavailableReason(): ?string
    {
        if ($this->clv_prob_delta !== null) {
            return null;
        }

        if ($this->closing_price_american === null) {
            return 'The market has not closed yet, or this book never quoted it before kickoff.';
        }

        if ($this->clvLineMoved()) {
            return 'The line moved from '.$this->line.' to '.$this->closing_line
                .'. Valuing that move takes a scoring model, so the price gap alone is not reported as value.';
        }

        return 'Only one side of this market closed, so the vig cannot be removed.';
    }

    public function toWinCents(): int
    {
        return (int) round($this->stake_cents * ($this->price_decimal - 1));
    }
}
