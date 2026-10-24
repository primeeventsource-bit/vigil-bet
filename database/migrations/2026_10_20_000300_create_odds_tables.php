<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // APPEND ONLY. Every pull inserts new rows; a historical quote is
        // never updated or deleted. See the append-only guard migration.
        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->string('market', 16);
            $table->string('side', 8);
            $table->foreignId('selection_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->decimal('line', 6, 2)->nullable();
            $table->integer('price_american');
            $table->decimal('price_decimal', 10, 4);
            $table->timestampTz('api_last_update_at')->nullable();
            $table->timestampTz('pulled_at');
            $table->unsignedBigInteger('api_request_id')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'market', 'side', 'pulled_at'], 'odds_snapshots_event_market_idx');
            $table->index(['book_id', 'pulled_at']);
            $table->index('pulled_at');
        });

        // Derived cache of the first and last quote per event/book/market/side.
        // Closing = last snapshot strictly before kickoff.
        Schema::create('event_line_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('market', 16);
            $table->string('side', 8);
            $table->decimal('line_open', 6, 2)->nullable();
            $table->integer('price_open_american')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->unsignedBigInteger('opening_snapshot_id')->nullable();
            $table->decimal('line_close', 6, 2)->nullable();
            $table->integer('price_close_american')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closing_snapshot_id')->nullable();
            $table->timestampTz('computed_at');

            $table->unique(['event_id', 'book_id', 'market', 'side'], 'line_markers_unique');
        });

        // APPEND ONLY. No-vig fair probability across books at a point in time.
        Schema::create('consensus_prices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('market', 16);
            $table->string('side', 8);
            $table->decimal('line', 6, 2)->nullable();
            $table->string('method', 16);
            $table->decimal('fair_prob', 8, 6);
            $table->decimal('implied_sum', 8, 6);
            $table->unsignedSmallInteger('book_count');
            $table->foreignId('best_book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->integer('best_price_american')->nullable();
            $table->timestampTz('based_on_pulled_at');
            $table->timestampTz('computed_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'market', 'computed_at'], 'consensus_event_market_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consensus_prices');
        Schema::dropIfExists('event_line_markers');
        Schema::dropIfExists('odds_snapshots');
    }
};
