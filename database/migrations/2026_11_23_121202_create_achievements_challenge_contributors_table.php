<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who added to each challenge and how much, as of the last count. Only ever shown as totals, as
 * a person's own contribution, or as the visible profiles of whoever completed it: never which
 * subjects anybody took part in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_challenge_contributors', function (Blueprint $table): void {
            $table->foreignUuid('challenge_id')->constrained('achievements_challenges')->cascadeOnDelete();
            // No foreign key to the account, like the ledger: UserAnonymized deletes its rows
            $table->uuid('user_id');
            // Subjects of the challenge the person took part in
            $table->unsignedInteger('contribution');
            // Their latest fact that counts, in the storage zone
            $table->timestamp('last_contribution_at');
            $table->timestamps();

            $table->primary(['challenge_id', 'user_id']);
            $table->index('user_id', 'achievements_challenge_contributors_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_challenge_contributors');
    }
};
