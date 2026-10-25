<?php

declare(strict_types=1);

namespace App\Services\Parlay;

use App\Modeling\Odds\OddsConverter;
use App\Modeling\Odds\Parlay as ParlayMath;
use App\Models\VlBoardEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * The slip a customer is building.
 *
 * Held in the session rather than the database on purpose: a slip being
 * assembled is not a decision, and writing every half-built combination
 * to a table would bury the parlays somebody actually weighed under the
 * ones they were idly poking at.
 *
 * It used to hold board entry ids, and that was the whole of the bug the
 * builder had. A board entry is a thing the engine judged, and the
 * controller would only accept one whose market the engine had proven -
 * so with 24,610 candidates evaluated and none proven, there was nothing
 * a customer could put on a slip and nothing on the page to click. The
 * engine refusing to sell a pick is not a reason a customer cannot record
 * their own.
 *
 * So a leg is now a selection: a game, a market, a side, a line and the
 * price taken. That is all settlement needs and all a customer is
 * claiming. A selection that happens to correspond to a board entry
 * carries its id too, which is what lets the sheet say whether the engine
 * agreed - but nothing requires one.
 */
class ParlaySlip
{
    private const KEY = 'parlay.slip';

    public const MAX_LEGS = 8;

    /**
     * The selections on the slip, in the order they were added.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function selections(): Collection
    {
        return collect((array) Session::get(self::KEY, []))
            ->filter(static fn ($leg): bool => is_array($leg) && isset($leg['key']))
            ->values();
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return $this->selections()->pluck('key')->all();
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function count(): int
    {
        return $this->selections()->count();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * The key that identifies one selection.
     *
     * A game, a market, a side and a line. The line is part of it because
     * Over 44.5 and Over 47.5 are different bets, and leaving it out
     * would silently treat the second click as a duplicate of the first.
     */
    public static function keyFor(int $eventId, string $market, ?string $side, float|string|null $line): string
    {
        return implode('|', [
            $eventId,
            $market,
            $side ?? '',
            $line === null || $line === '' ? '' : (string) (float) $line,
        ]);
    }

    /**
     * Add a selection.
     *
     * Returns a refusal sentence, or null on success. Every refusal is
     * something a customer would otherwise discover from a payout that
     * made no sense, and each is said out loud rather than swallowed -
     * a click that does nothing is the worst outcome of the three.
     *
     * @param  array<string, mixed>  $selection
     */
    public function add(array $selection): ?string
    {
        $key = (string) ($selection['key'] ?? '');

        if ($key === '') {
            return 'That selection could not be identified.';
        }

        if ($this->has($key)) {
            return 'That selection is already on the slip.';
        }

        $current = $this->selections();

        if ($current->count() >= self::MAX_LEGS) {
            return 'A slip holds '.self::MAX_LEGS.' legs. Beyond that the hold swamps any edge.';
        }

        /*
         * Two legs on the same game and market contradict each other
         * rather than correlating: the Over and the Under cannot both
         * land, so a parlay holding both is a guaranteed loser the
         * combined price would never admit to.
         */
        $clash = $current->first(
            fn (array $leg): bool => (int) $leg['event_id'] === (int) $selection['event_id']
                && (string) $leg['market'] === (string) $selection['market']
        );

        if ($clash !== null) {
            return 'You already have '.$clash['selection'].' in that market. Two sides of one market cannot both win.';
        }

        Session::put(self::KEY, $current->push($selection)->all());

        return null;
    }

    public function remove(string $key): void
    {
        Session::put(
            self::KEY,
            $this->selections()->reject(fn (array $leg): bool => $leg['key'] === $key)->values()->all()
        );
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * The combined price of what is on the slip.
     *
     * Our own product of the legs, which is not what a sportsbook will
     * offer: a book prices a parlay with its margin compounded over every
     * leg, so the real ticket comes back worse. Shown as an estimate and
     * never used to settle - the accepted price the customer types in is
     * what a payout is worked out from.
     */
    public function combinedPriceAmerican(): ?int
    {
        $prices = $this->selections()
            ->pluck('price_american')
            ->filter(static fn ($price): bool => $price !== null)
            ->map(static fn ($price): int => (int) $price)
            ->all();

        return $prices === [] ? null : ParlayMath::americanPrice($prices);
    }

    /**
     * What a stake would return at the combined price, stake included.
     */
    public function estimatedReturnCents(int $stakeCents): ?int
    {
        $price = $this->combinedPriceAmerican();

        if ($price === null || $stakeCents < 1) {
            return null;
        }

        return (int) round($stakeCents * OddsConverter::americanToDecimal($price));
    }

    /**
     * The legs in the shape Edge Lab's judge expects.
     *
     * A selection with no engine event id is dropped rather than sent
     * with a null: the judge would reject the whole slip and the customer
     * would be told their parlay was unavailable rather than which leg
     * was the problem.
     *
     * @return array<int, array{event_id:int, market:string, side:string}>
     */
    public function legsForJudge(): array
    {
        return $this->selections()
            ->filter(static fn (array $leg): bool => ! empty($leg['event_id']) && ! empty($leg['market']) && ! empty($leg['side']))
            ->map(static fn (array $leg): array => [
                'event_id' => (int) $leg['event_id'],
                'market' => (string) $leg['market'],
                'side' => (string) $leg['side'],
            ])
            ->values()
            ->all();
    }

    /**
     * The legs in the shape ParlayLedger stores.
     *
     * @return array<int, array<string, mixed>>
     */
    public function legsForLedger(): array
    {
        return $this->selections()
            ->map(static fn (array $leg): array => [
                'edge_lab_event_id' => $leg['event_id'] ?? null,
                'board_entry_id' => $leg['board_entry_id'] ?? null,
                'sport' => (string) $leg['sport'],
                'market' => (string) $leg['market'],
                'side' => $leg['side'] ?? null,
                'line' => $leg['line'] ?? null,
                'selection' => (string) $leg['selection'],
                'price_american' => (int) $leg['price_american'],
                'model_probability' => $leg['model_probability'] ?? null,
                'model_version' => $leg['model_version'] ?? null,
                'model_edge' => $leg['model_edge'] ?? null,
                'model_agreement' => $leg['model_agreement'] ?? 'no_prediction',
            ])
            ->values()
            ->all();
    }

    /**
     * Board entries for the legs that came from the engine's board.
     *
     * Kept for the pages that still speak in board entries. A slip built
     * from Today's Games has none, which is the point.
     *
     * @return Collection<int, VlBoardEntry>
     */
    public function entries(): Collection
    {
        $ids = $this->selections()
            ->pluck('board_entry_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        $entries = VlBoardEntry::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $entries->get($id))->filter()->values();
    }
}
