<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A small, obviously fake dataset so the app runs and the screens render
 * with no API keys at all.
 *
 * Everything written here is demo data. EDGE_DEMO_MODE stays on until real
 * sources are connected, and the UI must show a DEMO badge wherever these
 * rows appear. No prediction rows are created, because a prediction with no
 * trained model behind it would be a lie.
 */
class FixtureSeeder extends Seeder
{
    public function run(): void
    {
        $books = Book::query()->tracked()->orderBy('display_order')->take(4)->get();

        if ($books->isEmpty()) {
            $this->command?->warn('Seed books first.');

            return;
        }

        $created = 0;

        foreach ([Sport::Nfl, Sport::Nba, Sport::Nhl] as $sport) {
            $teams = Team::query()->forSport($sport)->inRandomOrder()->take(4)->get();

            if ($teams->count() < 4) {
                continue;
            }

            foreach ([[0, 1], [2, 3]] as $index => [$homeIdx, $awayIdx]) {
                $startsAt = Carbon::now()->addHours(4 + $index * 3)->startOfHour();

                $event = Event::query()->firstOrCreate(
                    [
                        'sport' => $sport->value,
                        'home_team_id' => $teams[$homeIdx]->id,
                        'away_team_id' => $teams[$awayIdx]->id,
                        'starts_at' => $startsAt,
                    ],
                    [
                        'season' => (int) now()->year,
                        'season_type' => 'reg',
                        'status' => EventStatus::Scheduled->value,
                    ]
                );

                $this->seedOdds($event, $books, $sport);
                $created++;
            }
        }

        $this->command?->info("Demo fixtures seeded: {$created} events. EDGE_DEMO_MODE must stay on until real data is connected.");
    }

    private function seedOdds(Event $event, mixed $books, Sport $sport): void
    {
        if (OddsSnapshot::query()->where('event_id', $event->id)->exists()) {
            return;
        }

        $spread = match ($sport) {
            Sport::Nhl => 1.5,
            Sport::Nba => 4.5,
            default => 3.0,
        };

        $total = match ($sport) {
            Sport::Nhl => 6.0,
            Sport::Nba => 224.5,
            default => 44.5,
        };

        $rows = [];

        // Two pulls, an hour apart, so the odds-movement chart has a shape.
        foreach ([120, 30] as $minutesAgo) {
            $pulledAt = Carbon::now()->subMinutes($minutesAgo);

            foreach ($books as $bookIndex => $book) {
                $jitter = ($bookIndex - 1) * 5;
                $drift = $minutesAgo === 30 ? 5 : 0;

                $quotes = [
                    [Market::Moneyline, Side::Home, null, -140 - $jitter - $drift],
                    [Market::Moneyline, Side::Away, null, 120 + $jitter],
                    [Market::Spread, Side::Home, -$spread, -110 - $jitter],
                    [Market::Spread, Side::Away, $spread, -110 + $jitter],
                    [Market::Total, Side::Over, $total, -110 - $jitter + $drift],
                    [Market::Total, Side::Under, $total, -110 + $jitter],
                ];

                foreach ($quotes as [$market, $side, $line, $price]) {
                    $rows[] = [
                        'event_id' => $event->id,
                        'book_id' => $book->id,
                        'market' => $market->value,
                        'side' => $side->value,
                        'selection_team_id' => match ($side) {
                            Side::Home => $event->home_team_id,
                            Side::Away => $event->away_team_id,
                            default => null,
                        },
                        'line' => $line,
                        'price_american' => $price,
                        'price_decimal' => OddsConverter::americanToDecimal($price),
                        'pulled_at' => $pulledAt,
                        'created_at' => now(),
                    ];
                }
            }
        }

        OddsSnapshot::insert($rows);
    }
}
