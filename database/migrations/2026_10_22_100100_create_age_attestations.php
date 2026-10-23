<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEND ONLY. Proof that the age gate was applied, and nothing more.
 *
 * What is deliberately not here is the date of birth. The spec says store
 * the attestation result and the year, and the reason is worth writing
 * down rather than inferring: a full date of birth is identity-document
 * material. Holding it turns a leak of this table from an embarrassment
 * into an identity-theft kit, and it buys nothing - the year is enough to
 * show the gate was applied, enough to re-check it if the threshold moves,
 * and enough to answer a regulator asking what we knew and when.
 *
 * The threshold in force at the time is stored beside the result for the
 * same reason. If the minimum age ever changes, a row that only said
 * "passed" would silently mean something different than it did when it was
 * written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_attestations', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable, because a refusal is worth recording too and there
             * is no user to hang it on - the account is never created. A
             * table that only held the people who got in would make "we
             * check" a claim rather than a record.
             */
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // The year only. Never the month, never the day.
            $table->unsignedSmallInteger('birth_year');
            $table->unsignedTinyInteger('minimum_age');
            $table->boolean('passed');
            // The box they ticked, separately from the arithmetic. Both are
            // required to sign up, and a regulator asks about both.
            $table->boolean('attested');

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['passed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_attestations');
    }
};
