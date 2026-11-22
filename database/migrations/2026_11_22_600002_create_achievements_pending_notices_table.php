<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grants waiting to be told (achievements#6): every grant of one evaluation lands here, and one
 * delivery a little later tells them all at once. A revoked grant (its row deleted) leaves with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_pending_notices', function (Blueprint $table): void {
            $table->foreignUuid('grant_id')->primary()->constrained('achievements_grants')->cascadeOnDelete();
            // Who it is told to, to take all of a person's at once
            $table->uuid('user_id')->index();
            // The grant's own instant (storage zone), to tell them in the order they were got
            $table->timestamp('granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_pending_notices');
    }
};
