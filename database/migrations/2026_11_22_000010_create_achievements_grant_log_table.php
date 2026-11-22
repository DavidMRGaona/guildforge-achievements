<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to each grant: granted (by a rule, by hand, in bulk) or revoked by hand, written in
 * the same transaction as the grant itself. Read-only in the panel; rows are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_grant_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Null once the achievement is deleted: its name below keeps the history readable
            $table->foreignUuid('achievement_id')->nullable()->constrained('achievements_catalogue')->nullOnDelete();
            $table->string('achievement_name', 60);
            // Null once the account is anonymized (its reason goes too); no foreign key, like the grants
            $table->uuid('user_id')->nullable();
            // Who did it; null for what a rule granted
            $table->uuid('actor_id')->nullable();
            // granted or revoked (GrantLogAction)
            $table->string('action', 16);
            // How it happened: rule, manual, bulk or challenge (GrantSource); a revoke is always manual
            $table->string('source', 16);
            // The season of a repeatable achievement, '' without one, as in achievements_grants
            $table->string('season_key', 32)->default('');
            $table->string('reason', 500)->nullable();
            // An instant in the storage zone
            $table->timestamp('created_at');

            $table->index(['achievement_id', 'created_at'], 'achievements_grant_log_achievement_index');
            $table->index(['user_id', 'achievement_id', 'season_key'], 'achievements_grant_log_person_index');
            $table->index(['actor_id'], 'achievements_grant_log_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_grant_log');
    }
};
