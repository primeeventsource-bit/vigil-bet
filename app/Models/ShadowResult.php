<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. How a shadow pick turned out.
 *
 * Units rather than money, for a reason that is sharper here than on the
 * real record: nobody was told to stake anything on these, because nobody
 * was shown them. A figure in dollars would imply a bet that was never
 * available to place.
 *
 * `clv` is the figure that matters. Whether a shadow pick won is mostly
 * noise at this sample size; whether it beat the number the market closed
 * at is the thing that would eventually justify a verdict.
 */
class ShadowResult extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units' => 'float',
            'clv' => 'float',
            'final_home_score' => 'integer',
            'final_away_score' => 'integer',
            'closing_price_american' => 'integer',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function pick(): BelongsTo
    {
        return $this->belongsTo(ShadowPick::class, 'shadow_pick_id');
    }

    public function isWin(): bool
    {
        return $this->status === 'won';
    }

    /** A push returned the stake and tested nothing. */
    public function isGraded(): bool
    {
        return in_array($this->status, ['won', 'lost'], true);
    }
}
