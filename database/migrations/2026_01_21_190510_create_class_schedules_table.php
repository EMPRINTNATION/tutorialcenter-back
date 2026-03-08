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
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();

            // Foreign key to classes table
            $table->foreignId('class_id')
                  ->constrained('classes')
                  ->cascadeOnDelete();

            // Day of week
            $table->enum('day_of_week', [
                'sunday',
                'monday',
                'tuesday',
                'wednesday',
                'thursday',
                'friday',
                'saturday'
            ])->index();

            // Start and end time
            $table->time('start_time');
            $table->time('end_time');

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();

            // Optional: Prevent duplicate schedule for same class/day/time
            $table->unique(['class_id', 'day_of_week', 'start_time', 'end_time'], 'class_schedule_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};

