<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of recognition people can give each other (#11), managed in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_recognition_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 40);
            // One of the core's closed lists (ProfileBadgeIcons::NAMES, ProfileBadgeColour)
            $table->string('icon', 32);
            $table->string('colour', 16);
            $table->boolean('is_active')->default(true);
            // Set on the starter types, so installing them again adds only what is missing
            $table->string('starter_key', 64)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_recognition_types');
    }
};
