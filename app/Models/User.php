<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Security\Totp;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

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
            // Encrypted, not hashed: both have to be readable again, the
            // secret to check a code against and the codes to show once.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
        ];
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
