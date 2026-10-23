<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. A customer who locked themselves out.
 *
 * §9 allows 7, 30 or 90 days and says it cannot be undone early. There is
 * no method on this class that ends one, and no column it could write to
 * if there were - the only way out is the date passing.
 *
 * The durations are a fixed set rather than a free number, because a field
 * that accepts any value accepts 1, and a one-day self-exclusion is a
 * button that lets somebody feel they have done something.
 */
class SelfExclusion extends Model
{
    use AppendOnly, HasFactory;

    /** @var array<int, int> */
    public const DURATIONS = [7, 30, 90];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('ends_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->ends_at->isFuture();
    }

    /** How long is left, in words a person would use. */
    public function remaining(): string
    {
        return $this->ends_at->diffForHumans(['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }
}
