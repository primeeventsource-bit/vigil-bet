<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\VigMethod;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. The no-vig market probability at a point in time. This is the
 * benchmark every model is measured against.
 */
class ConsensusPrice extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'side' => Side::class,
            'method' => VigMethod::class,
            'line' => 'float',
            'fair_prob' => 'float',
            'implied_sum' => 'float',
            'book_count' => 'integer',
            'best_price_american' => 'integer',
            'based_on_pulled_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function bestBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'best_book_id');
    }
}
