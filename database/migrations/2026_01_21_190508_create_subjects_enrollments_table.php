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
        Schema::create('subjects_enrollments', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('course_enrollment_id')
                  ->constrained('courses_enrollments')
                  ->cascadeOnDelete();

            $table->foreignId('subject_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('student_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Progress
            $table->decimal('progress', 5, 2)->default(0.00)
                  ->comment('Measured in percentage 0-100');

            // Timestamps and soft deletes
            $table->softDeletes();
            $table->timestamps();

            // Prevent duplicate entries: same student in same subject under same course enrollment
            $table->unique(['course_enrollment_id', 'subject_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects_enrollments');
    }
};
