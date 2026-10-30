<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of state changes. Rows are inserted, never updated,
        // so there is deliberately no updated_at column.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Empty when the system itself (scheduler or queue worker) made the change.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 60);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->json('details')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
