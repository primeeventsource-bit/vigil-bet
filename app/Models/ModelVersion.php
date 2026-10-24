<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\ModelType;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trained model, frozen. Every prediction references the exact version
 * that produced it, so the track record stays attributable.
 */
class ModelVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'model_type' => ModelType::class,
            'status' => ModelStatus::class,
            'params' => 'array',
            'calibration' => 'array',
            'holdout_metrics' => 'array',
            'version' => 'integer',
            'training_rows' => 'integer',
            'feature_schema_version' => 'integer',
            'trained_at' => 'datetime',
            'trained_through_at' => 'datetime',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ModelStatus::Active);
    }

    public function describe(): string
    {
        return sprintf(
            '%s %s %s v%d',
            strtoupper($this->sport->value),
            $this->market->label(),
            $this->model_type->value,
            $this->version
        );
    }
}
