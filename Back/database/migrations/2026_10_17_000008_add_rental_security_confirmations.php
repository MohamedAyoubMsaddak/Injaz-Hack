<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_requests', function (Blueprint $table) {
            $table->timestamp('student_confirmed_secured_at')->nullable();
            $table->timestamp('owner_confirmed_secured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_requests', function (Blueprint $table) {
            $table->dropColumn([
                'student_confirmed_secured_at',
                'owner_confirmed_secured_at',
            ]);
        });
    }
};
