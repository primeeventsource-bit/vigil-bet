<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Side;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY, and always timestamped before kickoff. This table is the
 * honest track record: nothing here is ever revised after the fact.
 */
class Prediction extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'side' => Side::class,
            'line' => 'float',
            'prob' => 'float',
            'fair_price_american' => 'integer',
            'projected_home_score' => 'float',
            'projected_away_score' => 'float',
            'projected_margin' => 'float',
            'projected_total' => 'float',
            'sigma' => 'float',
            'components' => 'array',
            'feature_contributions' => 'array',
            'predicted_at' => 'datetime',
            'event_starts_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function featureSet(): BelongsTo
    {
        return $this->belongsTo(FeatureSet::class);
    }
}
