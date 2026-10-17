<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('house_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('monthly_price_tnd', 10, 3)->default(
                config('student_housing.owner_subscription_monthly_tnd', '20.000')
            );
            $table->string('status', 24)->default('pending');
            $table->date('starts_on');
            $table->date('next_renewal_on')->nullable();
            $table->string('payment_status', 24)->default('unpaid');
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['status', 'next_renewal_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_subscriptions');
    }
};
