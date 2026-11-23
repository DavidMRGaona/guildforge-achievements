<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who counted as an association member for a challenge of members, as last asked within its days.
 * Once its days are over this is frozen: late facts still count, but joining or leaving the
 * association afterwards changes nothing (a person first seen late is asked once and kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_challenge_audience', function (Blueprint $table): void {
            $table->foreignUuid('challenge_id')->constrained('achievements_challenges')->cascadeOnDelete();
            // No foreign key to the account, like the ledger: UserAnonymized deletes its rows
            $table->uuid('user_id');
            $table->boolean('is_member');
            $table->timestamps();

            $table->primary(['challenge_id', 'user_id']);
            $table->index('user_id', 'achievements_challenge_audience_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_challenge_audience');
    }
};
