<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY.
 *
 * What the player actually did, read from the box score by the same
 * reader the projection was built with - so a projection and its grade
 * can never disagree about what "5-12" meant.
 *
 * `void` is a real outcome and not a missing one. A player who did not
 * take the field has no line in the box score and most books void a prop
 * on him rather than settling it, so the bet did not win or lose. It is
 * recorded and then held out of every calibration figure.
 */
class PropProjectionResult extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'actual' => 'float',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(PropProjection::class, 'prop_projection_id');
    }

    /** Whether this graded row says the model's preferred side won. */
    public function wonFor(string $side): bool
    {
        return $this->outcome === $side;
    }

    public function isGradable(): bool
    {
        return $this->outcome === 'over' || $this->outcome === 'under';
    }
}
