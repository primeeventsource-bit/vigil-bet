<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\LeakageViolation;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. A feature vector built as of a moment in time.
 *
 * max_source_at is the newest source timestamp that fed this vector. It must
 * precede kickoff, and the leakage test fails the build if it ever does not.
 */
class FeatureSet extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Leakage guard. Postgres enforces this with a check constraint; this
     * hook enforces it on SQLite and in tests too, so a feature built from
     * data that did not exist before kickoff can never be written at all.
     */
    protected static function booted(): void
    {
        static::creating(function (self $set): void {
            if ($set->max_source_at === null || $set->event_starts_at === null) {
                return;
            }

            if ($set->max_source_at->greaterThan($set->event_starts_at)) {
                throw new LeakageViolation(
                    'Refusing to store a feature set built from data timestamped after kickoff.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'as_of' => 'datetime',
            'max_source_at' => 'datetime',
            'event_starts_at' => 'datetime',
            'built_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function leaksFuture(): bool
    {
        return $this->max_source_at->greaterThan($this->event_starts_at);
    }
}
