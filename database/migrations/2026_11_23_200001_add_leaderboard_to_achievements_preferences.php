<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Aparecer en la clasificación» (#10): on unless the person opts out. A person without a row
 * appears, as the default says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements_preferences', function (Blueprint $table): void {
            $table->boolean('leaderboard')->default(true)->after('featured');
        });
    }

    public function down(): void
    {
        Schema::table('achievements_preferences', function (Blueprint $table): void {
            $table->dropColumn('leaderboard');
        });
    }
};
