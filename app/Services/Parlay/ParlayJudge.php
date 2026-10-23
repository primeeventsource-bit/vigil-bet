<?php

declare(strict_types=1);

namespace App\Services\Parlay;

use App\Models\ParlayGrade;
use App\Models\User;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\EdgeLabException;
use Illuminate\Support\Facades\Log;

/**
 * Sends a slip to Edge Lab's parlay judge and stores what came back.
 *
 * Vigil computes nothing here. The correlation-aware probability, the
 * fair price, the book's hold and the shaded edge are all Edge Lab's
 * work, and multiplying leg probabilities together on this side would
 * produce a number that looked like a parlay probability and was not one
 * - legs on the same game are correlated, and ignoring that is how a
 * parlay product flatters itself.
 *
 * When the judge cannot be reached the grade is stored with the reason
 * rather than with an empty result. A slip that silently came back
 * blank would read as "no value here" instead of "nobody answered".
 */
class ParlayJudge
{
    public function __construct(private readonly EdgeLabClient $client) {}

    /** @param  array<int, array<string, mixed>>  $legs */
    public function judge(User $user, array $legs, ?float $shade = null): ParlayGrade
    {
        if ($legs === []) {
            throw new \RuntimeException('There is nothing on the slip to grade.');
        }

        try {
            $result = $this->client->evaluateParlay($legs, $shade);
        } catch (EdgeLabException $e) {
            Log::warning('Parlay judge unavailable', [
                'user_id' => $user->id,
                'legs' => count($legs),
                'reason' => $e->getMessage(),
            ]);

            return $this->store($user, $legs, null, $e->getMessage());
        }

        return $this->store($user, $legs, $result, null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @param  array<string, mixed>|null  $result
     */
    private function store(User $user, array $legs, ?array $result, ?string $unavailable): ParlayGrade
    {
        /*
         * Only the columns a page sorts on are lifted out. Everything
         * Edge Lab said is kept in `result` verbatim, because a grade
         * reproduced from a subset of its own fields is not the grade
         * the customer saw.
         */
        return ParlayGrade::create([
            'user_id' => $user->id,
            'legs' => $legs,
            'result' => $result,
            'leg_count' => count($legs),
            'win_probability' => $this->number($result['win_probability'] ?? $result['probability'] ?? null),
            'fair_price_american' => $this->integer($result['fair_price_american'] ?? null),
            'offered_price_american' => $this->integer($result['offered_price_american'] ?? $result['price_american'] ?? null),
            'book_hold' => $this->number($result['hold'] ?? $result['book_hold'] ?? null),
            'shaded_edge' => $this->number($result['shaded_edge'] ?? $result['edge'] ?? null),
            'verdict' => is_string($result['verdict'] ?? null) ? $result['verdict'] : null,
            'unavailable_reason' => $unavailable,
            'created_at' => now(),
        ]);
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value))
            ? (int) $value
            : null;
    }
}
