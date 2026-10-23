<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared behaviour for the four read-model mirrors.
 *
 * The interesting part is `current`. These tables keep every state they
 * ever observed, so "the board right now" is the newest row per Edge Lab
 * id rather than every row. Expressed as a subquery on max(id) rather than
 * a maintained `is_current` flag, because keeping a flag accurate means
 * updating the previous row - and these tables do not permit updates, for
 * the reason the whole design exists.
 */
trait MirrorsEdgeLab
{
    /** The newest observed state of each Edge Lab record. */
    public function scopeCurrent(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereIn('id', function ($sub) use ($table): void {
            $sub->selectRaw('max(id)')->from($table)->groupBy('edge_lab_id');
        });
    }

    public function scopeForEdgeLabId(Builder $query, int $id): Builder
    {
        return $query->where('edge_lab_id', $id);
    }

    /** How old this reading is, in minutes. */
    public function ageInMinutes(): float
    {
        return $this->observed_at === null ? INF : $this->observed_at->diffInMinutes(now());
    }
}
