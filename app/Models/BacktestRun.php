<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Enums\Verdict;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'model_type' => ModelType::class,
            'verdict' => Verdict::class,
            'config' => 'array',
            'metrics' => 'array',
            'market_metrics' => 'array',
            'from_date' => 'date',
            'to_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'n_predictions' => 'integer',
            'delta_log_loss' => 'float',
            'mean_home_bias' => 'float',
        ];
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(BacktestPrediction::class);
    }

    public function beatsMarket(): bool
    {
        return $this->delta_log_loss !== null && $this->delta_log_loss < 0;
    }
}
