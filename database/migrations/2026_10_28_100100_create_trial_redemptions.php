<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| One trial per person, recorded where an account change cannot erase it
|--------------------------------------------------------------------------
|
| The columns on `users` say what happened to an account. This table says
| what happened to a person, and it has to survive everything that can
| happen to an account - an address change, a suspension, a deletion that
| this product does not even offer but a future one might.
|
| WHAT IS STORED, AND WHAT DELIBERATELY IS NOT.
|
| Not the email address. `email_hash` is an HMAC of the normalised
| address keyed on APP_KEY, so the table answers "has this person had a
| trial" without being readable as a mailing list. Anybody who takes a
| copy of it learns nothing about who signed up.
|
| Not the IP address either. `ip_hash` is the same construction. The
| product can still see that six trials came from one address this
| morning without holding a sixth copy of somebody's IP - and it already
| holds one forever in age_attestations, with no retention policy, which
| is a problem this table declines to make larger.
|
| Country, region and ASN are kept in the clear, because coarse location
| is the part a human actually reviews and hashing it would make it
| useless for the only purpose it has.
|
| Append-only, in the manner of audit_log and billing_events, enforced
| by a trigger on Postgres. The entire value of this table is that a row
| cannot be quietly removed to grant somebody a second turn - and the
| most likely person to try is an administrator with a support ticket
| open, acting in good faith. There is a proper way to do that: the
| admin trials page grants an extra trial explicitly, with a reason, in
| the audit log.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Unique: this is the control. One trial per identity.
            $table->string('email_hash', 64)->unique();

            /*
             * Nullable and nullOnDelete. The redemption outlives the
             * account deliberately - if the user row goes, the fact
             * that this identity has had its turn must not go with it.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestampTz('redeemed_at');

            // The GeoIP signal. Coarse, nullable, and never a reason to
            // refuse anything - Geo fails open by design.
            $table->string('country', 2)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('asn', 64)->nullable();

            $table->string('ip_hash', 64)->nullable();

            /*
             * Set when an administrator grants a trial to somebody who
             * had already used theirs. The row is still here, so the
             * history is still true; this says a human decided to make
             * an exception and the audit log says who and why.
             */
            $table->boolean('granted_by_operator')->default(false);

            $table->timestampTz('created_at');

            $table->index('redeemed_at');
            // "How many trials from this network today" is the whole of
            // the review signal, and it is a scan without this.
            $table->index(['ip_hash', 'redeemed_at']);
            $table->index(['country', 'redeemed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_trial_redemptions_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'trial_redemptions is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trial_redemptions_append_only
                BEFORE DELETE ON trial_redemptions
                FOR EACH ROW EXECUTE FUNCTION vigil_trial_redemptions_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trial_redemptions_append_only ON trial_redemptions');
            DB::unprepared('DROP FUNCTION IF EXISTS vigil_trial_redemptions_append_only()');
        }

        Schema::dropIfExists('trial_redemptions');
    }
};
