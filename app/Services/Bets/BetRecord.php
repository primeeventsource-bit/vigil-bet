<?php

declare(strict_types=1);

namespace App\Services\Bets;

use App\Models\User;
use App\Models\UserBet;
use Illuminate\Support\Collection;

/**
 * A customer's own record: wins, losses, ROI, closing line value.
 *
 * Every figure here carries its sample size, and every figure is null
 * rather than zero when there is no sample. §7 says it for the public
 * record and it is just as true here: "with zero settled picks, show no
 * rate at all". A 0% ROI and an unmeasured ROI look identical on a
 * dashboard and mean completely different things.
 */
class BetRecord
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $settled = UserBet::query()
            ->where('user_id', $user->id)
            ->settled()
            ->get();

        $pending = UserBet::query()->where('user_id', $user->id)->pending()->count();

        return [
            'settled' => $settled->count(),
            'pending' => $pending,
            'won' => $settled->where('status', 'won')->count(),
            'lost' => $settled->where('status', 'lost')->count(),
            'pushed' => $settled->where('status', 'push')->count(),
            'staked_cents' => (int) $settled->sum('stake_cents'),
            'returned_cents' => (int) $settled->sum('return_cents'),
            'profit_cents' => $settled->isEmpty() ? null : $this->profitCents($settled),
            'win_rate' => $this->winRate($settled),
            'roi' => $this->roi($settled),
            'units' => $this->units($settled),
            'clv' => $this->averageClv($user),
            'clv_sample' => UserBet::query()->where('user_id', $user->id)->whereNotNull('clv')->count(),
        ];
    }

    /** @param  Collection<int, UserBet>  $settled */
    private function profitCents(Collection $settled): int
    {
        return (int) $settled->sum('return_cents') - (int) $settled->sum('stake_cents');
    }

    /**
     * Wins over decided bets.
     *
     * Pushes are excluded from the denominator rather than counted as
     * half a win: a push is a bet that did not happen in any sense that
     * a win rate measures.
     *
     * @param  Collection<int, UserBet>  $settled
     */
    private function winRate(Collection $settled): ?float
    {
        $decided = $settled->whereIn('status', ['won', 'lost']);

        if ($decided->isEmpty()) {
            return null;
        }

        return round($decided->where('status', 'won')->count() / $decided->count(), 4);
    }

    /** @param  Collection<int, UserBet>  $settled */
    private function roi(Collection $settled): ?float
    {
        $staked = (int) $settled->sum('stake_cents');

        if ($staked === 0) {
            return null;
        }

        return round($this->profitCents($settled) / $staked, 4);
    }

    /**
     * Profit in units, where a unit is the average stake.
     *
     * Units rather than dollars so a record is comparable between
     * somebody staking $5 and somebody staking $500, which is what makes
     * the figure worth printing at all.
     *
     * @param  Collection<int, UserBet>  $settled
     */
    private function units(Collection $settled): ?float
    {
        if ($settled->isEmpty()) {
            return null;
        }

        $averageStake = (float) $settled->avg('stake_cents');

        if ($averageStake <= 0) {
            return null;
        }

        return round($this->profitCents($settled) / $averageStake, 2);
    }

    /**
     * Average closing line value, over the bets that have one.
     *
     * Not over all bets. A manual entry has no recorded opening price to
     * compare against and is left out rather than counted as zero, which
     * would drag a real figure toward nothing.
     */
    private function averageClv(User $user): ?float
    {
        $average = UserBet::query()
            ->where('user_id', $user->id)
            ->whereNotNull('clv')
            ->avg('clv');

        return $average === null ? null : round((float) $average, 5);
    }

    /**
     * The bankroll curve, one point per settled bet, in order.
     *
     * Built from the bets rather than from the ledger so the line and
     * the record on the same page cannot disagree. Starts at zero profit
     * rather than at the starting bankroll, because the question the
     * chart answers is "is this working", not "how much money is there".
     *
     * @return array{labels: array<int, string>, series: array<int, float>}
     */
    public function curve(User $user, int $limit = 60): array
    {
        $bets = UserBet::query()
            ->where('user_id', $user->id)
            ->settled()
            ->orderBy('settled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($bets->isEmpty()) {
            // No points, and the panel says so. A flat line at zero would
            // read as "tried and broke even".
            return ['labels' => [], 'series' => []];
        }

        $running = 0;
        $labels = [];
        $series = [];

        foreach ($bets as $bet) {
            $running += (int) $bet->return_cents - (int) $bet->stake_cents;
            $labels[] = $bet->settled_at?->format('j M') ?? '';
            $series[] = round($running / 100, 2);
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Rows for the customer's own CSV.
     *
     * §8: "CSV export of own bets only". Scoped by user id in the query
     * rather than filtered afterwards, so there is no version of this
     * that returns somebody else's rows because a parameter was missed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function csvRows(User $user): array
    {
        return UserBet::query()
            ->where('user_id', $user->id)
            ->orderBy('placed_at')
            ->get()
            ->map(fn (UserBet $bet) => [
                'placed_at' => $bet->placed_at?->toIso8601String(),
                'sport' => $bet->sport,
                'market' => $bet->market,
                'selection' => $bet->selection,
                'side' => $bet->side,
                'line' => $bet->line,
                'price_american' => $bet->price_american,
                'book' => $bet->book_title ?? $bet->book,
                // Dollars in the export, because a spreadsheet is read by
                // a person. Cents stay in the database.
                'stake' => number_format($bet->stake_cents / 100, 2, '.', ''),
                'status' => $bet->status,
                'return' => $bet->return_cents === null
                    ? ''
                    : number_format($bet->return_cents / 100, 2, '.', ''),
                'profit' => $bet->profitCents() === null
                    ? ''
                    : number_format($bet->profitCents() / 100, 2, '.', ''),
                'closing_price_american' => $bet->closing_price_american ?? '',
                'closing_line_value' => $bet->clv === null ? '' : $bet->clv,
                'settled_at' => $bet->settled_at?->toIso8601String() ?? '',
                'source' => $bet->source,
            ])
            ->all();
    }
}
