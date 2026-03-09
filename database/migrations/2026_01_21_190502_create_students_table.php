<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('firstname')->nullable();
            $table->string('surname')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('tel')->unique()->nullable();
            $table->string('password');

            $table->enum('gender', ['male', 'female', 'others'])->nullable();
            $table->string('profile_picture')->nullable();
            $table->date('date_of_birth')->nullable();

            // Verification
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('tel_verified_at')->nullable();

            // Location Info
            $table->string('location')->nullable()->comment('Country and state');
            $table->string('address')->nullable()->comment('Full address');

            // Department
            $table->string('department')->nullable()->comment('Student department');

            // Soft deletes & timestamps
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
