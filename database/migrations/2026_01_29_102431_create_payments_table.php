<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // WHO PAID
            $table->foreignId('student_id')
                  ->constrained('students')
                  ->cascadeOnDelete();

            // WHAT WAS PAID FOR
            $table->foreignId('course_enrollment_id')
                  ->constrained('courses_enrollments')
                  ->cascadeOnDelete();

            // PAYMENT DETAILS
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('NGN');

            // PAYMENT GATEWAY INFO
            $table->enum('payment_method', [
                'card',
                'bank_transfer',
                'ussd',
                'wallet',
                'manual'
            ]);
            $table->string('gateway')->nullable(); // paystack, flutterwave, stripe
            $table->string('gateway_reference')->nullable()->unique();

            // PAYMENT STATUS
            $table->enum('status', [
                'pending',
                'successful',
                'failed',
                'cancelled',
                'refunded'
            ])->default('pending');

            // BILLING CONTEXT
            $table->enum('billing_cycle', [
                'monthly',
                'quarterly',
                'semi_annual',
                'annual'
            ]);

            // METADATA (gateway payloads, receipts, etc.)
            $table->json('meta')->nullable();

            // PAYMENT TIMESTAMP
            $table->timestamp('paid_at')->nullable();

            // Soft deletes & timestamps
            $table->softDeletes();
            $table->timestamps();

            // INDEXES for faster queries
            $table->index(['student_id', 'course_enrollment_id', 'status', 'gateway_reference'], 'payments_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
