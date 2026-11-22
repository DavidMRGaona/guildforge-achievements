<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The starter achievements created once on this site: one that was deleted and is created again
 * starts switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_starter_keys', function (Blueprint $table): void {
            $table->string('starter_key', 64)->primary();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_starter_keys');
    }
};
