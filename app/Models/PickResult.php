<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** APPEND ONLY. One result, graded from a final score. */
class PickResult extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units' => 'float',
            'clv' => 'float',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function pick(): BelongsTo
    {
        return $this->belongsTo(PublishedPick::class, 'published_pick_id');
    }
}
