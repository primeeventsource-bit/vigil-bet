<?php

declare(strict_types=1);

namespace App\Models;

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
        return $this->verdict === 'proven';
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
