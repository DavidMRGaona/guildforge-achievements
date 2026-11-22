<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger: what each activity source reports, subject by subject (core#774).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // `<module>.<thing>` and the subject's stable id, as the core's contract allows them
            $table->string('source_type', 100);
            $table->string('subject_id', 191);
            // No foreign key: like other modules, accounts are anonymized rather than deleted,
            // and UserAnonymized deletes their facts
            $table->uuid('user_id');
            $table->string('role', 32);
            $table->string('kind', 32);
            // An instant in the storage zone
            $table->timestamp('occurred_at');
            // {"<key>": {"id": "<value id>", "label": "<label>"}}
            $table->json('attributes');
            $table->unsignedInteger('placement')->nullable();
            $table->timestamps();

            // Also the index of every query per subject (its leading columns)
            $table->unique(['source_type', 'subject_id', 'user_id', 'role'], 'achievements_facts_subject_person_role_unique');
            // Rules count a person's facts of a kind, in a window
            $table->index(['user_id', 'kind', 'occurred_at'], 'achievements_facts_user_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_facts');
    }
};
