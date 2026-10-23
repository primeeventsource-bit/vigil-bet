<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Read, seen by one customer, on one day. */
class ReadView extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'viewed_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function read(): BelongsTo
    {
        return $this->belongsTo(VigilRead::class, 'vigil_read_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
