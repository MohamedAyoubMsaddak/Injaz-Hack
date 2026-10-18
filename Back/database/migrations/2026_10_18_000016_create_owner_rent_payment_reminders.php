<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_rent_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('amount_tnd', 10, 3);
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['rental_request_id', 'due_date'], 'owner_rent_reminder_due_uq');
            $table->index(['owner_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_rent_payment_reminders');
    }
};
