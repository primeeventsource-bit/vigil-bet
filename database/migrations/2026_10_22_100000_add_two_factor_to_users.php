<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second factor on a customer account.
 *
 * The secret and the recovery codes are encrypted at rest by an Eloquent
 * cast rather than hashed, because both have to be readable again - the
 * secret to check a code against, the codes to show once when they are
 * generated. That makes them the only secrets in this database that can be
 * read back, which is exactly why they are the only ones worth encrypting;
 * the password is hashed and never needs to be.
 *
 * `confirmed_at` is separate from the secret on purpose. A secret exists
 * from the moment setup begins, and enrolment is finished only once a code
 * generated from it has been proved to work. Without the distinction a
 * customer can lock themselves out with a QR code they never scanned.
 *
 * ---
 *
 * Column-guarded, which is unusual, and deliberate.
 *
 * The engine had its own copy of this migration, adding these same four
 * columns to its own users table. When the two applications merged, the
 * database that was kept was the engine's - it holds the odds history -
 * and these columns are therefore already on it, added by a migration
 * file that is no longer in this repository.
 *
 * So this migration has to do two different things: create the columns on
 * a fresh database, and notice they are already there on the one in
 * production. Guarding on the columns rather than on the environment is
 * what makes those the same code path, and keeps `migrate` safe to run
 * from any starting point - which is the only property that matters here.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const COLUMNS = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_timestep',
    ];

    public function up(): void
    {
        $missing = $this->missing();

        if ($missing === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($missing) {
            if (in_array('two_factor_secret', $missing, true)) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }

            if (in_array('two_factor_recovery_codes', $missing, true)) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }

            if (in_array('two_factor_confirmed_at', $missing, true)) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }

            // The last timestep a code was accepted for, so one code cannot
            // be replayed inside its own thirty second window.
            if (in_array('two_factor_last_timestep', $missing, true)) {
                $table->unsignedBigInteger('two_factor_last_timestep')->nullable()
                    ->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_diff(self::COLUMNS, $this->missing()));

        if ($present === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    /** @return array<int, string> */
    private function missing(): array
    {
        return array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => ! Schema::hasColumn('users', $column),
        ));
    }
};
