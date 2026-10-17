<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 24)->default('open');
            $table->decimal('repair_cost_tnd', 10, 3)->nullable();
            $table->string('cost_responsibility', 24)->nullable();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['house_id', 'status']);
            $table->index(['assigned_worker_id', 'status']);
            $table->index(['priority', 'status']);
        });

        Schema::create('maintenance_request_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->restrictOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->index('maintenance_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_request_photos');
        Schema::dropIfExists('maintenance_requests');
    }
};
