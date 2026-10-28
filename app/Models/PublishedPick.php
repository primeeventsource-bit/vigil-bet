<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * APPEND ONLY, and never written after kickoff.
 *
 * One pick Vigil published, at the price that was available, before the
 * game started. The public record is the sum of these rows and nothing
 * else - no backtests, no paper results, no "what the model would have
 * said". §7 forbids mixing those into one number and the way to
 * guarantee it is for this table to contain only published picks.
 */
class PublishedPick extends Model
{
    use AppendOnly, HasFactory, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Whether anything was ever published, whatever the gate says now.
     *
     * Separate from VlModel::anyMarketProven() on purpose, because the
     * two answer different questions and the record page needs both.
     * "May this engine publish today" is a judgement about the models;
     * "did this engine publish, and how did it go" is a fact about the
     * past, and a verdict changing later does not reach back and unmake
     * the games.
     *
     * Eight picks had been published and graded while the gate still
     * admitted calibrated markets. Tightening it to proven alone was
     * right - but it also took the page that showed them, so a real
     * record with real results became a 404 and the only honest thing
     * on the site about those picks was nothing at all.
     *
     * `once` for the same reason anyMarketProven uses it: the framework
     * flushes it between requests and between tests, where a static of
     * our own would carry one test's answer into the next.
     */
    public static function anyPublished(): bool
    {
        return once(fn (): bool => self::query()->exists());
    }

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'model_prob' => 'float',
            'fair_prob' => 'float',
            'edge_at_price' => 'float',
            'stake_fraction' => 'float',
            'payload' => 'array',
            'kickoff_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function result(): HasOne
    {
        return $this->hasOne(PickResult::class);
    }

    public function scopeSettled(Builder $query): Builder
    {
        // Void excluded: a game that never ran is not a result, and
        // counting it would dilute the record with nothing.
        return $query->whereHas('result', fn ($q) => $q->whereIn('status', ['won', 'lost', 'push']));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereDoesntHave('result');
    }

    /** The permanent URL §7 asks for. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function priceWritten(): string
    {
        return ($this->price_american > 0 ? '+' : '').$this->price_american;
    }

    /** Profit per unit if this wins, excluding the stake. */
    public function unitsIfWon(): float
    {
        return $this->price_american > 0
            ? $this->price_american / 100
            : 100 / abs($this->price_american);
    }
}
