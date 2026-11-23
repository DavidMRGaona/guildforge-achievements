<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leaderboard's seasons start at the first grant still kept (#10): min(granted_at) read from
 * this index instead of every grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements_grants', function (Blueprint $table): void {
            $table->index('granted_at', 'achievements_grants_granted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('achievements_grants', function (Blueprint $table): void {
            $table->dropIndex('achievements_grants_granted_at_index');
        });
    }
};
