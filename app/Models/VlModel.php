<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Verdict;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\MirrorsEdgeLab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY. A model version and the verdict it was given.
 *
 * This table decides what Vigil is allowed to sell. `proven` is the only
 * verdict that permits a pick; everything else is analysis labelled "not
 * yet proven", which is a feature of the product and not a failure of it.
 */
class VlModel extends Model
{
    use AppendOnly, HasFactory, MirrorsEdgeLab;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'allows_picks' => 'boolean',
            'predictions' => 'integer',
            'log_loss_improvement' => 'float',
            'gate' => 'array',
            'payload' => 'array',
            'trained_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * Whether any market at all has passed the gate.
     *
     * The question the public pages ask before rendering anything the
     * engine produced. Answered from the verdict column rather than from
     * the stored `allows_picks` flag beside it: that flag is written at
     * sync time from whatever the gate said then, the mirror only writes
     * a new row when the upstream payload changes, and a derived column
     * is not part of that payload - so tightening the gate would never
     * reach the stored flag and the pages would go on believing the old
     * answer for ever.
     *
     * Memoised per request. Several components ask it while rendering
     * one page and the answer cannot change mid-response.
     */
    public static function anyMarketProven(): bool
    {
        // `once` rather than a static of our own: the framework flushes
        // it between requests and between tests, where a static would
        // carry one test's answer into the next.
        return once(fn (): bool => self::query()->current()->proven()->exists());
    }

    public function scopeProven(Builder $query): Builder
    {
        return $query->whereIn('verdict', VlBoardEntry::recommendableVerdicts());
    }

    /**
     * Whether this market may produce a pick.
     *
     * Reads the stored verdict rather than re-deriving it from the
     * metrics beside it. Edge Lab owns that judgement; a second opinion
     * computed here is how the two systems start disagreeing.
     */
    public function allowsPicks(): bool
    {
        return Verdict::tryFrom((string) $this->verdict)?->allowsRecommendation() ?? false;
    }

    /** Plain words for a customer, from the stored verdict only. */
    public function verdictLabel(): string
    {
        return match ($this->verdict) {
            'proven' => 'Proven',
            'unproven' => 'Not yet proven',
            'negative' => 'Loses to the market',
            'insufficient_data' => 'Not enough data yet',
            default => 'Unknown',
        };
    }
}
