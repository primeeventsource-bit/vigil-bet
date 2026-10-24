<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived opening and closing quote per event, book, market and side.
 * Closing is the last snapshot strictly before kickoff, which is what CLV
 * is measured against.
 */
class EventLineMarker extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'side' => Side::class,
            'line_open' => 'float',
            'line_close' => 'float',
            'price_open_american' => 'integer',
            'price_close_american' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
