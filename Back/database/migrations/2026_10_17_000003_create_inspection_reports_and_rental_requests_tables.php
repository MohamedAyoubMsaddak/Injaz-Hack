<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspection_date');
            $table->json('condition_checklist')->nullable();
            $table->text('defects_found')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();

            $table->index(['house_id', 'status']);
            $table->index(['worker_id', 'status']);
        });

        Schema::create('rental_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->string('status', 24)->default('pending');
            $table->boolean('secured_house')->default(false);
            $table->timestamp('secured_at')->nullable();
            $table->decimal('service_fee_tnd', 10, 3)->default(
                config('student_housing.student_service_fee_tnd', '20.000')
            );
            $table->string('service_fee_payment_status', 24)->default('not_due');
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['house_id', 'status']);
            $table->index(['secured_house', 'service_fee_payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_requests');
        Schema::dropIfExists('inspection_reports');
    }
};
