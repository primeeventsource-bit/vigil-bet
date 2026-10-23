<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY. One explanation, and the facts it was allowed to use.
 *
 * A Read is never edited. If the prompt changes, the version changes and
 * a new row is written beside the old one - what a customer was actually
 * shown is part of the record, and rewriting it later would make the
 * audit trail a description of today's prompt rather than of that day's.
 */
class VigilRead extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'prompt_version' => 'integer',
            'attempt' => 'integer',
            'fact_sheet' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function scopeForBoardEntry(Builder $query, int $id): Builder
    {
        return $query->where('subject_type', 'board_entry')->where('subject_id', $id);
    }

    public function scopeCurrentVersion(Builder $query): Builder
    {
        return $query->where('prompt_version', (int) config('ai.prompt_version'));
    }

    /** Whether a customer is reading the model's words or the template's. */
    public function fromModel(): bool
    {
        return $this->source === 'model';
    }
}
