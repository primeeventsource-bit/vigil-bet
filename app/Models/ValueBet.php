<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Sport;
use App\Enums\Verdict;
use App\Enums\VigMethod;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * APPEND ONLY. One evaluated candidate: either a ranked pick or an explicit
 * pass with a reason. Nothing evaluated is ever hidden.
 */
class ValueBet extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'side' => Side::class,
            'recommendation' => Recommendation::class,
            'pass_reason' => PassReason::class,
            'market_verdict' => Verdict::class,
            'fair_prob_method' => VigMethod::class,
            'line' => 'float',
            'model_prob' => 'float',
            'fair_prob' => 'float',
            'edge' => 'float',
            'edge_at_price' => 'float',
            'break_even_win_rate' => 'float',
            'better_elsewhere' => 'boolean',
            'ev_per_dollar' => 'float',
            'kelly_fraction' => 'float',
            'threshold_used' => 'float',
            'suggested_stake_units' => 'float',
            'suggested_stake_cents' => 'integer',
            'bankroll_cents' => 'integer',
            'best_price_american' => 'integer',
            'break_even_price_american' => 'integer',
            'min_acceptable_price_american' => 'integer',
            'book_count' => 'integer',
            'probability_rank' => 'integer',
            'value_rank' => 'integer',
            'line_shopped' => 'array',
            'reason_factors' => 'array',
            'price_pulled_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(ValueScan::class, 'value_scan_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The shadow row, if this is one the gate declined and the
     * experiment kept. Nothing a customer can reach reads it.
     */
    public function shadowPick(): HasOne
    {
        return $this->hasOne(ShadowPick::class);
    }

    public function bestBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'best_book_id');
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function scopePicks(Builder $query): Builder
    {
        return $query->where('recommendation', Recommendation::Bet)->orderBy('probability_rank');
    }

    public function scopePasses(Builder $query): Builder
    {
        return $query->where('recommendation', Recommendation::Pass);
    }

    public function isPick(): bool
    {
        return $this->recommendation === Recommendation::Bet;
    }

    /** Human label for the side, for example Bills +2.5 or Over 8.5. */
    public function selectionLabel(): string
    {
        $team = match ($this->side) {
            Side::Home => $this->event?->homeTeam?->display_name,
            Side::Away => $this->event?->awayTeam?->display_name,
            default => null,
        };

        return match ($this->market) {
            Market::Moneyline => (string) $team,
            Market::Spread => trim($team.' '.self::signed($this->line)),
            Market::Total => ucfirst($this->side->value).' '.rtrim(rtrim(number_format((float) $this->line, 1), '0'), '.'),
        };
    }

    public static function signed(?float $line): string
    {
        if ($line === null) {
            return '';
        }

        $formatted = rtrim(rtrim(number_format(abs($line), 1), '0'), '.');

        return ($line >= 0 ? '+' : '-').$formatted;
    }
}
