<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who holds which achievement. Grants are never revoked by a recalculation: only added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // No foreign key to the account, like the ledger: UserAnonymized deletes its grants
            $table->uuid('user_id');
            $table->foreignUuid('achievement_id')->constrained('achievements_catalogue')->cascadeOnDelete();
            // The season of a repeatable achievement; null for the rest (every grant of this release)
            $table->string('season', 32)->nullable();
            // The season again, '' without one: the unique key needs a value, since NULLs never clash
            $table->string('season_key', 32)->default('');
            // An instant in the storage zone
            $table->timestamp('granted_at');
            // rule, manual, bulk or challenge (GrantSource)
            $table->string('source', 16);
            // Who granted it by hand; null for rules
            $table->uuid('actor_id')->nullable();
            // Granted by a recalculation or a silent bulk grant: no notice is ever sent for it
            $table->boolean('silent')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id', 'season_key'], 'achievements_grants_person_achievement_season_unique');
            $table->index(['achievement_id', 'season_key'], 'achievements_grants_achievement_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_grants');
    }
};
