<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grants waiting to be told (achievements#6), once per kind of notice: to the holder a little later
 * (all of one evaluation together), and to others in the next daily release of announcements. A
 * revoked grant (its row deleted) leaves with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_pending_notices', function (Blueprint $table): void {
            $table->foreignUuid('grant_id')->constrained('achievements_grants')->cascadeOnDelete();
            // «earned» (to the holder) or «announcement» (to others)
            $table->string('kind', 16);
            // Who it is about, to take all of a person's at once
            $table->uuid('user_id');
            // The grant's own instant (storage zone), to tell them in the order they were got
            $table->timestamp('granted_at');

            $table->primary(['grant_id', 'kind']);
            $table->index(['kind', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_pending_notices');
    }
};
