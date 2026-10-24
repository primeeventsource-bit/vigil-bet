<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetResult;
use App\Enums\Market;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestPrediction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'side' => Side::class,
            'result' => BetResult::class,
            'line' => 'float',
            'prob' => 'float',
            'market_fair_prob' => 'float',
            'clv_prob_delta' => 'float',
            'price_at_predict_american' => 'integer',
            'closing_price_american' => 'integer',
            'pnl_flat_cents' => 'integer',
            'predicted_at' => 'datetime',
            'as_of' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
