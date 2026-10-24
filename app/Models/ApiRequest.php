<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per outbound API call. Quota is tracked here, so a provider going
 * dark or a quota running out is visible instead of silent.
 */
class ApiRequest extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'quota_used' => 'integer',
            'quota_remaining' => 'integer',
            'credits_cost' => 'integer',
            'response_bytes' => 'integer',
            'items_written' => 'integer',
            'cache_hit' => 'boolean',
            'requested_at' => 'datetime',
        ];
    }

    public function wasSuccessful(): bool
    {
        return $this->http_status !== null && $this->http_status >= 200 && $this->http_status < 300;
    }
}
