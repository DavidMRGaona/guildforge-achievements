<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who each newcomer's mentor is (achievements#24, #37), decided once by the nightly batch and never
 * again: grants are permanent, so a first game that changes later (a table completed weeks late, or
 * one that stops counting) must not make a second mentor. One row per newcomer, with no mentor when
 * there was none. Never shown to anybody; only counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_mentor_decisions', function (Blueprint $table): void {
            // No foreign keys to the accounts, like the ledger: UserAnonymized erases the newcomer's
            // row and empties the mentor's side
            $table->uuid('newcomer_id')->primary();
            $table->uuid('mentor_id')->nullable();
            // When the first game could be decided on (the game plus the grace), in the storage zone
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('mentor_id', 'achievements_mentor_decisions_mentor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_mentor_decisions');
    }
};
