<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subject the ledger has written, locked while its facts are read and replaced, so two
 * refreshes of one subject (a queued event and a recalculation, two events) run one after the
 * other even when the subject has no facts yet. The rows are kept: deleting one would let two
 * writers race again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_subject_locks', function (Blueprint $table): void {
            $table->string('source_type', 100);
            $table->string('subject_id', 191);

            $table->primary(['source_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_subject_locks');
    }
};
