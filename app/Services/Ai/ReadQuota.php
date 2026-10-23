<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\ReadView;
use App\Models\User;
use App\Models\VigilRead;
use App\Services\Entitlements\Entitlements;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * How many Vigil Reads a customer gets.
 *
 * Two different limits, which are easy to confuse and must not be:
 *
 *  - **Viewing.** §4 gives the free plan one Read a day. Reads attached
 *    to the board are generated once and shared by everybody, so this is
 *    not about cost - it is what the free plan is.
 *  - **Generating.** §6 rate limits the Reads a customer triggers on
 *    their own bets and parlays, per tier. Those cost money on every
 *    call, which is why the ceiling is much higher but still a ceiling.
 *
 * Re-opening a Read already seen is free, for ever. A customer who reads
 * today's explanation twice has not used two Reads, and a counter that
 * could not tell the difference would be a worse product than no limit.
 */
class ReadQuota
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /* ----------------------------------------------------------------
     | Viewing
     ---------------------------------------------------------------- */

    /** Null means no limit. */
    public function viewAllowance(?User $user): ?int
    {
        return $this->entitlements->limit($user, 'reads_per_day');
    }

    public function viewsUsedToday(User $user): int
    {
        return ReadView::query()
            ->where('user_id', $user->id)
            ->whereDate('viewed_on', today())
            ->count();
    }

    /** Null means no limit. */
    public function viewsRemaining(?User $user): ?int
    {
        $allowance = $this->viewAllowance($user);

        if ($allowance === null || $user === null) {
            return $allowance;
        }

        return max(0, $allowance - $this->viewsUsedToday($user));
    }

    /**
     * May this customer see this Read, and record it if so.
     *
     * One method rather than a check and a separate write, because a
     * caller that asked and forgot to record would hand out unlimited
     * Reads and nothing would ever fail.
     */
    public function claim(?User $user, ?VigilRead $read): bool
    {
        if ($read === null) {
            return false;
        }

        if ($user === null) {
            // A signed-out visitor is shown nothing. The sample board on
            // the marketing page does not carry Reads.
            return false;
        }

        if ($this->viewAllowance($user) === null) {
            return true;
        }

        $already = ReadView::query()
            ->where('user_id', $user->id)
            ->where('vigil_read_id', $read->id)
            ->whereDate('viewed_on', today())
            ->exists();

        if ($already) {
            return true;
        }

        if ($this->viewsRemaining($user) === 0) {
            return false;
        }

        /*
         * Raced by two tabs, the unique key decides and the loser is
         * treated as a repeat view rather than an error - it is the same
         * Read either way.
         */
        try {
            ReadView::create([
                'user_id' => $user->id,
                'vigil_read_id' => $read->id,
                'viewed_on' => today(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }
        }

        return true;
    }

    /* ----------------------------------------------------------------
     | Generating
     ---------------------------------------------------------------- */

    public function generateAllowance(?User $user): int
    {
        $tier = $this->entitlements->tier($user);

        return (int) config('ai.rate_limits.'.$tier, config('ai.rate_limits.free', 1));
    }

    public function generationsToday(User $user): int
    {
        return AiUsage::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', today())
            ->count();
    }

    /**
     * Whether this customer may trigger another Read right now.
     *
     * Counted from ai_usage rather than from a cache, because ai_usage is
     * the row that proves money was spent. A limit enforced against a
     * counter that can be cleared is a limit that can be cleared.
     */
    public function mayGenerate(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->generationsToday($user) < $this->generateAllowance($user);
    }

    public function generationsRemaining(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return max(0, $this->generateAllowance($user) - $this->generationsToday($user));
    }

    /** Reads this customer has already been shown today, newest first. */
    public function seenToday(User $user): Collection
    {
        return ReadView::query()
            ->where('user_id', $user->id)
            ->whereDate('viewed_on', today())
            ->orderByDesc('id')
            ->pluck('vigil_read_id');
    }
}
