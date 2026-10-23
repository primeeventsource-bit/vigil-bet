<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** APPEND ONLY. One slip, as Edge Lab judged it. */
class ParlayGrade extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'legs' => 'array',
            'result' => 'array',
            'win_probability' => 'float',
            'book_hold' => 'float',
            'shaded_edge' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasAnswered(): bool
    {
        return $this->unavailable_reason === null && $this->result !== null;
    }

    /**
     * Whether this slip is worth backing.
     *
     * A parlay is almost never worth backing - the hold compounds with
     * every leg - so the honest default is no, and the verdict has to
     * come from Edge Lab rather than from an assumption either way.
     */
    public function isWorthBacking(): bool
    {
        return $this->wasAnswered()
            && $this->shaded_edge !== null
            && $this->shaded_edge > 0;
    }
}
