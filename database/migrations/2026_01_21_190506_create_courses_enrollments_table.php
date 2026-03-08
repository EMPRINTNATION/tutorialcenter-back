<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('courses_enrollments', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('student_id')
                  ->constrained('students')
                  ->cascadeOnDelete();

            $table->foreignId('course_id')
                  ->constrained('courses')
                  ->cascadeOnDelete();

            // Enrollment period
            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable();

            // Billing
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'semi_annual', 'annual']);
            $table->decimal('cost', 12, 2);

            // Status
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled'])
                  ->default('pending');

            // Timestamps and soft deletes
            $table->softDeletes();
            $table->timestamps();

            // Prevent duplicate enrollment for same student-course
            $table->unique(['student_id', 'course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses_enrollments');
    }
};

