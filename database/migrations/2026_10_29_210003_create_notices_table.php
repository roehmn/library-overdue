<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();

            // reminder_3d | reminder_due | overdue_1d | escalation_librarian_7d | escalation_supervisor_14d
            $table->string('type', 40);

            // The due date this notice was created for. A renewal changes the due date,
            // which allows a fresh set of notices for the new cycle.
            $table->date('due_date');

            // pending | sending | retry_scheduled | sent | manual_review | resolved | cancelled
            $table->string('status', 20)->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Manual handling of permanent failures.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            // Idempotency: the database refuses a duplicate notice for the same loan, type and due-date cycle.
            $table->unique(['loan_id', 'type', 'due_date'], 'notices_loan_type_due_unique');

            // Speeds up the recovery check for notices stuck in "sending" and the retry queries.
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
