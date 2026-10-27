<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Picks a customer has saved to come back to
|--------------------------------------------------------------------------
|
| "My Picks" in the spec, and it is not the bet log. My Bets is a record
| of money a customer says they put down; this is a shortlist of
| published opinions they want to keep an eye on, with nothing staked.
| Conflating the two would mean either a watchlist that reports a profit
| nobody made, or a bet log full of bets nobody placed.
|
| Deliberately thin. Just the join, the moment, and an optional note -
| everything else about a pick already lives on the opinion it points at,
| and copying any of it here would be a second version of a number that
| can go out of date.
|
| No stake, no price, no result. The result is the opinion's result, and
| a saved pick that disagreed with the record it came from would be worse
| than useless.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_picks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Points at an opinion rather than a board entry. An
             * opinion is the thing that was actually published and
             * graded in public; a board entry is a candidate the engine
             * considered, most of which it declined.
             */
            $table->foreignId('opinion_id')->constrained()->cascadeOnDelete();

            // Why they kept it. Theirs, never shown to anybody else.
            $table->string('note', 200)->nullable();

            $table->timestampsTz();

            // One save per pick per person. Saving twice is the same
            // intention expressed twice, not two saves.
            $table->unique(['user_id', 'opinion_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_picks');
    }
};
