<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue: the achievements a site offers, each with its look and its rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_catalogue', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // The badge's label and description (the core's limits for profile badges)
            $table->string('name', 60);
            $table->string('description', 200)->default('');
            // One of the core's closed lists (ProfileBadgeIcons::NAMES, ProfileBadgeColour)
            $table->string('icon', 32);
            $table->string('colour', 16);
            // Optional image, uploaded to Cloudinary under achievements/badges/
            $table->string('image_public_id')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_active')->default(true);
            // Versioned JSON read by AchievementRule; anything unreadable is never evaluated
            $table->json('rule');
            // Set on the achievements of the starter catalogue, so creating it again adds only what is missing
            $table->string('starter_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['is_active'], 'achievements_catalogue_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_catalogue');
    }
};
