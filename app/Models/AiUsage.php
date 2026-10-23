<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per call to the model, including the calls whose output was
 * rejected. Those are the expensive ones to be blind to.
 */
class AiUsage extends Model
{
    use HasFactory;

    protected $table = 'ai_usage';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cost_millicents' => 'integer',
            'accepted' => 'boolean',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }

    /** Dollars, for display. Stored as integer millicents. */
    public static function dollars(int $millicents): string
    {
        return '$'.number_format($millicents / 100_000, 2);
    }
}
