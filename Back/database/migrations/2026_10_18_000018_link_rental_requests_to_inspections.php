<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_reports', function (Blueprint $table) {
            $table->foreignId('rental_request_id')
                ->nullable()
                ->after('requested_by_student_id')
                ->constrained('rental_requests')
                ->cascadeOnDelete();
            $table->unique('rental_request_id', 'inspection_rental_request_uq');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_reports', function (Blueprint $table) {
            $table->dropUnique('inspection_rental_request_uq');
            $table->dropConstrainedForeignId('rental_request_id');
        });
    }
};
