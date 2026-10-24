<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * APPEND ONLY, and never written after kickoff.
 *
 * What the product published as its own opinion, with the confidence it
 * stated at the time. A weaker claim than a PublishedPick, deliberately:
 * a pick says this market has beaten the closing line, an opinion says
 * only that the model's probabilities have been measured to land close
 * to the truth.
 *
 * The two live in separate tables so the stronger record can be read
 * without qualification. Nothing that computes the public record touches
 * this one, and nothing here may ever be described as a proven edge.
 *
 * Every row carries the calibration measured when it was published, so
 * the accuracy page reports the standard that was applied at the time
 * rather than today's. A model that drifts cannot retroactively change
 * the standing of opinions published while it was good.
 */
class Opinion extends Model
{
    use AppendOnly, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'model_prob' => 'float',
            'fair_prob' => 'float',
            'edge_at_price' => 'float',
            'stake_fraction' => 'float',
            'calibration_error_at_publish' => 'float',
            'price_american' => 'integer',
            'model_version' => 'integer',
            'model_predictions_at_publish' => 'integer',
            'kickoff_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function result(): HasOne
    {
        return $this->hasOne(OpinionResult::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Games that have finished and have no result row yet. */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->whereDoesntHave('result')->where('kickoff_at', '<', now());
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('result');
    }

    /** Published, not yet started. What a customer is looking at. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('kickoff_at', '>', now())->orderBy('kickoff_at');
    }

    /**
     * The confidence figure, as a whole percentage.
     *
     * Rounded for display in one place rather than in each template,
     * because a figure that reads 74% here and 73.6% there invites being
     * treated as more precise than it is.
     */
    public function confidencePercent(): int
    {
        return (int) round($this->model_prob * 100);
    }
}
