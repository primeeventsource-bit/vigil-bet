<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The admin area: who may see it, and the one thing it stores.
 *
 * `is_admin` is a column rather than a role table because there are two
 * kinds of person in this product - a customer and whoever runs it - and
 * a permissions system for two cases is a permissions system nobody
 * maintains. If that changes, this becomes a join and the middleware is
 * the only thing that has to know.
 *
 * `feature_flags` is the only mutable admin-owned table, and every change
 * to it is audited. §11 asks for flags; the reason they are in the
 * database rather than in config is that turning something off at two in
 * the morning should not need a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();

            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);

            /*
             * What this flag does, in a sentence, written by whoever
             * added it. A flag nobody can explain is a flag nobody dares
             * turn off, which is how they accumulate.
             */
            $table->string('description');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('feature_flags');
    }
};
