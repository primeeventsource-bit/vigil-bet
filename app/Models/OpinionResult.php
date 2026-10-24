<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. How a published opinion turned out.
 *
 * Graded the same way a PublishedPick is, by the same settler logic, so
 * that an opinion and a pick on the same selection can never disagree
 * about whether it won.
 *
 * Closing line value is stored even though an opinion makes no claim to
 * beat the closing line. It is the measurement that would eventually
 * show whether one of these markets deserves the stronger badge, and it
 * can only be collected while the opinions are being published.
 */
class OpinionResult extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units' => 'float',
            'clv' => 'float',
            'closing_price_american' => 'integer',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function opinion(): BelongsTo
    {
        return $this->belongsTo(Opinion::class);
    }

    public function won(): bool
    {
        return $this->status === 'won';
    }
}
