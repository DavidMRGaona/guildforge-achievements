<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collective challenges (#12): a goal between everybody («40 partidas en diciembre») with a reward
 * achievement for whoever added to it. The total is kept here, worked out from the ledger whenever
 * it changes, so pages never add the facts up themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('description', 300)->default('');
            // What it counts and the target (ChallengeRule, versioned JSON)
            $table->json('rule');
            // everybody or members (ChallengeAudience)
            $table->string('audience', 16)->default('everybody');
            // Calendar days of the display zone, both included
            $table->date('starts_on');
            $table->date('ends_on');
            // A reward somebody may hold is never deleted under a challenge
            $table->foreignUuid('reward_achievement_id')->constrained('achievements_catalogue')->restrictOnDelete();
            // The last total worked out, and how many people added to it
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('contributors')->default(0);
            // Instants in the storage zone
            $table->timestamp('progress_at')->nullable();
            // When the total first reached the target (the fact that did it); never cleared
            $table->timestamp('completed_at')->nullable();
            // Closed by hand: nothing more counts, nobody else gets the reward
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['closed_at', 'ends_on'], 'achievements_challenges_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_challenges');
    }
};
