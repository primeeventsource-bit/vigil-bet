<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Book;
use Illuminate\Database\Seeder;

/**
 * The US books The Odds API reports, including Hard Rock Bet. Any book that
 * shows up in a pull and is not listed here is created automatically, so
 * this is a starting point rather than a closed list.
 */
class BookSeeder extends Seeder
{
    private const BOOKS = [
        ['draftkings', 'DraftKings', 'us', 10],
        ['fanduel', 'FanDuel', 'us', 20],
        ['betmgm', 'BetMGM', 'us', 30],
        ['hardrockbet', 'Hard Rock Bet', 'us2', 40],
        ['williamhill_us', 'Caesars', 'us', 50],
        ['betrivers', 'BetRivers', 'us', 60],
        ['espnbet', 'ESPN BET', 'us', 70],
        ['fanatics', 'Fanatics', 'us', 80],
        ['bovada', 'Bovada', 'us', 90],
        ['mybookieag', 'MyBookie.ag', 'us', 100],
        ['betonlineag', 'BetOnline.ag', 'us', 110],
        ['lowvig', 'LowVig.ag', 'us', 120],
        ['betus', 'BetUS', 'us', 130],
        ['ballybet', 'Bally Bet', 'us2', 140],
        ['betparx', 'betPARX', 'us2', 150],
        ['fliff', 'Fliff', 'us2', 160],
        ['windcreek', 'Wind Creek', 'us2', 170],
    ];

    public function run(): void
    {
        $mine = (array) config('edge.my_books');
        $primary = $mine[0] ?? null;

        foreach (self::BOOKS as [$key, $title, $region, $order]) {
            Book::query()->updateOrCreate(
                ['key' => $key],
                [
                    'title' => $title,
                    'region' => $region,
                    'is_tracked' => true,
                    // Every tracked book feeds the fair price. Only the ones
                    // I hold an account with can produce a pick.
                    'is_bettable' => in_array($key, $mine, true),
                    'is_primary' => $key === $primary,
                    'display_order' => $order,
                ]
            );
        }

        // Not a real book: the bucket historical closing lines are imported
        // under, kept untracked so it never competes in line shopping.
        Book::query()->updateOrCreate(
            ['key' => 'historical_close'],
            [
                'title' => 'Historical closing line',
                'region' => 'historical',
                'is_tracked' => false,
                'display_order' => 999,
            ]
        );

        $this->command?->info(sprintf(
            'Books seeded. Bettable: %s.',
            implode(', ', Book::query()->bettable()->orderByDesc('is_primary')->pluck('title')->all()) ?: 'none',
        ));
    }
}
