<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the list of a completed challenge may name (#12), as found on the first day of the month:
 * whoever added to it and held its reward then. The list only grows once a month, so a name that
 * turns up never tells when, or after which table, that person got the reward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_challenge_listed', function (Blueprint $table): void {
            $table->foreignUuid('challenge_id')->constrained('achievements_challenges')->cascadeOnDelete();
            // No foreign key to the account, like the ledger: UserAnonymized deletes its rows
            $table->uuid('user_id');
            // Instant in the storage zone
            $table->timestamp('listed_at');

            $table->primary(['challenge_id', 'user_id']);
            $table->index('user_id', 'achievements_challenge_listed_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_challenge_listed');
    }
};
