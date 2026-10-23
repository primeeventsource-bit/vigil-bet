<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEND ONLY. One generated explanation, and the facts it was allowed to
 * use.
 *
 * The fact sheet is stored beside the text on purpose. It is the only way
 * to answer the question that matters about an AI feature in a product
 * like this - "where did that number come from" - months later, against
 * the Read a customer actually saw. Without it the validation is a claim
 * about a run nobody can reproduce.
 *
 * `source` records whether a Read came from the model or from the
 * templated fallback, because a customer is owed the difference and so is
 * anybody debugging a bad week of explanations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vigil_reads', function (Blueprint $table) {
            $table->bigIncrements('id');

            // What this explains. A board entry today; a bet or a parlay
            // later, which is why it is a polymorphic pair rather than a
            // foreign key to one table.
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');

            $table->unsignedSmallInteger('prompt_version');
            $table->text('body');

            // model | fallback. Never silently interchangeable.
            $table->string('source', 16);
            $table->string('model', 48)->nullable();

            // Exactly what the model was allowed to see.
            $table->json('fact_sheet');

            // Which validation pass produced this: 1 first try, 2 after one
            // regeneration, null for the templated fallback.
            $table->unsignedTinyInteger('attempt')->nullable();
            $table->string('rejected_reason', 64)->nullable();

            $table->timestamp('created_at');

            $table->unique(['subject_type', 'subject_id', 'prompt_version'], 'vigil_reads_subject_version');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vigil_reads');
    }
};
