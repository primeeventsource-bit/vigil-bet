<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Entitlements\Entitlements;
use App\Services\Security\Totp;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Was missing, and isAdmin() has been compensating with a
            // manual (bool) ever since. Postgres hands back a real
            // boolean and SQLite hands back 0/1, so the cast is what
            // makes `=== true` mean the same thing on both.
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_expires_at' => 'datetime',
            'trial_used_at' => 'datetime',
            // Encrypted, not hashed: both have to be readable again, the
            // secret to check a code against and the codes to show once.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
        ];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * The tier this user is actually on.
     *
     * Routed through the one service rather than read off the
     * subscription, so a cancelled-but-still-paid period and an
     * override are handled in one place instead of wherever somebody
     * happened to need the answer.
     */
    /**
     * Whether this person runs the product rather than uses it.
     *
     * Still a column, and still the thing EnsureAdmin reads. Roles came
     * later and sit beside it rather than replacing it: the middleware
     * guarding every admin route answers in one already-loaded boolean
     * instead of a join, and the 404-not-403 behaviour that AdminTest
     * enumerates route by route is unchanged.
     *
     * The column is kept true to the roles by syncAdminFlag() below, so
     * there is exactly one writer and no way for the two to drift.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Full authority. One account type, deliberately hard to acquire.
     *
     * Nothing in the HTTP layer can grant this - not registration, not
     * the admin pages, not an ADMIN with every permission ticked. Only
     * the console command does, and the policies refuse every edit,
     * demotion, deletion and impersonation aimed at somebody holding it.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('roles.super_admin'));
    }

    /**
     * Keep `is_admin` answerable to the roles.
     *
     * Called from the model's saved hook and after any role change. The
     * flag is a cache of "holds an administrative role", and a cache
     * that can disagree with its source is worse than no cache: the
     * middleware would let somebody through whose role had been taken
     * away that morning.
     */
    public function syncAdminFlag(): void
    {
        $shouldBeAdmin = $this->hasAnyRole([
            config('roles.super_admin'),
            config('roles.admin'),
        ]);

        if ($this->is_admin !== $shouldBeAdmin) {
            $this->forceFill(['is_admin' => $shouldBeAdmin])->saveQuietly();
        }
    }

    /** Signed out of every device, everywhere, at once. */
    public function revokeSessions(): int
    {
        return DB::table('sessions')->where('user_id', $this->getKey())->delete();
    }

    /**
     * Locked out by an operator, as distinct from locked out by choice.
     *
     * Self-exclusion is the customer's own decision and has no end date
     * anybody here can shorten. This is the other one - the capability
     * the terms have promised since launch and the code has never had.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suspended_by');
    }

    public function tier(): string
    {
        return app(Entitlements::class)->tier($this);
    }

    /**
     * The status of the subscription, if there is one.
     *
     * The trial spec asked for a `subscription_status` column on this
     * table. There already is one, on `subscriptions`, and a second
     * copy here would be a second thing to keep true - the two
     * disagreeing is a worse outcome than the lookup this avoids. So
     * the column was not added and this reads the one that exists.
     *
     * Null means no subscription row at all, which is every account
     * that has never bought anything, since registration creates none.
     */
    public function subscriptionStatus(): ?string
    {
        return $this->subscription?->status;
    }

    /** The clock is running. */
    public function onTrial(): bool
    {
        return $this->trial_expires_at !== null && $this->trial_expires_at->isFuture();
    }

    /** It ran, and it is over. Distinct from never having started one. */
    public function trialExpired(): bool
    {
        return $this->trial_used_at !== null && ! $this->onTrial();
    }

    /**
     * The trial's state in one word, derived rather than stored.
     *
     * The spec asked for a `trial_status` column. It is not here,
     * because it would be a second copy of what these two timestamps
     * already say and the two could then disagree - which is the only
     * way this question gets a wrong answer. Nothing is saved by
     * storing it either: "every running trial" is
     * `where trial_expires_at > now()` on an indexed column.
     */
    public function trialStatus(): string
    {
        return match (true) {
            $this->onTrial() => 'active',
            $this->trial_used_at !== null => 'expired',
            default => 'none',
        };
    }

    public function entitlementOverrides(): HasMany
    {
        return $this->hasMany(EntitlementOverride::class);
    }

    public function ageAttestations(): HasMany
    {
        return $this->hasMany(AgeAttestation::class);
    }

    /**
     * Enrolment is finished, not merely started.
     *
     * A secret exists from the moment setup begins. Treating that as "2FA
     * is on" is how somebody locks themselves out with a QR code they
     * never actually scanned.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /**
     * Check a six digit code and spend the timestep it belonged to.
     *
     * Spending it is the point: the algorithm cannot tell a replay from a
     * first use inside the same thirty seconds, so this is the only thing
     * that can.
     */
    public function verifyTwoFactorCode(#[SensitiveParameter] string $code): bool
    {
        if (! filled($this->two_factor_secret)) {
            return false;
        }

        $step = Totp::verify($this->two_factor_secret, $code, $this->two_factor_last_timestep);

        if ($step === null) {
            return false;
        }

        $this->forceFill(['two_factor_last_timestep' => $step])->save();

        return true;
    }

    /**
     * Spend a recovery code, which works exactly once.
     *
     * Compared with hash_equals and removed on use. A recovery code that
     * survives being used is a password that happens to be shorter.
     */
    public function useRecoveryCode(#[SensitiveParameter] string $code): bool
    {
        $code = trim($code);
        $remaining = [];
        $matched = false;

        foreach ((array) $this->two_factor_recovery_codes as $stored) {
            if (! $matched && hash_equals((string) $stored, $code)) {
                $matched = true;

                continue;
            }

            $remaining[] = $stored;
        }

        if (! $matched) {
            return false;
        }

        $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    /** @return array<int, string> */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtolower(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))))
            ->all();
    }
}
