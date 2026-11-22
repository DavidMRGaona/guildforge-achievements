<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each person chose about their own achievements: whether others see them and which ones are
 * featured. A person without a row has chosen nothing (shown, the rarest featured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_preferences', function (Blueprint $table): void {
            // No foreign key, like the rest of the module: UserAnonymized deletes the row
            $table->uuid('user_id')->primary();
            // Only the owner sees their achievements: section, count and featured badges
            $table->boolean('hidden')->default(false);
            // Ids of up to three held achievements, in the owner's order; null when never chosen
            $table->json('featured')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_preferences');
    }
};
