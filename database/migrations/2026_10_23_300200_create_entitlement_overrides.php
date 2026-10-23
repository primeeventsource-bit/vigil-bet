<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A feature granted to one customer outside their plan, by a person.
 *
 * Support needs this - comping a feature while a billing problem is
 * sorted out, or honouring something that was promised. What it must not
 * become is a second, invisible plan system, so every row has an actor,
 * a reason and an expiry, and every write to this table is audited.
 *
 * `granted` can be false. Revoking a feature a plan would otherwise give
 * is rarer and more serious than granting one, and doing it through the
 * same audited table is better than doing it by quietly editing a tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 48);
            $table->boolean('granted')->default(true);

            // Required. An override nobody can explain later is one
            // nobody can safely remove later.
            $table->string('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Nullable means permanent, which is sometimes right and is
             * always worth being deliberate about - most comps should
             * expire, and a null here is a decision rather than a
             * default nobody noticed.
             */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'feature']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_overrides');
    }
};
