<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly photo of points (#10): what anybody but the owner sees. Points of two people who
 * played together would otherwise rise at once in front of everybody, so others only see them
 * change once a day. Only ids and points: per period (`all` or a season key) and account, the
 * points of each achievement held. Whether one is secret, who may see whom and each person's choices
 * are decided when it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_points_snapshots', function (Blueprint $table): void {
            // `all` or a season key ('2027')
            $table->string('period_key', 8);
            // No foreign key, like the grants: UserAnonymized deletes its rows
            $table->uuid('user_id');
            // {"<achievement id>": points}
            $table->json('points');

            $table->primary(['period_key', 'user_id']);
            $table->index('user_id', 'achievements_points_snapshots_user_index');
        });

        Schema::create('achievements_snapshot_runs', function (Blueprint $table): void {
            $table->string('name', 32)->primary();
            // When the photo was taken (storage zone); without a row there is none yet
            $table->timestamp('taken_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_snapshot_runs');
        Schema::dropIfExists('achievements_points_snapshots');
    }
};
