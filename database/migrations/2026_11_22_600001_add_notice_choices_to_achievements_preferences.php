<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The choices of the account settings' «Logros» tab (achievements#6): announcing one's rare
 * achievements to whoever may see the profile (off until the person turns it on) and appearing in
 * the leaderboard (on until the person leaves it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements_preferences', function (Blueprint $table): void {
            $table->boolean('announce_rare')->default(false);
            $table->boolean('in_leaderboard')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('achievements_preferences', function (Blueprint $table): void {
            $table->dropColumn(['announce_rare', 'in_leaderboard']);
        });
    }
};
