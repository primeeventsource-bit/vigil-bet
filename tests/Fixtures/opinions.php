<?php

declare(strict_types=1);

use App\Models\ModelVersion;

/*
|--------------------------------------------------------------------------
| Published opinions
|--------------------------------------------------------------------------
|
| Shared because more than one test file needs them. They lived in
| OpinionPublisherTest.php, which meant any other file using them only
| passed when that one loaded first - green in the suite, broken alone.
|
*/

function publishableModel(string $sport = 'nhl', string $market = 'h2h', array $metrics = []): ModelVersion
{
    static $version = 0;
    $version++;

    return ModelVersion::create([
        'sport' => $sport,
        'market' => $market,
        'model_type' => 'elo',
        'version' => $version,
        'status' => 'active',
        'training_rows' => 1900,
        'params' => ['model' => 'elo'],
        // Clears the bar: 956 predictions, calibration off by 3.35 points.
        'holdout_metrics' => array_merge([
            'predictions' => 956,
            'calibration_error' => 0.0335,
            'verdict' => 'unproven',
        ], $metrics),
        'trained_at' => now(),
        'trained_through_at' => now(),
        'activated_at' => now(),
    ]);
}

/** A bet the scanner would take on the merits, with a model behind it. */
function opinionCandidate(array $overrides = [], int $hoursToKickoff = 6, ?ModelVersion $model = null)
{
    $model ??= publishableModel();

    $event = engineGame('Detroit Red Wings', 'Boston Bruins', [
        'sport' => 'nhl',
        'starts_at' => now()->addHours($hoursToKickoff),
    ]);

    return engineBoardEntry(null, $event, array_merge([
        'sport' => 'nhl',
        'market' => 'h2h',
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'market_verdict' => 'unproven',
        'shadow_recommendation' => 'bet',
        'shadow_pass_reason' => null,
        'model_version_id' => $model->id,
        'model_prob' => 0.62,
        'best_price_american' => -110,
        'edge_at_price' => 0.0962,
        'kelly_fraction' => 0.04,
    ], $overrides));
}
