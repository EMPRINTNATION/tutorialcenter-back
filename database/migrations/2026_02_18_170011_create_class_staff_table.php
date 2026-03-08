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
        Schema::create('class_staff', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('class_id')
                  ->constrained('classes')
                  ->cascadeOnDelete();

            $table->foreignId('staff_id')
                  ->constrained('staffs')
                  ->cascadeOnDelete();

            // Role in class
            $table->enum('role', ['lead', 'assistant'])
                  ->default('lead');

            // Timestamps
            $table->timestamps();

            // Prevent duplicate assignment
            $table->unique(['class_id', 'staff_id'], 'class_staff_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_staff');
    }
};
