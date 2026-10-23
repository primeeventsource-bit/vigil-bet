<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\SelfExclusion as SelfExclusionModel;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Locking a customer out, at their own request.
 *
 * §9: 7, 30 or 90 days, and it cannot be undone early. This class can
 * start one and answer whether one is running. It has no method that ends
 * one, and the model has no column it could write to if it did - which is
 * deliberate, because the person a self-exclusion protects is exactly the
 * person who will come looking for the way out.
 *
 * Nor is there an admin action to shorten one. An operator who can be
 * talked into lifting it is the same hole with an extra step.
 */
class SelfExclusion
{
    /**
     * The sentence a customer has to be shown before it takes effect.
     *
     * Stored with the exclusion, because this is the one decision in the
     * product somebody may later say they did not understand, and the
     * answer should not depend on what the page happens to say today.
     */
    public function acknowledgement(int $days): string
    {
        return "I understand that I will be locked out of {$this->brand()} for {$days} days, "
            .'that this cannot be shortened or cancelled by me or by support, '
            .'and that I will not be able to sign in until it ends.';
    }

    /**
     * Start one.
     *
     * @throws InvalidArgumentException on a duration nobody offered
     */
    public function start(User $user, int $days): SelfExclusionModel
    {
        if (! in_array($days, SelfExclusionModel::DURATIONS, true)) {
            /*
             * A fixed set rather than a free number. A field that
             * accepts any value accepts 1, and a one-day exclusion is a
             * button that lets somebody feel they have done something.
             */
            throw new InvalidArgumentException('Self-exclusion is offered for 7, 30 or 90 days.');
        }

        $existing = $this->active($user);

        if ($existing !== null) {
            /*
             * Already excluded. Returning the existing one rather than
             * stacking a new one, because a second exclusion started
             * during the first could only ever be shorter - and a
             * shorter exclusion must never replace a longer.
             */
            return $existing;
        }

        $exclusion = SelfExclusionModel::create([
            'user_id' => $user->id,
            'days' => $days,
            'starts_at' => now(),
            'ends_at' => now()->addDays($days),
            'acknowledged' => $this->acknowledgement($days),
            'created_at' => now(),
        ]);

        /*
         * Audited. Not because an operator did it - the customer did -
         * but because support will be asked about it, and the answer has
         * to come from a row rather than from memory.
         */
        Audit::write(
            'self_exclusion.started',
            $user,
            null,
            ['days' => $days, 'ends_at' => $exclusion->ends_at->toIso8601String()],
            'Requested by the customer',
        );

        return $exclusion;
    }

    public function active(?User $user): ?SelfExclusionModel
    {
        if ($user === null) {
            return null;
        }

        return SelfExclusionModel::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('ends_at')
            ->first();
    }

    public function isExcluded(?User $user): bool
    {
        return $this->active($user) !== null;
    }

    /** Every exclusion this customer has had, for their own record. */
    public function history(User $user): Collection
    {
        return SelfExclusionModel::query()
            ->where('user_id', $user->id)
            ->orderByDesc('starts_at')
            ->get();
    }

    private function brand(): string
    {
        return (string) config('brand.name');
    }
}
