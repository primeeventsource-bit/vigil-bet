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

    /**
     * Markets that have beaten the closing line. Strictly that.
     *
     * This used to read `recommendableVerdicts()`, which was the same
     * list while only `proven` could recommend. It is not the same list
     * any more: publication opened up to the unvalidated tiers and the
     * edge claim did not, so the two questions needed separating.
     *
     * Everything that asks this one is asking about a *claim* - whether
     * the homepage may show a track record, whether /record exists at
     * all. Pointing it at the publication list would put a win rate back
     * on a marketing page for markets that have proved nothing, which is
     * the exact bug Verdict's own docblock records.
     */
    /**
     * How many graded, out-of-sample predictions stand behind a market.
     *
     * The number the verdict was computed from, which is the honest
     * companion to a confidence figure: a stated 72% from a market with
     * ten graded predictions and the same 72% from one with four
     * thousand are not the same claim, and the product should not let
     * them look alike.
     *
     * One query for the whole board, memoised per request. A dashboard
     * draws ten cards and each would otherwise ask the same question.
     *
     * @return array<string, int>
     */
    public static function sampleSizes(): array
    {
        return once(fn (): array => self::query()
            ->current()
            ->get(['sport', 'market', 'predictions'])
            ->mapWithKeys(fn (self $model): array => [
                $model->sport.'|'.$model->market => (int) $model->predictions,
            ])
            ->all());
    }

    /** The sample behind one market, or null when we hold no model for it. */
    public static function sampleFor(?string $sport, ?string $market): ?int
    {
        return self::sampleSizes()[$sport.'|'.$market] ?? null;
    }

    public function scopeProven(Builder $query): Builder
    {
        return $query->whereIn('verdict', array_values(array_map(
            static fn (Verdict $verdict): string => $verdict->value,
            array_filter(Verdict::cases(), static fn (Verdict $verdict): bool => $verdict->claimsEdge()),
        )));
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
