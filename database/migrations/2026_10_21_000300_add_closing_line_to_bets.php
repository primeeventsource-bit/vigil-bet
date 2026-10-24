<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the line was when the market closed, alongside what the price was.
 *
 * On a moneyline the price is the whole story, so closing_price_american was
 * enough. On a spread or a total it is not: taking -3 at -110 and watching it
 * close -3.5 at -110 shows no price movement at all while the bet gained
 * real value, and the reverse loses it. Without the closing line there is no
 * way to tell those apart from a bet that genuinely did not move, so closing
 * line value on those markets would be quietly wrong rather than missing.
 *
 * Recording it lets the recorder refuse to state a value it cannot compute -
 * see ClvRecorder - instead of reporting the price gap as though it were the
 * whole number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->decimal('closing_line', 6, 2)->nullable()->after('closing_price_american');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn('closing_line');
        });
    }
};
