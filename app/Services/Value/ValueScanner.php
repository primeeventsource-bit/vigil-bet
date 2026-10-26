<?php

declare(strict_types=1);

namespace App\Services\Value;

use App\Data\ConsensusPrice;
use App\Data\ShoppedLine;
use App\Data\StakeDecision;
use App\Enums\EventStatus;
use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Sport;
use App\Enums\Verdict;
use App\Enums\VigMethod;
use App\Modeling\Odds\LineShopper;
use App\Modeling\Odds\MarketConsensus;
use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Models\Book;
use App\Models\ConsensusPrice as ConsensusPriceRow;
use App\Models\Event;
use App\Models\Prediction;
use App\Models\ValueBet;
use App\Models\ValueScan;
use App\Services\Engine\Bankroll\LimitGuard;
use App\Services\Engine\Bankroll\StakeSizer;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares the model against the board and decides what, if anything, to bet.
 *
 * Every candidate is recorded, pick or pass, with the reason attached. A
 * tool that silently drops the bets it did not like is impossible to audit
 * and impossible to argue with, and the one number a bettor most needs to
 * see is the one that nearly qualified.
 *
 * Three prices, kept deliberately apart, because collapsing them is how a
 * tool recommends a bet that is theoretically sound and practically
 * unprofitable:
 *
 *  - the fair probability, from every tracked book, vig removed per book and
 *    then combined. This is the sharpest available estimate of the truth.
 *  - `edge`, the model against that fair probability. A statement about the
 *    model: does it disagree with the market at all.
 *  - `edge_at_price`, the model against the break-even rate of the price
 *    actually on offer at the pricing book. This is the only one that
 *    decides anything, because it is the only one that describes a bet that
 *    can be placed.
 *
 * A bet that is good at the best number on the board but short at the book
 * the bet would be placed at is a pass labelled "price too short here",
 * never a quiet omission and never a recommendation to bet somewhere there
 * is no account.
 */
class ValueScanner
{
    public function __construct(
        private readonly LineShopper $shopper,
        private readonly StakeSizer $sizer,
        private readonly LimitGuard $limits,
        private readonly Availability $availability,
    ) {}

    public function scan(?Sport $sport = null, int $horizonHours = 72, ?int $jobRunId = null): ValueScan
    {
        $settings = BankrollSetting::current();
        $pricingBook = Book::query()->where('key', (string) config('edge.pricing_book'))->first();

        $rules = [
            'edge_threshold' => (float) ($settings?->edge_threshold ?? config('edge.value.edge_threshold')),
            'min_book_count' => (int) ($settings?->min_book_count ?? config('edge.value.min_book_count')),
            'min_win_probability' => (float) ($settings?->min_win_probability ?? config('edge.value.min_win_probability')),
            'require_proven_market' => (bool) ($settings?->require_proven_market ?? config('edge.value.require_proven_market')),
            'max_quote_age_minutes' => (int) config('edge.value.max_quote_age_minutes'),
            'vig_method' => (string) config('edge.value.vig_method'),
            'pricing_book' => $pricingBook?->key,
        ];

        $bankrollCents = BankrollLedgerEntry::currentBalanceCents()
            ?: (int) ($settings?->starting_bankroll_cents ?? 0);

        // Checked once for the whole sweep rather than per candidate: a
        // lockout is a fact about the period, and asking twelve times
        // invites twelve slightly different answers.
        $locked = $this->limits->isLocked();

        $scan = ValueScan::create([
            'scanned_at' => now(),
            'settings' => $rules,
            'rank_mode' => (string) config('edge.value.rank_mode', 'most_likely'),
            'pricing_book_id' => $pricingBook?->id,
            'bankroll_cents' => $bankrollCents,
            'job_run_id' => $jobRunId,
            'created_at' => now(),
        ]);

        $candidates = [];

        foreach ($this->candidates($sport, $horizonHours) as $prediction) {
            $candidates[] = $this->evaluate($prediction, $scan, $rules, $bankrollCents, $settings, $locked);
        }

        $this->persist($scan, $this->ranked($candidates));

        return $scan->fresh();
    }

    /**
     * The newest prediction per event, market and side, for games still to
     * come. Newest matters: activating a retrained model leaves the old
     * rows in place, and the board should reflect the model that is live.
     *
     * @return Collection<int, Prediction>
     */
    private function candidates(?Sport $sport, int $horizonHours): Collection
    {
        return Prediction::query()
            ->whereHas('event', fn ($q) => $q
                ->where('status', EventStatus::Scheduled)
                ->where('starts_at', '>', now())
                ->where('starts_at', '<=', now()->addHours($horizonHours))
                ->when($sport, fn ($inner) => $inner->forSport($sport))
            )
            ->with(['event.homeTeam', 'event.awayTeam', 'modelVersion'])
            ->orderBy('predicted_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Prediction $p) => $p->event_id.'|'.$p->market->value.'|'.$p->side->value)
            ->values();
    }

    /**
     * Evaluate one side of one market and decide.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function evaluate(
        Prediction $prediction,
        ValueScan $scan,
        array $rules,
        int $bankrollCents,
        ?BankrollSetting $settings,
        bool $locked,
    ): array {
        $event = $prediction->event;
        $market = $prediction->market;
        $side = $prediction->side;
        $modelProb = (float) $prediction->prob;
        $method = VigMethod::tryFrom($rules['vig_method']) ?? VigMethod::Multiplicative;

        $shopped = $this->shopper->shop(
            $event,
            $market,
            $side,
            $prediction->line,
            maxAgeMinutes: $rules['max_quote_age_minutes'],
        );

        $consensus = $this->consensus($prediction, $method, $rules['max_quote_age_minutes']);
        $verdict = $this->verdictFor($prediction);

        $fairProb = $consensus?->fairProbability;

        /*
         * A market that has not beaten the closing line is shrunk toward
         * it before any edge is taken. Everything downstream - the edge,
         * the edge at the price, expected value, the Kelly stake - is
         * measured from this number rather than the raw model one.
         */
        $modelProb = $this->shrinkToMarket($modelProb, $fairProb, $verdict, $event);

        $edge = $fairProb === null ? null : $modelProb - $fairProb;

        $price = $shopped->pricingBookPriceAmerican;
        $breakEven = $shopped->breakEvenWinRate();
        $edgeAtPrice = $breakEven === null ? null : $modelProb - $breakEven;

        $row = [
            'value_scan_id' => $scan->id,
            'event_id' => $event->id,
            'sport' => $event->sport->value,
            'market' => $market->value,
            'side' => $side->value,
            'line' => $prediction->line,
            'prediction_id' => $prediction->id,
            'model_version_id' => $prediction->model_version_id,
            'model_prob' => $modelProb,
            'fair_prob' => $fairProb,
            'break_even_win_rate' => $breakEven,
            'edge' => $edge,
            'edge_at_price' => $edgeAtPrice,
            'fair_prob_method' => $method->value,
            'ev_per_dollar' => $price === null ? null : OddsConverter::expectedValue($modelProb, $price),
            // best_* is the actionable price: the one at the book every
            // number here is measured at, and the one a pick tells you to
            // take. market_best_* is where the wider board was, for context.
            'best_book_id' => $shopped->pricingBookId,
            'best_price_american' => $price,
            'market_best_book_id' => $shopped->bestMarketBookId,
            'market_best_price_american' => $shopped->bestMarketPriceAmerican,
            'better_elsewhere' => $shopped->betterElsewhere(),
            'price_pulled_at' => $shopped->pricePulledAt,
            'book_count' => $consensus?->bookCount ?? 0,
            'line_shopped' => $shopped->quotes,
            'break_even_price_american' => OddsConverter::probabilityToAmerican($modelProb),
            'min_acceptable_price_american' => $this->minimumAcceptable($modelProb, (float) $rules['edge_threshold']),
            'bankroll_cents' => $bankrollCents,
            'threshold_used' => $rules['edge_threshold'],
            'market_verdict' => $verdict->value,
            'flagged_at' => now(),
            'created_at' => now(),
        ];

        [$recommendation, $passReason, $stake, $shadowRec, $shadowReason] = $this->decide(
            $shopped, $consensus, $verdict, $modelProb, $edgeAtPrice, $rules, $bankrollCents, $settings, $locked,
            $this->staleQuoteExists($shopped, $prediction, $rules),
        );

        $row['recommendation'] = $recommendation->value;
        $row['pass_reason'] = $passReason?->value;
        // What it would have been without the gate. Never read by
        // anything a customer can reach - see the shadow_picks table.
        $row['shadow_recommendation'] = $shadowRec->value;
        $row['shadow_pass_reason'] = $shadowReason?->value;
        $row['kelly_fraction'] = $stake?->kellyFraction ?? 0.0;
        $row['suggested_stake_cents'] = $stake?->isActionable ? $stake->stakeCents : 0;
        $row['suggested_stake_units'] = $stake?->isActionable ? $stake->units : 0.0;
        $row['reason'] = $this->sentence($recommendation, $passReason, $shopped, $stake, $edgeAtPrice);
        $row['reason_factors'] = [
            'model_prob' => $modelProb,
            'fair_prob' => $fairProb,
            'break_even_win_rate' => $breakEven,
            'edge' => $edge,
            'edge_at_price' => $edgeAtPrice,
            'threshold' => $rules['edge_threshold'],
            'book_count' => $consensus?->bookCount ?? 0,
            'implied_sum' => $consensus?->impliedSum,
            'pricing_book' => $shopped->pricingBookTitle,
            'market_best_book' => $shopped->bestMarketBookTitle,
            'cost_of_pricing_book' => $shopped->costOfPricingBook(),
            'verdict' => $verdict->value,
            'capped_by' => $stake?->cappedBy,
            'uncapped_kelly_cents' => $stake?->uncappedKellyCents,
        ];

        $row['_consensus'] = $consensus;
        $row['_shopped'] = $shopped;

        return $row;
    }

    /**
     * The decision, in the order the reasons actually matter.
     *
     * A lockout comes first because it overrides every other consideration:
     * the whole point of a limit is that it is obeyed on the day it is least
     * welcome. Then the price, because without one there is nothing to
     * evaluate. Then whether the fair price can be trusted at all, then
     * whether this model has earned the right to pick here, and only then
     * the size of the edge.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: Recommendation, 1: PassReason|null, 2: StakeDecision|null, 3: Recommendation, 4: PassReason|null}
     */
    private function decide(
        ShoppedLine $shopped,
        ?ConsensusPrice $consensus,
        Verdict $verdict,
        float $modelProb,
        ?float $edgeAtPrice,
        array $rules,
        int $bankrollCents,
        ?BankrollSetting $settings,
        bool $locked,
        bool $staleQuoteExists,
    ): array {
        /*
         * An ungated refusal is its own shadow: the gate is not what
         * stopped it, so there is no hidden answer to carry.
         */
        $pass = fn (PassReason $reason) => [Recommendation::Pass, $reason, null, Recommendation::Pass, $reason];

        if ($locked) {
            return $pass(PassReason::LockoutActive);
        }

        if (! $shopped->hasPrice()) {
            // Distinguish "never quoted" from "quoted, but too long ago to
            // act on". Both are passes; only one of them means the pull
            // cadence needs looking at.
            return $pass($staleQuoteExists ? PassReason::StaleQuote : PassReason::NoPrice);
        }

        if ($consensus === null || $consensus->bookCount < (int) $rules['min_book_count']) {
            return $pass(PassReason::TooFewBooks);
        }

        /*
         * The gate, and the question it hides.
         *
         * Everything below this point is a test of the bet on its own
         * merits, and the gate sits above them - so a row declined here
         * has never been edge-tested and says nothing about whether it
         * was a good bet. That is the right order for the product and the
         * wrong one for learning anything, so when the gate blocks, the
         * merits are still evaluated and the answer is carried out
         * alongside the refusal.
         *
         * The refusal itself is untouched. A gated row is still a pass,
         * still reads market_unproven, and still reaches a customer as a
         * decline.
         */
        if ($rules['require_proven_market'] && ! $verdict->allowsRecommendation()) {
            [$shadowRec, $shadowReason] = $this->decideOnMerits(
                $shopped, $modelProb, $edgeAtPrice, $rules, $bankrollCents, $settings
            );

            return [Recommendation::Pass, PassReason::MarketUnproven, null, $shadowRec, $shadowReason];
        }

        if ($edgeAtPrice === null || $edgeAtPrice <= 0.0) {
            return $pass(PassReason::NegativeEdge);
        }

        if ($edgeAtPrice < (float) $rules['edge_threshold']) {
            // The bet clears the bar at the best number on the board but not
            // at the book it would be placed at. That is a different fact
            // from "there is no edge here", and worth saying so.
            return $pass($this->clearsAtMarketBest($shopped, $modelProb, (float) $rules['edge_threshold'])
                ? PassReason::PriceTooShortHere
                : PassReason::BelowThreshold);
        }

        if ($modelProb < (float) $rules['min_win_probability']) {
            // A display preference, not a profitability test: this bet can
            // still make money, it just wins less often than was asked for.
            return $pass(PassReason::BelowMinWinProbability);
        }

        $stake = $this->sizer->size($modelProb, (int) $shopped->pricingBookPriceAmerican, $bankrollCents, $settings);

        if (! $stake->isActionable) {
            return [Recommendation::Pass, $stake->passReason, $stake, Recommendation::Pass, $stake->passReason];
        }

        return [Recommendation::Bet, null, $stake, Recommendation::Bet, null];
    }

    /**
     * The bet on its own merits, with the verdict gate taken out.
     *
     * The same tests decide() applies below the gate, in the same order,
     * on the same inputs. It exists so the question "would this have been
     * a bet" has exactly one answer, computed once, whether or not the
     * gate let it through.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: Recommendation, 1: PassReason|null}
     */
    private function decideOnMerits(
        ShoppedLine $shopped,
        float $modelProb,
        ?float $edgeAtPrice,
        array $rules,
        int $bankrollCents,
        ?BankrollSetting $settings,
    ): array {
        if ($edgeAtPrice === null || $edgeAtPrice <= 0.0) {
            return [Recommendation::Pass, PassReason::NegativeEdge];
        }

        if ($edgeAtPrice < (float) $rules['edge_threshold']) {
            return [Recommendation::Pass, $this->clearsAtMarketBest($shopped, $modelProb, (float) $rules['edge_threshold'])
                ? PassReason::PriceTooShortHere
                : PassReason::BelowThreshold];
        }

        if ($modelProb < (float) $rules['min_win_probability']) {
            return [Recommendation::Pass, PassReason::BelowMinWinProbability];
        }

        $stake = $this->sizer->size($modelProb, (int) $shopped->pricingBookPriceAmerican, $bankrollCents, $settings);

        if (! $stake->isActionable) {
            return [Recommendation::Pass, $stake->passReason];
        }

        return [Recommendation::Bet, null];
    }

    /**
     * Would this bet clear the threshold at the best price on the board.
     */
    private function clearsAtMarketBest(ShoppedLine $shopped, float $modelProb, float $threshold): bool
    {
        if ($shopped->bestMarketPriceAmerican === null) {
            return false;
        }

        $atBest = $modelProb - OddsConverter::americanToProbability($shopped->bestMarketPriceAmerican);

        return $atBest >= $threshold;
    }

    /**
     * Was there a price for this side that is simply too old to use.
     *
     * The shopper drops stale quotes before they can be acted on, which is
     * right, but it leaves a stale board and an empty one looking identical
     * from here. Asking again with the age limit lifted tells them apart,
     * and only a stale board says the pull cadence needs attention.
     *
     * @param  array<string, mixed>  $rules
     */
    private function staleQuoteExists(ShoppedLine $shopped, Prediction $prediction, array $rules): bool
    {
        if ($shopped->hasPrice()) {
            return false;
        }

        return $this->shopper->shop(
            $prediction->event,
            $prediction->market,
            $prediction->side,
            $prediction->line,
            maxAgeMinutes: 60 * 24 * 30,
        )->hasPrice();
    }

    /**
     * The market's fair probability for this side, from every tracked book
     * quoting both sides of the market.
     */
    private function consensus(Prediction $prediction, VigMethod $method, int $maxAgeMinutes): ?ConsensusPrice
    {
        $byBook = [];

        foreach ($prediction->market->sides() as $side) {
            $shopped = $this->shopper->shop(
                $prediction->event,
                $prediction->market,
                $side,
                $prediction->line,
                maxAgeMinutes: $maxAgeMinutes,
            );

            foreach ($shopped->quotes as $bookKey => $quote) {
                $byBook[$bookKey][$side->value] = (int) $quote['price'];
            }
        }

        return MarketConsensus::fairProbability(
            $byBook,
            $prediction->side->value,
            array_map(fn ($s) => $s->value, $prediction->market->sides()),
            $method,
        );
    }

    /**
     * Whether the model behind this prediction has earned the right to pick
     * in this market, read off the version that made it.
     */
    private function verdictFor(Prediction $prediction): Verdict
    {
        $stored = $prediction->modelVersion?->holdout_metrics['verdict'] ?? null;

        return $stored === null ? Verdict::Insufficient : (Verdict::tryFrom($stored) ?? Verdict::Insufficient);
    }

    /**
     * Pull a model's probability toward the market's, by as much as
     * it has not earned the right to disagree.
     *
     * Two things reduce that right, and they are different. The tier is
     * about the model: only a market that beats the closing line may
     * claim an edge, and none currently does. The team sheet is about
     * this particular game: a rating that beats the close across ordinary
     * fixtures was never measured on one missing a quarterback, so a
     * lopsided absence list shrinks even a proven market. Left unshrunk, the calibrated tier put Philadelphia
     * at 62.3% against a no-vig market of 33.6% and the board offered it
     * as a 27.8% edge. Real edges run one to three points; a model that
     * loses to the close saying the book is twenty-eight points wrong is
     * the model being wrong, dressed as a recommendation.
     *
     * Calibration does not protect against this. It says the stated
     * probabilities are right on average, and the biggest disagreements
     * with a sharper source are exactly where an average is least
     * comforting.
     *
     * With no market price there is nothing to shrink toward, so the
     * model's own number stands and the edge is null anyway.
     */
    private function shrinkToMarket(float $modelProb, ?float $fairProb, Verdict $verdict, Event $event): float
    {
        if ($fairProb === null) {
            return $modelProb;
        }

        /*
         * A market that beats the close has earned the right to its own
         * number, so it starts at full trust and is not shrunk by the
         * tier at all. What it has not earned is an exemption from the
         * team sheet: it was measured across ordinary games, and a game
         * missing a quarterback is not one of those.
         */
        $shrink = $verdict->claimsEdge()
            ? 1.0
            : (float) config('edge.value.unproven_edge_shrink', 0.25);

        /*
         * And further still when one side is missing more players than
         * the other.
         *
         * The engine's probability is a power rating that has never seen
         * an injury report; the market reprices within minutes of a
         * quarterback being ruled out. So a lopsided absence list is
         * information the price holds and this number does not, and the
         * correct response is to disagree with the price less - not to
         * invent a points-per-injury coefficient nobody measured.
         *
         * Symmetric absences barely move this, which is deliberate: every
         * NFL game has players out, and treating ordinary attrition as a
         * crisis would shrink every pick to nothing.
         */
        $imbalance = $this->availability->weightedImbalance($event);

        if ($imbalance > 0) {
            $weight = (float) config('edge.value.absence_imbalance_weight', 0.25);
            $shrink /= 1.0 + ($weight * $imbalance);
        }

        return $fairProb + ($shrink * ($modelProb - $fairProb));
    }

    private function minimumAcceptable(float $modelProb, float $threshold): ?int
    {
        // A threshold at or above the model's own probability can never be
        // cleared by any price, and the converter says so by throwing.
        if ($modelProb - $threshold <= 0.0) {
            return null;
        }

        return OddsConverter::minimumAcceptableAmerican($modelProb, $threshold);
    }

    /** One plain sentence for the card. */
    private function sentence(
        Recommendation $recommendation,
        ?PassReason $passReason,
        ShoppedLine $shopped,
        ?StakeDecision $stake,
        ?float $edgeAtPrice,
    ): string {
        if ($recommendation === Recommendation::Bet && $stake !== null) {
            return sprintf(
                '%s at %s on %s, %.1f%% over the break-even rate.',
                Money::format($stake->stakeCents),
                OddsConverter::formatAmerican((int) $shopped->pricingBookPriceAmerican),
                $shopped->pricingBookTitle ?? 'your book',
                ($edgeAtPrice ?? 0.0) * 100,
            );
        }

        return mb_substr((string) $passReason?->detail($shopped->pricingBookTitle), 0, 255);
    }

    /**
     * Rank the picks two ways and store both, so the board can switch
     * ordering without rescanning and without the numbers moving.
     *
     * Ordering is not selection. Nothing that failed the value test is
     * promoted by either ranking; these only order what already passed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function ranked(array $rows): array
    {
        $picks = array_keys(array_filter(
            $rows,
            fn (array $row) => $row['recommendation'] === Recommendation::Bet->value,
        ));

        // Most likely first. Sorting by probability alone would put -300
        // favourites on top, which is why it only ever orders picks that
        // already cleared the edge test.
        $byProbability = $picks;
        usort($byProbability, fn ($a, $b) => $rows[$b]['model_prob'] <=> $rows[$a]['model_prob']);

        $byValue = $picks;
        usort($byValue, fn ($a, $b) => $rows[$b]['edge_at_price'] <=> $rows[$a]['edge_at_price']);

        foreach ($byProbability as $position => $index) {
            $rows[$index]['probability_rank'] = $position + 1;
        }

        foreach ($byValue as $position => $index) {
            $rows[$index]['value_rank'] = $position + 1;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persist(ValueScan $scan, array $rows): void
    {
        DB::transaction(function () use ($scan, $rows): void {
            $picks = 0;

            foreach ($rows as $row) {
                $consensus = $row['_consensus'];
                $shopped = $row['_shopped'];
                unset($row['_consensus'], $row['_shopped']);

                ValueBet::create($row);

                if ($row['recommendation'] === Recommendation::Bet->value) {
                    $picks++;
                }

                $this->recordConsensus($row, $consensus, $shopped);
            }

            $scan->forceFill([
                'candidates' => count($rows),
                'picks' => $picks,
            ])->save();
        });
    }

    /**
     * Keep the fair price itself, not just the decision that used it.
     *
     * consensus_prices is append-only history of what the market thought
     * over time, which is what makes it possible to ask later whether the
     * model was wrong or the market moved.
     *
     * @param  array<string, mixed>  $row
     */
    private function recordConsensus(array $row, ?ConsensusPrice $consensus, ShoppedLine $shopped): void
    {
        if ($consensus === null) {
            return;
        }

        ConsensusPriceRow::create([
            'event_id' => $row['event_id'],
            'market' => $row['market'],
            'side' => $row['side'],
            'line' => $row['line'],
            'method' => $consensus->method->value,
            'fair_prob' => $consensus->fairProbability,
            'implied_sum' => $consensus->impliedSum,
            'book_count' => $consensus->bookCount,
            'best_book_id' => $shopped->bestMarketBookId,
            'best_price_american' => $shopped->bestMarketPriceAmerican,
            'based_on_pulled_at' => $shopped->pricePulledAt ?? now(),
            'computed_at' => now(),
            'created_at' => now(),
        ]);
    }
}
