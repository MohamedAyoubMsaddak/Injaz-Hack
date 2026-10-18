<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_rental_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('house_title');
            $table->string('student_name');
            $table->decimal('amount_tnd', 10, 3);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['rental_request_id', 'event_type'], 'owner_rental_notification_event_uq');
            $table->index(['owner_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_rental_notifications');
    }
};
