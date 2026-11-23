<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recognitions people give each other after a game (#11): one per giver, subject and receiver.
 * Account ids carry no foreign key, like the rest of the module: anonymizing either person deletes
 * the rows (ForgetAnonymizedAccountRecognitions), and so does either person leaving the subject's
 * facts (the ledger, under the subject's lock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements_recognitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Restrict: a type in use is switched off, never deleted
            $table->foreignUuid('type_id')->constrained('achievements_recognition_types')->restrictOnDelete();
            $table->uuid('giver_id');
            $table->uuid('receiver_id');
            $table->string('source_type', 100);
            $table->string('subject_id', 191);
            $table->string('kind', 32);
            // Storage zone; others count only what was given before the 1st of the display month
            $table->timestamp('given_at');
            $table->timestamps();

            $table->unique(['giver_id', 'source_type', 'subject_id', 'receiver_id'], 'achievements_recognitions_once_unique');
            $table->index(['receiver_id', 'given_at'], 'achievements_recognitions_receiver_index');
            $table->index(['source_type', 'subject_id'], 'achievements_recognitions_subject_index');
        });

        // Who was already asked «¿Quieres reconocer a alguien de…?» for a subject: once each
        Schema::create('achievements_recognition_prompts', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->string('source_type', 100);
            $table->string('subject_id', 191);
            $table->timestamp('prompted_at');

            $table->primary(['user_id', 'source_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements_recognition_prompts');
        Schema::dropIfExists('achievements_recognitions');
    }
};
