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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // The last timestep a code was accepted for, so one code cannot
            // be replayed inside its own thirty second window.
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_timestep',
            ]);
        });
    }
};
