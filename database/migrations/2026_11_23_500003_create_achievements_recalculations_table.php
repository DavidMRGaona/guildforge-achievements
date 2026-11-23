<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which activity sources were recalculated whole at least once (`achievements:recalculate`, not a
 * dry run). Mentors are decided once and for good (#37), so the nightly batch waits until every
 * source of game sessions was: before that, the ledger may lack the games played before installing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_recalculations', function (Blueprint $table): void {
            $table->string('source_type', 191)->primary();
            // The last complete recalculation, in the storage zone
            $table->timestamp('completed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_recalculations');
    }
};
