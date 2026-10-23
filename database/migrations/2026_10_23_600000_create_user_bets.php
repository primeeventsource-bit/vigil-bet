<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A customer's own bets: current state, derived from user_bet_events.
 *
 * Vigil never places a bet and never holds money. These rows record that
 * a customer told us they backed a selection at a price, so their record,
 * their ROI and their closing line value have something to measure. The
 * bet itself happens at their own book and nothing here could do
 * otherwise.
 *
 * Money in integer cents. A bankroll kept in floats accumulates error in
 * the direction nobody checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Where it came from. A bet logged from the board carries the
             * entry, which is what makes closing line value measurable -
             * we know what the model said at the time. A manual entry
             * does not, and its CLV stays null rather than being
             * estimated.
             */
            $table->foreignId('board_entry_id')->nullable()
                ->constrained('vl_board_entries')->nullOnDelete();
            $table->unsignedBigInteger('edge_lab_event_id')->nullable()->index();

            $table->string('sport');
            $table->string('market');
            $table->string('side')->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->string('selection');

            $table->integer('price_american');
            $table->string('book')->nullable();
            $table->string('book_title')->nullable();

            $table->unsignedInteger('stake_cents');

            /*
             * pending until the game finishes. `void` is a bet that never
             * ran; `push` is a tie against the line. They settle
             * differently and conflating them would quietly flatter the
             * record - a void returns the stake and a push returns the
             * stake, but only a push means the model was wrong about
             * nothing.
             */
            $table->string('status')->default('pending')->index();
            $table->timestamp('settled_at')->nullable();

            // What came back, stake included. Null while pending.
            $table->integer('return_cents')->nullable();

            /*
             * Closing line value: the price at kickoff against the price
             * taken. The single most honest measure of whether a bet was
             * good, independent of whether it won, which is why §8 puts
             * it behind Pro rather than hiding it.
             */
            $table->integer('closing_price_american')->nullable();
            $table->decimal('clv', 8, 5)->nullable();

            $table->timestamp('placed_at')->index();
            $table->string('source')->default('manual');

            // The last event folded in, so a stale projection is visible
            // rather than inferred.
            $table->unsignedBigInteger('last_event_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'placed_at']);
            $table->index(['user_id', 'status']);
        });
    }
};
