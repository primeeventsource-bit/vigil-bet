<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAlias extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sport' => Sport::class];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
