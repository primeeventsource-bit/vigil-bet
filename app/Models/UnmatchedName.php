<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Model;

/**
 * A source name we could not resolve to a team. Logged, never guessed at.
 */
class UnmatchedName extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'context' => 'array',
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
