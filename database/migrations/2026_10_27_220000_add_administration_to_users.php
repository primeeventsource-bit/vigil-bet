<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The columns an operator needs and the product never had
|--------------------------------------------------------------------------
|
| Suspension is the one that matters. legal/terms.blade.php has said
| "We may suspend or close an account that..." since launch, and there
| has never been a column, a route or a button behind it. A promise in
| the terms that the code cannot keep is worse than no promise.
|
| Note what suspension is not. Self-exclusion is a customer's own
| decision, enforced by EnforceSelfExclusion, and deliberately has no
| way back - SelfExclusion has no method to end one and this migration
| does not give it one. Suspension is the operator's decision about an
| account, and is reversible by an operator. Two different things that
| happen to both end in a locked door.
|
| last_login_at is a plain column rather than derived from the audit log
| because the user list sorts and filters on it, and a list that joins
| an append-only log to sort 30 rows is a list that gets slower every
| month.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestampTz('suspended_at')->nullable()->after('is_admin');
            }

            if (! Schema::hasColumn('users', 'suspended_reason')) {
                $table->string('suspended_reason')->nullable()->after('suspended_at');
            }

            if (! Schema::hasColumn('users', 'suspended_by')) {
                // nullOnDelete rather than cascade: an operator account
                // being closed must never take the record of what they
                // did with it.
                $table->foreignId('suspended_by')->nullable()->after('suspended_reason')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestampTz('last_login_at')->nullable()->after('suspended_by');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            // Partial-ish: the admin list filters on "currently
            // suspended", which is a small slice of a table that is
            // mostly nulls.
            $table->index('suspended_at');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['suspended_at']);
            $table->dropIndex(['last_login_at']);
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspended_at', 'suspended_reason', 'last_login_at']);
        });
    }
};
