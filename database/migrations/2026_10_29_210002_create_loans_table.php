<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('users')->restrictOnDelete();

            // ID from the simulated loan source. Unique, so syncing the same loan twice updates it.
            $table->string('external_loan_ref', 50)->unique();

            // Fictional catalogue data only.
            $table->string('item_title');
            $table->string('item_barcode', 50);

            $table->date('borrowed_at');
            $table->date('due_date')->index();
            $table->date('returned_at')->nullable();
            $table->unsignedSmallInteger('renewal_count')->default(0);

            // active | overdue | disputed | returned | closed
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
