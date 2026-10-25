<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Event;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A logged-in customer and two priced games, for driving the real thing
 * in a browser.
 *
 * The end-to-end path this product exists to serve - open a game, click a
 * market, see it selected, build a parlay, save it - cannot be proven by
 * the test suite alone. The suite posts forms; a browser renders them,
 * and the class of bug being fixed here (a control nothing happens when
 * you click) is invisible to a request-level test.
 *
 * Local only. It refuses to run anywhere else, because it creates a user
 * with a known password.
 */
class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('BrowserTestSeeder creates a known password and is local only.');

            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'browser@vigil.test'],
            [
                'name' => 'Browser Test',
                'password' => 'browser-test-password',
                'email_verified_at' => now(),
            ],
        );

        Subscription::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tier' => 'pro',
                'status' => 'active',
                'current_period_end' => now()->addMonth(),
                'source' => 'seed',
            ],
        );

        $book = Book::query()->firstOrCreate(['key' => 'seedbook'], ['title' => 'Seed Book']);

        foreach ([
            ['Baltimore Ravens', 'Cincinnati Bengals', 'nfl'],
            ['Kansas City Chiefs', 'Buffalo Bills', 'nfl'],
        ] as $i => [$homeName, $awayName, $sport]) {
            $home = Team::query()->firstOrCreate(
                ['sport' => $sport, 'slug' => str($homeName)->slug()->toString()],
                ['name' => $homeName, 'display_name' => $homeName],
            );

            $away = Team::query()->firstOrCreate(
                ['sport' => $sport, 'slug' => str($awayName)->slug()->toString()],
                ['name' => $awayName, 'display_name' => $awayName],
            );

            $event = Event::query()->updateOrCreate(
                ['sport' => $sport, 'home_team_id' => $home->id, 'away_team_id' => $away->id],
                [
                    'starts_at' => now()->addDays(2 + $i),
                    'season' => (int) now()->year,
                    'status' => 'scheduled',
                ],
            );

            /*
             * Three markets, so the test can click a moneyline, a spread
             * and a total - the three the brief names.
             */
            $quotes = [
                ['h2h', 'home', null, -150],
                ['h2h', 'away', null, 130],
                ['spreads', 'home', -3.5, -110],
                ['spreads', 'away', 3.5, -110],
                ['totals', 'over', 44.5, -105],
                ['totals', 'under', 44.5, -115],
            ];

            DB::table('odds_snapshots')->where('event_id', $event->id)->delete();

            foreach ($quotes as [$market, $side, $line, $price]) {
                DB::table('odds_snapshots')->insert([
                    'event_id' => $event->id,
                    'book_id' => $book->id,
                    'market' => $market,
                    'side' => $side,
                    'line' => $line,
                    'price_american' => $price,
                    'price_decimal' => $price > 0 ? 1 + ($price / 100) : 1 + (100 / abs($price)),
                    'pulled_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        $this->command?->info('Seeded browser@vigil.test and 2 priced games.');
    }
}
