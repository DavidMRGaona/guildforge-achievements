<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the nightly job saw, by name: what everybody but each owner is shown until the next night,
 * so two people who play together never give themselves away by their totals rising at once. The
 * profile's photo (`daily`) holds its instant and the rarity of every achievement then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_snapshots', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            // An instant in the storage zone: grants up to it are in the photo
            $table->timestamp('taken_at');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_snapshots');
    }
};
