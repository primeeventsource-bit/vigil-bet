<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** APPEND ONLY. One thing worth telling somebody about. */
class Alert extends Model
{
    use AppendOnly, HasFactory;

    public const PICK_POSTED = 'pick.posted';

    public const LINE_MOVED = 'line.moved';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /** Which preference flag governs this type. */
    public function preferenceKey(): string
    {
        return match ($this->type) {
            self::PICK_POSTED => 'on_new_pick',
            self::LINE_MOVED => 'on_line_move',
            default => 'on_new_pick',
        };
    }
}
