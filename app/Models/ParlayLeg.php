<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selection inside a parlay.
 *
 * The same claim about a game as a UserBet, with the same fields, so the
 * grader that settles a straight bet settles a leg. Two vocabularies for
 * the same thing would need two graders, and the second one would go
 * wrong in its own way.
 *
 * What a leg carries that a straight bet does not is the engine's opinion
 * frozen at the moment it was saved. The comparison the product promises
 * - did VIGIL agree with this pick - has to be answered as of when the
 * user chose. Models retrain weekly, so reading one live would rewrite
 * every past comparison each Sunday.
 */
class ParlayLeg extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'model_probability' => 'float',
            'model_edge' => 'float',
            'settled_at' => 'datetime',
        ];
    }

    public function parlay(): BelongsTo
    {
        return $this->belongsTo(UserParlay::class, 'user_parlay_id');
    }

    public function boardEntry(): BelongsTo
    {
        return $this->belongsTo(VlBoardEntry::class, 'board_entry_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Whether this leg still decides anything.
     *
     * A pushed or voided leg drops out of the parlay under every
     * sportsbook's rules, so it neither wins nor loses it - it shortens
     * it. Treating a push as a loss is the single most common way a
     * home-made tracker understates a bettor's record.
     */
    public function counts(): bool
    {
        return ! in_array($this->status, ['push', 'void'], true);
    }
}
