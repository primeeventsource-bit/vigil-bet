<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\ModelType;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Verdict;
use App\Models\Book;
use App\Models\Event;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Team;
use App\Models\ValueBet;
use App\Models\ValueScan;

/**
 * A small but complete slice of Edge Lab, for the internal API tests: a
 * game, a book, a trained model with a verdict on it, a prediction, and a
 * scan that turned the two into a board row.
 */
function internalGame(string $slug = 'g1', string $sport = 'nfl', ?int $hoursAway = 3): Event
{
    $home = Team::create(['sport' => $sport, 'name' => 'Buffalo Bills '.$slug, 'display_name' => 'Buffalo Bills', 'slug' => 'bills-'.$slug, 'abbreviation' => 'BUF']);
    $away = Team::create(['sport' => $sport, 'name' => 'Miami Dolphins '.$slug, 'display_name' => 'Miami Dolphins', 'slug' => 'dolphins-'.$slug, 'abbreviation' => 'MIA']);

    return Event::create([
        'sport' => $sport,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours($hoursAway),
        'status' => EventStatus::Scheduled->value,
        'odds_api_event_id' => 'odds-'.$slug,
    ]);
}

function internalModel(string $verdict = 'proven', string $sport = 'nfl'): ModelVersion
{
    return ModelVersion::create([
        'sport' => $sport,
        'market' => Market::Moneyline->value,
        'model_type' => ModelType::cases()[0]->value,
        'version' => 1,
        'params' => ['k' => 1],
        'trained_at' => now()->subWeek(),
        'trained_through_at' => now()->subWeek(),
        'training_rows' => 5000,
        'holdout_metrics' => [
            'verdict' => $verdict,
            'verdict_reason' => 'Beats the closing line on log loss by more than chance explains.',
            'predictions' => 420,
            'log_loss' => 0.66,
            'market_log_loss' => 0.68,
            'log_loss_improvement' => 0.02,
        ],
        'status' => ModelStatus::Active->value,
    ]);
}

function internalBoardRow(Event $event, ModelVersion $model, string $recommendation = 'bet'): ValueBet
{
    $book = Book::firstOrCreate(['key' => 'hardrockbet'], ['title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true]);

    $scan = ValueScan::create([
        'scanned_at' => now(),
        'settings' => ['edge_threshold' => 0.03],
        'bankroll_cents' => 500000,
        'candidates' => 1,
        'picks' => $recommendation === 'bet' ? 1 : 0,
    ]);

    $prediction = Prediction::create([
        'event_id' => $event->id,
        'model_version_id' => $model->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'prob' => 0.62,
        'fair_price_american' => -163,
        'predicted_at' => now()->subHour(),
        'event_starts_at' => $event->starts_at,
        'created_at' => now(),
    ]);

    return ValueBet::create([
        'value_scan_id' => $scan->id,
        'event_id' => $event->id,
        'sport' => $event->sport->value,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'prediction_id' => $prediction->id,
        'model_version_id' => $model->id,
        'model_prob' => 0.62,
        'fair_prob' => 0.57,
        'edge' => 0.05,
        'edge_at_price' => 0.04,
        'break_even_win_rate' => 0.58,
        'ev_per_dollar' => 0.08,
        'best_book_id' => $book->id,
        'best_price_american' => -140,
        'book_count' => 5,
        'line_shopped' => ['draftkings' => -145, 'hardrockbet' => -140],
        'kelly_fraction' => 0.04,
        'suggested_stake_cents' => 20000,
        'suggested_stake_units' => 2.0,
        'market_verdict' => Verdict::Proven->value,
        'recommendation' => $recommendation === 'bet' ? Recommendation::Bet->value : Recommendation::Pass->value,
        'probability_rank' => 1,
        'value_rank' => 1,
        'reason' => '$200.00 at -140 on Hard Rock Bet, 4.0% over the break-even rate.',
        'flagged_at' => now(),
        'created_at' => now(),
    ]);
}

function internalQuote(Event $event, string $bookKey = 'draftkings', int $price = -140, ?string $propKey = null): OddsSnapshot
{
    $book = Book::firstOrCreate(['key' => $bookKey], ['title' => ucfirst($bookKey), 'is_tracked' => true]);

    return OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => $propKey === null ? Market::Moneyline->value : null,
        'market_key' => $propKey,
        'side' => $propKey === null ? Side::Home->value : Side::Over->value,
        'player_name' => $propKey === null ? null : 'Josh Allen',
        'line' => $propKey === null ? null : 249.5,
        'price_american' => $price,
        'price_decimal' => $price < 0 ? 1 + (100 / abs($price)) : 1 + ($price / 100),
        'pulled_at' => now(),
        'provenance' => 'live_pull',
        'created_at' => now(),
    ]);
}
