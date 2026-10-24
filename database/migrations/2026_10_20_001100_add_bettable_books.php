<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which books I can actually place a bet at.
 *
 * Line shopping across twenty books is interesting; it is not actionable if I
 * only hold two accounts. A price I cannot reach is not an edge, so a pick is
 * priced at my books, while the no-vig fair probability still uses the whole
 * market, because more books make a sharper estimate of the true price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('is_bettable')->default(false)->after('is_tracked');
            $table->boolean('is_primary')->default(false)->after('is_bettable');
        });

        Schema::table('value_bets', function (Blueprint $table) {
            // best_* stays the actionable price: the best among my books.
            // These two record where the wider market was, so a pick can say
            // "better elsewhere, but you cannot bet there".
            $table->unsignedBigInteger('market_best_book_id')->nullable()->after('line_shopped');
            $table->integer('market_best_price_american')->nullable()->after('market_best_book_id');
        });
    }

    public function down(): void
    {
        Schema::table('value_bets', function (Blueprint $table) {
            $table->dropColumn(['market_best_book_id', 'market_best_price_american']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['is_bettable', 'is_primary']);
        });
    }
};
