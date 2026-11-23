<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Mentor» (achievements#24) looks up somebody's first fact of a kind in one role (their first game
 * as a player) once per newcomer: with the role in the index, that minimum is read from the index
 * alone, however many facts of other roles the account has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements_facts', function (Blueprint $table): void {
            $table->index(['user_id', 'kind', 'role', 'occurred_at'], 'achievements_facts_first_fact_index');
        });
    }

    public function down(): void
    {
        Schema::table('achievements_facts', function (Blueprint $table): void {
            $table->dropIndex('achievements_facts_first_fact_index');
        });
    }
};
