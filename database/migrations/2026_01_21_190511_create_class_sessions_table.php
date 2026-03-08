<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();

            // Foreign key to classes
            $table->foreignId('class_id')
                  ->constrained('classes')
                  ->cascadeOnDelete();

            // Foreign key to class_schedules
            $table->foreignId('class_schedule_id')
                  ->constrained('class_schedules')
                  ->cascadeOnDelete();

            // Session date
            $table->date('session_date')->index();

            // Start and end time (nullable if not yet set)
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            // Links
            $table->string('class_link')->nullable();
            $table->string('recording_link')->nullable();

            // Status
            $table->enum('status', [
                'scheduled',
                'completed',
                'cancelled',
                'recorded'
            ])->default('scheduled')->index();

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();

            // Optional: prevent duplicate session for same class and schedule on same date
            $table->unique(['class_id', 'class_schedule_id', 'session_date'], 'class_session_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
