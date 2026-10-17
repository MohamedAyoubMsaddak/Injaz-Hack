<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('governorate');
            $table->string('city');
            $table->string('address')->nullable();
            $table->decimal('monthly_rent_tnd', 10, 3);
            $table->unsignedTinyInteger('bedrooms');
            $table->string('furnishing_status', 24)->default('unknown');
            $table->boolean('available')->default(false);
            $table->string('verification_status', 24)->default('pending');
            $table->timestamps();

            $table->index(['governorate', 'city']);
            $table->index(['available', 'verification_status']);
        });

        Schema::create('house_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['house_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_photos');
        Schema::dropIfExists('houses');
    }
};
