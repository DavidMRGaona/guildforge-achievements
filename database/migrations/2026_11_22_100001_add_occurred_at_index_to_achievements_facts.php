<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rarity counts the people active in the last twelve months over every account: the existing index
 * starts with the account, so this one starts with the instant and carries the account, and the
 * query is answered from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements_facts', function (Blueprint $table): void {
            $table->index(['occurred_at', 'user_id'], 'achievements_facts_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('achievements_facts', function (Blueprint $table): void {
            $table->dropIndex('achievements_facts_occurred_at_index');
        });
    }
};
